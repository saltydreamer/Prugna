<?php

namespace App\Notifications;

class RolesChangedNotification extends AppNotification
{
    /**
     * @param  list<string>  $roles  Human readable role names.
     */
    public function __construct(public array $roles) {}

    public function title(): string
    {
        return __('I tuoi ruoli sono stati aggiornati');
    }

    public function message(): string
    {
        return $this->roles === []
            ? __('Non hai più alcun ruolo assegnato.')
            : __('Ruoli attuali: :roles.', ['roles' => implode(', ', $this->roles)]);
    }

    public function icon(): string
    {
        return 'mdi-shield-account-outline';
    }

    public function color(): string
    {
        return 'info';
    }
}
