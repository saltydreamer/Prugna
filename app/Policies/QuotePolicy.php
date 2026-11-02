<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;

/**
 * Salespeople manage their own quotes; "quotes.supervise" gives access to everyone's.
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::QuotesView->value);
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->can(Permission::QuotesSupervise->value)
            || ($quote->owner_id === $user->id && $user->can(Permission::QuotesView->value));
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::QuotesCreate->value);
    }

    /**
     * Edit, send, reopen and record the customer's answer. Accepted quotes are final.
     */
    public function update(User $user, Quote $quote): bool
    {
        if ($quote->status === QuoteStatus::Accepted) {
            return false;
        }

        return $user->can(Permission::QuotesSupervise->value)
            || ($quote->owner_id === $user->id && $user->can(Permission::QuotesUpdate->value));
    }

    /**
     * Only drafts can be deleted: sent quotes are part of the history.
     */
    public function delete(User $user, Quote $quote): bool
    {
        if (! $quote->isDraft()) {
            return false;
        }

        return $user->can(Permission::QuotesSupervise->value)
            || ($quote->owner_id === $user->id && $user->can(Permission::QuotesDelete->value));
    }
}
