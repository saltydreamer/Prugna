<?php

namespace Database\Seeders;

use App\Actions\Permissions\SyncPermissions;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Create the roles and permissions defined in App\Enums.
     */
    public function run(SyncPermissions $syncPermissions): void
    {
        $syncPermissions->handle();
    }
}
