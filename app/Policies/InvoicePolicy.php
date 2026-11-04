<?php

namespace App\Policies;

use App\Enums\InvoiceType;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\User;

/**
 * Drafts are prepared with "invoices.manage"; issuing (numbering, credit notes) needs "invoices.issue".
 * Issued invoices never change: they are corrected with a credit note.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::InvoicesView->value);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::InvoicesManage->value);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $user->can(Permission::InvoicesManage->value);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $user->can(Permission::InvoicesManage->value);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $user->can(Permission::InvoicesIssue->value);
    }

    /**
     * Send an issued invoice to the SDI (again after a rejection or a failed send).
     */
    public function sendToSdi(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isDraft() && $user->can(Permission::InvoicesIssue->value);
    }

    public function creditNote(User $user, Invoice $invoice): bool
    {
        return ! $invoice->isDraft()
            && $invoice->type !== InvoiceType::CreditNote
            && $user->can(Permission::InvoicesIssue->value)
            && $user->can(Permission::InvoicesManage->value);
    }
}
