<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens') && ! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('media')) {
            return;
        }

        if (! Schema::hasColumn('media', 'generated_conversions')) {
            Schema::table('media', function (Blueprint $table) {
                $table->json('generated_conversions')->nullable();
            });
        }

        // Migrate legacy rows directly so the new media model can read them after the schema change.
        DB::table('media')->orderBy('id')->chunkById(100, function ($media) {
            foreach ($media as $item) {
                $updates = [];

                if (empty($item->uuid)) {
                    $updates['uuid'] = (string) Str::uuid();
                }

                if (empty($item->conversions_disk)) {
                    $updates['conversions_disk'] = $item->disk;
                }

                if (empty($item->generated_conversions)) {
                    $customProperties = json_decode($item->custom_properties ?: '{}', true);
                    $generatedConversions = $customProperties['generated_conversions'] ?? null;

                    if ($generatedConversions !== null) {
                        $updates['generated_conversions'] = json_encode($generatedConversions);
                    }
                }

                if ($updates) {
                    DB::table('media')->where('id', $item->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // The columns are additive compatibility fields; keep existing media and tokens readable on rollback.
    }
};
