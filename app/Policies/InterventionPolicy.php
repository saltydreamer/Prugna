<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Intervention;
use App\Models\User;

/**
 * Technicians manage their own interventions; "interventions.supervise" gives access to everyone's.
 * Invoiced interventions are final.
 */
class InterventionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::InterventionsView->value) || $user->can(Permission::InterventionsSupervise->value);
    }

    public function view(User $user, Intervention $intervention): bool
    {
        return $user->can(Permission::InterventionsSupervise->value)
            || ($intervention->technician_id === $user->id && $user->can(Permission::InterventionsView->value));
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::InterventionsManage->value);
    }

    public function update(User $user, Intervention $intervention): bool
    {
        return $intervention->isEditable() && $this->owns($user, $intervention);
    }

    public function delete(User $user, Intervention $intervention): bool
    {
        return $intervention->isEditable() && $this->owns($user, $intervention);
    }

    private function owns(User $user, Intervention $intervention): bool
    {
        return $user->can(Permission::InterventionsManage->value)
            && ($intervention->technician_id === $user->id || $user->can(Permission::InterventionsSupervise->value));
    }
}
