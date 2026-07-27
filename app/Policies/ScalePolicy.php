<?php

namespace App\Policies;

use App\Models\Scale;
use App\Models\User;

class ScalePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Scale $scale): bool
    {
        return ! $scale->isSystem();
    }

    public function delete(User $user, Scale $scale): bool
    {
        return ! $scale->isSystem();
    }
}
