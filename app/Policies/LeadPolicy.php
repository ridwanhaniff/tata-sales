<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SALES], true);
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->role === User::ROLE_SALES) {
            return $lead->assigned_to === $user->id;
        }

        return in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true);
    }

    public function update(User $user, Lead $lead): bool
    {
        if ($user->role === User::ROLE_SALES) {
            return $lead->assigned_to === $user->id;
        }

        return in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true);
    }

    public function assign(User $user, Lead $lead): bool
    {
        return in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true);
    }

    public function addNote(User $user, Lead $lead): bool
    {
        if ($user->role === User::ROLE_SALES) {
            return $lead->assigned_to === $user->id;
        }

        return in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true);
    }
}
