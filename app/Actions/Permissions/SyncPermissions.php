<?php

namespace App\Actions\Permissions;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the permissions/roles tables aligned with the App\Enums\Permission
 * and App\Enums\Role enums. Safe to run on every deploy.
 */
class SyncPermissions
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @return array{created: list<string>, deleted: list<string>}
     */
    public function handle(bool $prune = false, string $guard = 'web'): array
    {
        $this->registrar->forgetCachedPermissions();

        $existing = Permission::query()->where('guard_name', $guard)->pluck('name')->all();
        $created = array_values(array_diff(PermissionEnum::values(), $existing));

        foreach ($created as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);
        }

        $deleted = [];

        if ($prune) {
            $deleted = array_values(array_diff($existing, PermissionEnum::values()));
            Permission::query()->where('guard_name', $guard)->whereIn('name', $deleted)->delete();
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::firstOrCreate(['name' => $roleEnum->value, 'guard_name' => $guard]);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(array_map(fn (PermissionEnum $permission) => $permission->value, $roleEnum->defaultPermissions()));
            }
        }

        $this->registrar->forgetCachedPermissions();

        return ['created' => $created, 'deleted' => $deleted];
    }
}
