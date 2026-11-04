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

    /**
     * The customer signs a completed intervention on the technician's device.
     */
    public function sign(User $user, Intervention $intervention): bool
    {
        return ! $intervention->isRunning() && ! $intervention->isSigned() && $this->owns($user, $intervention);
    }

    /**
     * Only supervisors can remove a signature (to correct a signed intervention not yet invoiced).
     */
    public function unsign(User $user, Intervention $intervention): bool
    {
        return $intervention->isSigned() && ! $intervention->isInvoiced()
            && $user->can(Permission::InterventionsSupervise->value) && $user->can(Permission::InterventionsManage->value);
    }

    /**
     * Send the report by email.
     */
    public function send(User $user, Intervention $intervention): bool
    {
        return ! $intervention->isRunning() && $this->owns($user, $intervention);
    }

    private function owns(User $user, Intervention $intervention): bool
    {
        return $user->can(Permission::InterventionsManage->value)
            && ($intervention->technician_id === $user->id || $user->can(Permission::InterventionsSupervise->value));
    }
}
