<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WifiSession;

class WifiSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) ($user->is_admin ?? false);
    }

    public function view(User $user, WifiSession $session): bool
    {
        return (bool) ($user->is_admin ?? false);
    }

    public function retryRelease(User $user, WifiSession $session): bool
    {
        return (bool) ($user->is_admin ?? false);
    }

    public function reconcileRelease(User $user, WifiSession $session): bool
    {
        return (bool) ($user->is_admin ?? false);
    }
}
