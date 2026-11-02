<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Expense;
use App\Models\User;

/**
 * Expenses are shared by the team: "expenses.view" to consult, "expenses.manage" to record and pay them.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ExpensesView->value);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can(Permission::ExpensesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ExpensesManage->value);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can(Permission::ExpensesManage->value);
    }

    /**
     * Expenses whose costs were charged back to a customer stay.
     */
    public function delete(User $user, Expense $expense): bool
    {
        return $user->can(Permission::ExpensesManage->value) && $expense->isEditable();
    }
}
