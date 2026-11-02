<?php

namespace App\Console\Commands;

use App\Actions\Permissions\SyncPermissions;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('app:create-super-admin
    {email : Email address used to sign in}
    {--name=Super Admin : Display name}
    {--password= : Password (a random one is generated and printed when omitted)}
    {--if-none : Do nothing when a super admin already exists}')]
#[Description('Create a super admin account (use it instead of the demo seeder in production)')]
class CreateSuperAdminCommand extends Command
{
    public function handle(SyncPermissions $syncPermissions): int
    {
        $syncPermissions->handle();

        if ($this->option('if-none') && User::role(Role::SuperAdmin->value)->exists()) {
            $this->components->info('A super admin already exists: nothing to do.');

            return self::SUCCESS;
        }

        $email = Str::lower((string) $this->argument('email'));
        $generated = ! $this->option('password');
        $password = $this->option('password') ?: Str::password(20, symbols: false);

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => $password,
        ]);

        $user->markEmailAsVerified();
        $user->assignRole(Role::SuperAdmin->value);

        $this->components->info("Super admin created: {$email}");

        if ($generated) {
            $this->components->warn("Generated password: {$password}");
            $this->components->warn('Sign in and change it from the profile page.');
        }

        return self::SUCCESS;
    }
}
