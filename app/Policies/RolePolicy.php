<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesView->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RolesCreate->value);
    }

    /**
     * The super-admin role implicitly owns every permission: it can't be edited.
     */
    public function update(User $user, Role $role): bool
    {
        if ($role->name === RoleEnum::SuperAdmin->value) {
            return false;
        }

        return $user->can(Permission::RolesUpdate->value);
    }

    /**
     * System roles (defined in App\Enums\Role) can't be deleted.
     */
    public function delete(User $user, Role $role): bool
    {
        if (RoleEnum::isSystem($role->name)) {
            return false;
        }

        return $user->can(Permission::RolesDelete->value);
    }
}
