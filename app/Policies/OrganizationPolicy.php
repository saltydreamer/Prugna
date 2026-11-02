<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Customers ("organizations.*" permissions) and suppliers ("suppliers.*") with their locations and contacts.
 * Archived organizations are read-only until restored.
 */
class OrganizationPolicy
{
    /**
     * Without a role: whether the user can see at least one of the two registries.
     */
    public function viewAny(User $user, ?OrganizationRole $role = null): bool
    {
        $roles = $role ? [$role] : OrganizationRole::cases();

        return collect($roles)->contains(fn (OrganizationRole $role) => $user->can($role->permission('view')->value));
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->can($organization->role->permission('view')->value);
    }

    public function create(User $user, ?OrganizationRole $role = null): bool
    {
        return $user->can(($role ?? OrganizationRole::Customer)->permission('create')->value);
    }

    public function update(User $user, Organization $organization): bool
    {
        return ! $organization->trashed() && $user->can($organization->role->permission('update')->value);
    }

    /**
     * Archive (soft delete).
     */
    public function delete(User $user, Organization $organization): bool
    {
        return ! $organization->trashed() && $user->can($organization->role->permission('delete')->value);
    }

    public function restore(User $user, Organization $organization): bool
    {
        return $organization->trashed() && $user->can($organization->role->permission('delete')->value);
    }
}
