<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $this->label($role),
                'is_system' => RoleEnum::isSystem($role->name),
                'is_super_admin' => $role->name === RoleEnum::SuperAdmin->value,
                'permissions_count' => $role->name === RoleEnum::SuperAdmin->value
                    ? count(Permission::cases())
                    : $role->permissions_count,
                'users_count' => $role->users_count,
                'can' => [
                    'update' => $request->user()->can('update', $role),
                    'delete' => $request->user()->can('delete', $role),
                ],
            ]);

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles,
            'totalPermissions' => count(Permission::cases()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('admin/roles/Create', [
            'permissionGroups' => Permission::grouped(),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);
            $role->syncPermissions($request->validated('permissions', []));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ruolo creato.')]);

        return to_route('admin.roles.index');
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        return Inertia::render('admin/roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $this->label($role),
                'is_system' => RoleEnum::isSystem($role->name),
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ],
            'permissionGroups' => Permission::grouped(),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($request, $role): void {
            // System role names are referenced in code: they can't be renamed.
            if (! RoleEnum::isSystem($role->name)) {
                $role->update(['name' => $request->validated('name')]);
            }

            $role->syncPermissions($request->validated('permissions', []));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ruolo aggiornato.')]);

        return to_route('admin.roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $role->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ruolo eliminato.')]);

        return to_route('admin.roles.index');
    }

    private function label(Role $role): string
    {
        return RoleEnum::labelFor($role->name);
    }
}
