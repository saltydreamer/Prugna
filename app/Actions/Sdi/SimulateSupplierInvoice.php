<?php

namespace App\Actions\Sdi;

use App\Support\Sdi\OpenapiClient;
use App\Support\Settings;

/**
 * Sandbox only: Openapi sends a test supplier invoice addressed to our company to the supplier-invoice callback.
 */
class SimulateSupplierInvoice
{
    public function __construct(private OpenapiClient $client, private Settings $settings) {}

    public function handle(): void
    {
        $fiscalId = (string) ($this->settings->get('sdi.fiscal_id') ?: $this->settings->get('company.vat_number'));
        $amount = random_int(50, 500);

        $this->client->simulate('supplier-invoice', [
            'fattura_elettronica_header' => [
                'dati_trasmissione' => ['codice_destinatario' => OpenapiClient::RECIPIENT_CODE],
                'cedente_prestatore' => [
                    'dati_anagrafici' => [
                        'id_fiscale_iva' => ['id_paese' => 'IT', 'id_codice' => '12345678901'],
                        'anagrafica' => ['denominazione' => 'Fornitore di prova Srl'],
                        'regime_fiscale' => 'RF01',
                    ],
                    'sede' => ['indirizzo' => 'Via di Prova 1', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI', 'nazione' => 'IT'],
                ],
                'cessionario_committente' => [
                    'dati_anagrafici' => [
                        'id_fiscale_iva' => ['id_paese' => 'IT', 'id_codice' => $fiscalId],
                        'anagrafica' => ['denominazione' => (string) ($this->settings->get('company.name') ?: config('app.name'))],
                    ],
                    'sede' => [
                        'indirizzo' => (string) ($this->settings->get('company.address') ?: 'Via Roma 1'),
                        'cap' => (string) ($this->settings->get('company.postal_code') ?: '20121'),
                        'comune' => (string) ($this->settings->get('company.city') ?: 'Milano'),
                        'provincia' => (string) ($this->settings->get('company.province') ?: 'MI'),
                        'nazione' => 'IT',
                    ],
                ],
            ],
            'fattura_elettronica_body' => [[
                'dati_generali' => ['dati_generali_documento' => ['tipo_documento' => 'TD01', 'divisa' => 'EUR', 'data' => today()->toDateString(), 'numero' => 'PROVA-'.now()->format('His')]],
                'dati_beni_servizi' => [
                    'dettaglio_linee' => [['numero_linea' => 1, 'descrizione' => 'Materiale di prova', 'prezzo_unitario' => number_format($amount, 2, '.', ''), 'prezzo_totale' => number_format($amount, 2, '.', ''), 'aliquota_iva' => '22.00']],
                    'dati_riepilogo' => [['aliquota_iva' => '22.00', 'imponibile_importo' => number_format($amount, 2, '.', ''), 'imposta' => number_format($amount * 0.22, 2, '.', '')]],
                ],
            ]],
        ]);
    }
}
