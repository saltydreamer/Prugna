<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\RolesChangedNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_without_permission_can_not_access_the_user_list(): void
    {
        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admins_can_list_search_and_filter_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Giulia Bianchi']);
        User::factory()->inactive()->create(['name' => 'Luca Verdi']);

        $this->actingAs($admin)
            ->get('/admin/users?search=Giulia')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Giulia Bianchi')
                ->has('users.meta.total'));

        $this->actingAs($admin)
            ->get('/admin/users?status=inactive')
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Luca Verdi'));
    }

    public function test_invalid_sort_columns_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/users?sort_by=password')
            ->assertSessionHasErrors('sort_by');
    }

    public function test_admins_can_create_users_with_a_password(): void
    {
        Notification::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/users', [
                'name' => 'Nuovo Utente',
                'email' => 'nuovo@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'send_reset_link' => false,
                'is_active' => true,
                'roles' => [Role::User->value],
            ])
            ->assertRedirect('/admin/users');

        $user = User::where('email', 'nuovo@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole(Role::User->value));
        $this->assertTrue($user->hasVerifiedEmail());
        Notification::assertSentTo($user, WelcomeNotification::class);
        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_admins_can_create_users_sending_a_reset_link(): void
    {
        Notification::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/users', [
                'name' => 'Invitato',
                'email' => 'invitato@example.com',
                'send_reset_link' => true,
                'roles' => [],
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(User::where('email', 'invitato@example.com')->first(), ResetPassword::class);
    }

    public function test_password_is_required_without_reset_link(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/users', [
                'name' => 'Senza Password',
                'email' => 'nopass@example.com',
                'send_reset_link' => false,
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_admins_can_not_grant_the_super_admin_role(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/users', [
                'name' => 'Furbetto',
                'email' => 'furbo@example.com',
                'send_reset_link' => true,
                'roles' => [Role::SuperAdmin->value],
            ])
            ->assertSessionHasErrors('roles.0');
    }

    public function test_admins_can_update_users_and_the_user_is_notified_of_role_changes(): void
    {
        Notification::fake();

        $user = User::factory()->withRole(Role::User)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put("/admin/users/{$user->id}", [
                'name' => 'Nome Aggiornato',
                'email' => $user->email,
                'is_active' => false,
                'roles' => [Role::Admin->value],
            ])
            ->assertRedirect('/admin/users');

        $user->refresh();

        $this->assertSame('Nome Aggiornato', $user->name);
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->hasRole(Role::Admin->value));
        Notification::assertSentTo($user, RolesChangedNotification::class);
    }

    public function test_admins_can_not_edit_super_admins(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/admin/users/{$superAdmin->id}/edit")
            ->assertForbidden();
    }

    public function test_users_can_not_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put("/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => false,
                'roles' => [Role::Admin->value],
            ])
            ->assertSessionHasErrors('is_active');
    }

    public function test_the_last_super_admin_keeps_the_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put("/admin/users/{$superAdmin->id}", [
                'name' => $superAdmin->name,
                'email' => $superAdmin->email,
                'is_active' => true,
                'roles' => [Role::Admin->value],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());
    }

    public function test_admins_can_delete_users_but_not_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect('/admin/users');
        $this->assertModelMissing($user);

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertForbidden();
        $this->assertModelExists($admin);
    }

    public function test_admins_can_send_a_password_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/admin/users/{$user->id}/password-reset")
            ->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
