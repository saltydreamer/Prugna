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

    case SuppliersView = 'suppliers.view';
    case SuppliersCreate = 'suppliers.create';
    case SuppliersUpdate = 'suppliers.update';
    case SuppliersDelete = 'suppliers.delete';

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

    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';

    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    case InvoicesIssue = 'invoices.issue';

    case PurchasesView = 'purchases.view';
    case PurchasesManage = 'purchases.manage';

    case ExpensesView = 'expenses.view';
    case ExpensesManage = 'expenses.manage';

    case InterventionsView = 'interventions.view';
    case InterventionsManage = 'interventions.manage';
    case InterventionsSupervise = 'interventions.supervise';
    case ContractsManage = 'contracts.manage';

    case AiUse = 'ai.use';

    case SettingsManage = 'settings.manage';

    case DolibarrImport = 'dolibarr.import';

    case StatisticsView = 'statistics.view';

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
            self::OrganizationsView => 'Consultare i clienti',
            self::OrganizationsCreate => 'Creare clienti',
            self::OrganizationsUpdate => 'Modificare clienti, sedi e contatti',
            self::OrganizationsDelete => 'Archiviare clienti',
            self::SuppliersView => 'Consultare i fornitori',
            self::SuppliersCreate => 'Creare fornitori',
            self::SuppliersUpdate => 'Modificare fornitori, sedi e contatti',
            self::SuppliersDelete => 'Archiviare fornitori',
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
            self::OrdersView => 'Consultare gli ordini clienti',
            self::OrdersManage => 'Creare, modificare e chiudere gli ordini',
            self::InvoicesView => 'Consultare le fatture',
            self::InvoicesManage => 'Preparare e modificare le bozze di fattura',
            self::InvoicesIssue => 'Emettere fatture e note di credito',
            self::PurchasesView => 'Consultare gli ordini ai fornitori e i costi',
            self::PurchasesManage => 'Creare, inviare e gestire gli ordini ai fornitori',
            self::ExpensesView => 'Consultare spese, fatture fornitore e scadenze',
            self::ExpensesManage => 'Registrare spese e fatture fornitore, segnare i pagamenti',
            self::InterventionsView => 'Consultare i propri interventi',
            self::InterventionsManage => 'Registrare e modificare i propri interventi',
            self::InterventionsSupervise => 'Vedere e gestire gli interventi di tutti i tecnici',
            self::ContractsManage => 'Gestire i contratti a monte ore',
            self::AiUse => "Usare l'AI per leggere i documenti",
            self::SettingsManage => 'Gestire impostazioni (azienda, email, pagamenti)',
            self::DolibarrImport => 'Importare i dati da Dolibarr',
            self::StatisticsView => 'Vedere le statistiche di fatturato',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDelete => 'Utenti',
            self::RolesView, self::RolesCreate, self::RolesUpdate, self::RolesDelete => 'Ruoli e permessi',
            self::NotificationsSend => 'Notifiche',
            self::OrganizationsView, self::OrganizationsCreate, self::OrganizationsUpdate, self::OrganizationsDelete => 'Clienti',
            self::SuppliersView, self::SuppliersCreate, self::SuppliersUpdate, self::SuppliersDelete => 'Fornitori',
            self::ProductsView, self::ProductsCreate, self::ProductsUpdate, self::ProductsDelete, self::CatalogSettings => 'Prodotti e servizi',
            self::QuotesView, self::QuotesCreate, self::QuotesUpdate, self::QuotesDelete, self::QuotesSupervise => 'Preventivi',
            self::OrdersView, self::OrdersManage => 'Ordini',
            self::InvoicesView, self::InvoicesManage, self::InvoicesIssue => 'Fatture',
            self::PurchasesView, self::PurchasesManage => 'Acquisti',
            self::ExpensesView, self::ExpensesManage => 'Spese',
            self::InterventionsView, self::InterventionsManage, self::InterventionsSupervise, self::ContractsManage => 'Interventi',
            self::AiUse => 'Intelligenza artificiale',
            self::SettingsManage, self::DolibarrImport => 'Impostazioni',
            self::StatisticsView => 'Statistiche',
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
