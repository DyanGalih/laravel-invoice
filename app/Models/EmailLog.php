<?php

namespace Crater\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function mailable()
    {
        return $this->morphTo();
    }

    public function isExpired()
    {
        $mailable = $this->mailable;
        if (! $mailable || $this->revoked_at) {
            return true;
        }

        $linkexpiryDays = CompanySetting::getSetting('link_expiry_days', $mailable->company_id);
        $checkExpiryLinks = CompanySetting::getSetting('automatically_expire_public_links', $mailable->company_id);

        $expiryDate = $this->created_at->copy()->addDays((int) ($linkexpiryDays ?: 7));

        if (strtoupper((string) $checkExpiryLinks) === 'YES' && Carbon::now()->greaterThan($expiryDate)) {
            return true;
        }

        return false;
    }

    public function validMailable($type)
    {
        if ($this->mailable_type !== $type || $this->isExpired()) {
            return null;
        }

        $mailable = $this->mailable;

        return $mailable && $mailable->company_id ? $mailable : null;
    }

    public function revoke()
    {
        $this->revoked_at = Carbon::now();
        $this->save();
    }
}
