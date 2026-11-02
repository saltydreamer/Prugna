<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Supplier orders are shared by the team: "purchases.view" to consult, "purchases.manage" to change them.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PurchasesView->value);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can(Permission::PurchasesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PurchasesManage->value);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can(Permission::PurchasesManage->value);
    }

    /**
     * Drafts and cancelled orders whose costs were never charged back.
     */
    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can(Permission::PurchasesManage->value)
            && in_array($purchaseOrder->status->value, ['draft', 'cancelled'], true)
            && ! $purchaseOrder->lines()->whereHas('rebilledBy')->exists();
    }
}
