<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceContract;
use App\Models\User;

/**
 * Hour contracts: managed with "contracts.manage", consulted also by interventions supervisors.
 */
class ServiceContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ContractsManage->value) || $user->can(Permission::InterventionsSupervise->value);
    }

    public function view(User $user, ServiceContract $contract): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ContractsManage->value);
    }

    public function update(User $user, ServiceContract $contract): bool
    {
        return $user->can(Permission::ContractsManage->value);
    }

    /**
     * Contracts already used or invoiced stay (they can be closed).
     */
    public function delete(User $user, ServiceContract $contract): bool
    {
        return $user->can(Permission::ContractsManage->value) && $contract->invoice_id === null && ! $contract->interventions()->exists();
    }
}
