<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductsCreate->value);
    }

    /**
     * Also covers the prices agreed with customers.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->can(Permission::ProductsUpdate->value);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can(Permission::ProductsDelete->value);
    }
}
