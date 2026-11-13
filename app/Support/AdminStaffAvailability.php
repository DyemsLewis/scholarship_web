<?php

namespace App\Support;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Models\User;

final class AdminStaffAvailability
{
    public static function state(User $user): string
    {
        if ($user->isSuspended()) {
            return 'Access suspended';
        }
        if (! $user->hasVerifiedEmail()) {
            return 'Email verification needed';
        }
        if ($user->must_reset_password) {
            return 'Password reset required';
        }
        $role = AdminRoleCatalog::definitionFor($user);
        if (! $role) {
            return 'Role assignment needed';
        }
        if (array_diff($role['permissions'], $user->effectivePortalPermissions()) !== []) {
            return 'Role permissions missing';
        }

        return 'Ready';
    }
}
