<?php

namespace Tests\Feature\Profile;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile?tab=security')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('profile/Show')
                ->where('tab', 'security')
                ->where('twoFactor.enabled', false)
                ->has('user.email'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => 'Nuovo Nome', 'email' => 'nuova@example.com'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Nuovo Nome', $user->name);
        $this->assertSame('nuova@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_password_can_be_updated_and_the_user_is_notified(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_avatar_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200)])
            ->assertSessionHasNoErrors();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)->delete('/profile/avatar')->assertRedirect();

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_avatar_must_be_an_image(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');
    }

    public function test_notification_preferences_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/profile/preferences', ['email_notifications' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->email_notifications);
    }

    public function test_other_browser_sessions_require_the_password(): void
    {
        $this->actingAs(User::factory()->create())
            ->delete('/profile/sessions', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    public function test_users_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertModelMissing($user);
    }

    public function test_correct_password_is_required_to_delete_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->assertModelExists($user);
    }

    public function test_the_last_super_admin_can_not_delete_the_account(): void
    {
        $user = User::factory()->withRole(Role::SuperAdmin)->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertModelExists($user);
    }
}
