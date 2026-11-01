<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * System roles. They are created by `php artisan permissions:sync` and can't be deleted.
 * Additional roles can be created at runtime from the admin panel.
 */
enum Role: string
{
    /** Owns every permission (see AppServiceProvider::configureAuthorization). */
    case SuperAdmin = 'super-admin';

    case Admin = 'admin';

    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super amministratore',
            self::Admin => 'Amministratore',
            self::User => 'Utente',
        };
    }

    /**
     * Default permissions assigned when the role is first created.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [],
            self::Admin => Permission::cases(),
            self::User => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Human readable name for any role, including the ones created at runtime.
     */
    public static function labelFor(string $name): string
    {
        return self::tryFrom($name)?->label() ?? Str::headline($name);
    }

    public static function isSystem(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }
}
