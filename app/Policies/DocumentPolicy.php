<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/** Role rights and document state rules are checked together; no role may edit a document that left draft. */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Document $document): bool
    {
        return $document->isEditable();
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->isEditable() && $user->role->isManager();
    }

    /** Sending and marking as paid. */
    public function changeStatus(User $user, Document $document): bool
    {
        return true;
    }

    public function cancel(User $user, Document $document): bool
    {
        return $user->role->isManager();
    }
}
