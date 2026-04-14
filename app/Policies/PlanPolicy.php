<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) ($user->is_admin ?? false);
    }

    public function create(User $user): bool
    {
        return (bool) ($user->is_admin ?? false);
    }

    public function update(User $user, Plan $plan): bool
    {
        return (bool) ($user->is_admin ?? false);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return (bool) ($user->is_admin ?? false);
    }
}
