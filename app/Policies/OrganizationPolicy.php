<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;

/**
 * Organizations and their locations and contacts. Archived organizations
 * are read-only until restored.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::OrganizationsView->value);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->can(Permission::OrganizationsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::OrganizationsCreate->value);
    }

    public function update(User $user, Organization $organization): bool
    {
        return ! $organization->trashed() && $user->can(Permission::OrganizationsUpdate->value);
    }

    /**
     * Archive (soft delete).
     */
    public function delete(User $user, Organization $organization): bool
    {
        return ! $organization->trashed() && $user->can(Permission::OrganizationsDelete->value);
    }

    public function restore(User $user, Organization $organization): bool
    {
        return $organization->trashed() && $user->can(Permission::OrganizationsDelete->value);
    }
}
