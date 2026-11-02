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

    case OrganizationsView = 'organizations.view';
    case OrganizationsCreate = 'organizations.create';
    case OrganizationsUpdate = 'organizations.update';
    case OrganizationsDelete = 'organizations.delete';

    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';
    case CatalogSettings = 'catalog.settings';

    case QuotesView = 'quotes.view';
    case QuotesCreate = 'quotes.create';
    case QuotesUpdate = 'quotes.update';
    case QuotesDelete = 'quotes.delete';
    case QuotesSupervise = 'quotes.supervise';

    case SettingsManage = 'settings.manage';

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
            self::OrganizationsView => 'Consultare le anagrafiche',
            self::OrganizationsCreate => 'Creare organizzazioni',
            self::OrganizationsUpdate => 'Modificare organizzazioni, sedi e contatti',
            self::OrganizationsDelete => 'Archiviare organizzazioni',
            self::ProductsView => 'Consultare prodotti, servizi e listini',
            self::ProductsCreate => 'Creare prodotti e servizi',
            self::ProductsUpdate => 'Modificare prodotti, servizi e prezzi per cliente',
            self::ProductsDelete => 'Eliminare prodotti e servizi',
            self::CatalogSettings => 'Gestire categorie e aliquote IVA',
            self::QuotesView => 'Consultare i propri preventivi',
            self::QuotesCreate => 'Creare preventivi',
            self::QuotesUpdate => 'Modificare e inviare i propri preventivi',
            self::QuotesDelete => 'Eliminare le proprie bozze di preventivo',
            self::QuotesSupervise => 'Vedere e gestire i preventivi di tutti',
            self::SettingsManage => 'Gestire impostazioni (azienda, email, pagamenti)',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDelete => 'Utenti',
            self::RolesView, self::RolesCreate, self::RolesUpdate, self::RolesDelete => 'Ruoli e permessi',
            self::NotificationsSend => 'Notifiche',
            self::OrganizationsView, self::OrganizationsCreate, self::OrganizationsUpdate, self::OrganizationsDelete => 'Anagrafiche',
            self::ProductsView, self::ProductsCreate, self::ProductsUpdate, self::ProductsDelete, self::CatalogSettings => 'Prodotti e servizi',
            self::QuotesView, self::QuotesCreate, self::QuotesUpdate, self::QuotesDelete, self::QuotesSupervise => 'Preventivi',
            self::SettingsManage => 'Impostazioni',
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
