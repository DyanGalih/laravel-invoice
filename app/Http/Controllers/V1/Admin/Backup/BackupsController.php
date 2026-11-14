<?php

// Implementation taken from nova-backup-tool - https://github.com/spatie/nova-backup-tool/

namespace Crater\Http\Controllers\V1\Admin\Backup;

use Crater\Jobs\CreateBackupJob;
use Crater\Models\FileDisk;
use Crater\Rules\Backup\PathToZip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Helpers\Format;

class BackupsController extends ApiController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('manage backups');

        $request->validate([
            'file_disk_id' => ['nullable', 'integer'],
        ]);

        $fileDisk = FileDisk::whereCompany()
            ->when($request->file_disk_id, function ($query) use ($request) {
                return $query->whereKey($request->file_disk_id);
            }, function ($query) {
                return $query->whereSetAsDefault(true);
            })
            ->firstOrFail();
        $fileDisk->setConfig();
        $configuredBackupDisks = [$fileDisk->driver];

        try {
            $backupDestination = BackupDestination::create(config('filesystems.default'), config('backup.backup.name'));

            $backups = Cache::remember("backups-{$request->header('company')}-{$fileDisk->id}", now()->addSeconds(4), function () use ($backupDestination) {
                return $backupDestination
                    ->backups()
                    ->map(function (Backup $backup) {
                        return [
                            'path' => $backup->path(),
                            'created_at' => $backup->date()->format('Y-m-d H:i:s'),
                            'size' => Format::humanReadableSize($backup->size()),
                        ];
                    })
                    ->toArray();
            });

            return response()->json([
                'backups' => $backups,
                'disks' => $configuredBackupDisks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'backups' => [],
                'error' => 'invalid_disk_credentials',
                'error_message' => 'The selected backup disk is unavailable.',
                'disks' => $configuredBackupDisks,
            ]);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $this->authorize('manage backups');

        $validated = $request->validate([
            'file_disk_id' => ['required', 'integer'],
            'option' => ['nullable', 'in:only-db,only-files,full'],
        ]);
        $fileDisk = FileDisk::whereCompany()->findOrFail($validated['file_disk_id']);

        dispatch(new CreateBackupJob(array_merge($validated, [
            'company_id' => $request->header('company'),
            'file_disk_id' => $fileDisk->id,
        ])))->onQueue(config('backup.queue.name'));

        return $this->respondSuccess();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function destroy($disk, Request $request)
    {
        $this->authorize('manage backups');

        $validated = $request->validate([
            'path' => ['required', new PathToZip()],
            'file_disk_id' => ['required', 'integer'],
        ]);

        $fileDisk = FileDisk::whereCompany()->findOrFail($validated['file_disk_id']);
        $fileDisk->setConfig();

        $backupDestination = BackupDestination::create(config('filesystems.default'), config('backup.backup.name'));

        $backup = $backupDestination
            ->backups()
            ->first(function (Backup $backup) use ($validated) {
                return $backup->path() === $validated['path'];
            });

        if (! $backup) {
            return response('Backup not found', 422);
        }

        $backup->delete();

        return $this->respondSuccess();
    }
}
