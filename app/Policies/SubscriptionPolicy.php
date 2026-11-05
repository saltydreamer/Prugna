<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Subscription;
use App\Models\User;

/**
 * Service contracts: consulted with "subscriptions.view", changed with "subscriptions.manage".
 */
class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SubscriptionsView->value) || $user->can(Permission::SubscriptionsManage->value);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SubscriptionsManage->value);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->can(Permission::SubscriptionsManage->value);
    }

    /**
     * Contracts with fees already invoiced stay (they can be suspended or given an end date).
     */
    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->can(Permission::SubscriptionsManage->value) && ! $subscription->hasBilledPeriods();
    }

    /**
     * Draft invoices of the fees due: needs the drafts permission too.
     */
    public function invoice(User $user): bool
    {
        return $this->viewAny($user) && $user->can(Permission::InvoicesManage->value);
    }
}
