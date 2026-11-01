<?php

namespace App\Enums;

/**
 * Single source of truth for application permissions.
 *
 * Add a case here, run `php artisan permissions:sync` and the permission
 * becomes available in the database, the role editor and the frontend.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    case NotificationsSend = 'notifications.send';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'Visualizzare gli utenti',
            self::UsersCreate => 'Creare utenti',
            self::UsersUpdate => 'Modificare gli utenti',
            self::UsersDelete => 'Eliminare utenti',
            self::RolesView => 'Visualizzare ruoli e permessi',
            self::RolesCreate => 'Creare ruoli',
            self::RolesUpdate => 'Modificare i ruoli',
            self::RolesDelete => 'Eliminare ruoli',
            self::NotificationsSend => 'Inviare annunci agli utenti',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDelete => 'Utenti',
            self::RolesView, self::RolesCreate, self::RolesUpdate, self::RolesDelete => 'Ruoli e permessi',
            self::NotificationsSend => 'Notifiche',
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
     * Permissions grouped for the role editor.
     *
     * @return list<array{group: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->group()][] = [
                'name' => $permission->value,
                'label' => $permission->label(),
            ];
        }

        return array_map(
            fn (string $group, array $permissions) => ['group' => $group, 'permissions' => $permissions],
            array_keys($groups),
            array_values($groups),
        );
    }
}
