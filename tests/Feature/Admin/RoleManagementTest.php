<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_roles_and_permissions_are_synced(): void
    {
        $this->assertSame(count(Permission::cases()), \Spatie\Permission\Models\Permission::count());
        $this->assertEqualsCanonicalizing(RoleEnum::values(), Role::pluck('name')->all());
        $this->assertTrue(Role::findByName(RoleEnum::Admin->value)->hasPermissionTo(Permission::UsersDelete->value));
    }

    public function test_super_admins_have_every_permission(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($superAdmin->can($permission->value));
        }
    }

    public function test_roles_index_requires_permission(): void
    {
        $this->actingAs(User::factory()->withRole(RoleEnum::User)->create())
            ->get('/admin/roles')
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/roles/Index')->has('roles', 3));
    }

    public function test_roles_can_be_created_updated_and_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'editor',
            'permissions' => [Permission::UsersView->value],
        ])->assertRedirect('/admin/roles');

        $role = Role::findByName('editor');
        $this->assertTrue($role->hasPermissionTo(Permission::UsersView->value));

        $this->actingAs($admin)->put("/admin/roles/{$role->id}", [
            'name' => 'content-editor',
            'permissions' => [Permission::RolesView->value],
        ])->assertRedirect('/admin/roles');

        $role->refresh();
        $this->assertSame('content-editor', $role->name);
        $this->assertFalse($role->hasPermissionTo(Permission::UsersView->value));

        $this->actingAs($admin)->delete("/admin/roles/{$role->id}")->assertRedirect('/admin/roles');
        $this->assertModelMissing($role);
    }

    public function test_role_names_are_validated(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/roles', ['name' => 'Not Valid!', 'permissions' => ['unknown.permission']])
            ->assertSessionHasErrors(['name', 'permissions.0']);
    }

    public function test_system_roles_can_not_be_deleted_or_renamed(): void
    {
        $admin = User::factory()->admin()->create();
        $userRole = Role::findByName(RoleEnum::User->value);

        $this->actingAs($admin)->delete("/admin/roles/{$userRole->id}")->assertForbidden();

        $this->actingAs($admin)->put("/admin/roles/{$userRole->id}", [
            'name' => 'renamed',
            'permissions' => [Permission::UsersView->value],
        ])->assertRedirect();

        $userRole->refresh();
        $this->assertSame(RoleEnum::User->value, $userRole->name);
        $this->assertTrue($userRole->hasPermissionTo(Permission::UsersView->value));
    }

    public function test_the_super_admin_role_can_not_be_edited(): void
    {
        $role = Role::findByName(RoleEnum::SuperAdmin->value);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get("/admin/roles/{$role->id}/edit")
            ->assertForbidden();
    }
}
