<?php

namespace App\Console\Commands;

use App\Actions\Permissions\SyncPermissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('permissions:sync {--prune : Delete permissions no longer defined in App\Enums\Permission}')]
#[Description('Synchronize roles and permissions with the application enums')]
class SyncPermissionsCommand extends Command
{
    public function handle(SyncPermissions $sync): int
    {
        $result = $sync->handle(prune: (bool) $this->option('prune'));

        foreach ($result['created'] as $name) {
            $this->components->info("Permission created: {$name}");
        }

        foreach ($result['deleted'] as $name) {
            $this->components->warn("Permission deleted: {$name}");
        }

        $this->components->info('Roles and permissions are in sync.');

        return self::SUCCESS;
    }
}
