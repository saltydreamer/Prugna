<?php

namespace App\Enums;

/**
 * Customers and suppliers are separate registries: an organization is one or the other.
 */
enum OrganizationRole: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Cliente',
            self::Supplier => 'Fornitore',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Customer => 'Clienti',
            self::Supplier => 'Fornitori',
        };
    }

    /**
     * Route names of the list and of the create page.
     */
    public function routePrefix(): string
    {
        return match ($this) {
            self::Customer => 'registry.customers',
            self::Supplier => 'registry.suppliers',
        };
    }

    /**
     * @param  'view'|'create'|'update'|'delete'  $action
     */
    public function permission(string $action): Permission
    {
        return match ($this) {
            self::Customer => Permission::from("organizations.{$action}"),
            self::Supplier => Permission::from("suppliers.{$action}"),
        };
    }
}
