<?php

namespace Crater\Policies;

use Crater\Models\Note;
use Crater\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Silber\Bouncer\BouncerFacade;

class NotePolicy
{
    use HandlesAuthorization;

    public function manageNotes(User $user, Note $note = null)
    {
        if (($note && ! $user->hasCompany($note->company_id)) || ! $user->hasCompany(request()->header('company'))) {
            return false;
        }

        if (BouncerFacade::can('manage-all-notes', Note::class)) {
            return true;
        }

        return false;
    }

    public function viewNotes(User $user, Note $note = null)
    {
        if (($note && ! $user->hasCompany($note->company_id)) || ! $user->hasCompany(request()->header('company'))) {
            return false;
        }

        if (BouncerFacade::can('view-all-notes', Note::class)) {
            return true;
        }

        return false;
    }
}
