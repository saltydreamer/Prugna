<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

/**
 * Orders are shared by the whole team: "orders.view" to consult, "orders.manage" to change them.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::OrdersView->value);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can(Permission::OrdersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::OrdersManage->value);
    }

    /**
     * Status, delivery date and notes. Lines are editable only while nothing is invoiced (Order::isEditable()).
     */
    public function update(User $user, Order $order): bool
    {
        return $user->can(Permission::OrdersManage->value);
    }

    /**
     * Orders with invoices are part of the history.
     */
    public function delete(User $user, Order $order): bool
    {
        return $user->can(Permission::OrdersManage->value) && ! $order->invoices()->exists();
    }
}
