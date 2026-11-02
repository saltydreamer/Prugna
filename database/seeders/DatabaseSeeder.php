<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\CustomerPrice;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        // Demo accounts with a known password must never exist in production:
        // create the first account with `php artisan app:create-super-admin`.
        if (app()->isProduction()) {
            return;
        }

        User::factory()->superAdmin()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
        ]);

        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->withRole(Role::User)->create([
            'name' => 'Utente Demo',
            'email' => 'user@example.com',
        ]);

        if (app()->isLocal()) {
            User::factory(25)->withRole(Role::User)->create();
            $organizations = Organization::factory(20)->withDetails()->create();

            $categories = ProductCategory::factory(5)->create();
            $products = Product::factory(15)->create(fn () => ['category_id' => $categories->random()->id])
                ->merge(Product::factory(6)->service()->create(fn () => ['category_id' => $categories->random()->id]))
                ->merge(Product::factory(3)->recurring()->create());

            foreach ($organizations->random(5) as $organization) {
                CustomerPrice::factory()->for($organization)->for($products->random())->create();
            }
        }
    }
}
