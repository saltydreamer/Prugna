<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_super_admin(): void
    {
        $this->artisan('app:create-super-admin', ['email' => 'Boss@Example.com', '--password' => 'secret-password'])
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_it_generates_a_password_when_none_is_given(): void
    {
        $this->artisan('app:create-super-admin', ['email' => 'boss@example.com'])
            ->expectsOutputToContain('Generated password')
            ->assertSuccessful();
    }

    public function test_if_none_skips_when_a_super_admin_exists(): void
    {
        User::factory()->withRole(Role::SuperAdmin)->create();

        $this->artisan('app:create-super-admin', ['email' => 'second@example.com', '--if-none' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_duplicate_emails_are_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('app:create-super-admin', ['email' => 'taken@example.com'])->assertFailed();
    }
}
