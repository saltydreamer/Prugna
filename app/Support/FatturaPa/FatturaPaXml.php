<?php

namespace App\Support\FatturaPa;

use App\Actions\Invoices\InvoiceCalculator;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\VatRate;
use App\Support\Settings;
use DOMDocument;
use DOMElement;

/**
 * FatturaPA 1.2.2 XML (FPR12 for businesses, FPA12 for public administrations) of an issued invoice,
 * ready to be uploaded on "Fatture e corrispettivi" or sent through an intermediary.
 */
class FatturaPaXml
{
    public const NAMESPACE = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    private DOMDocument $document;

    private ?string $progressiveOverride = null;

    public function __construct(private Settings $settings, private InvoiceCalculator $calculator) {}

    /**
     * @param  string|null  $progressive  ProgressivoInvio of the file (each SDI transmission has its own).
     */
    public function make(Invoice $invoice, ?string $progressive = null): string
    {
        $this->progressiveOverride = $progressive;

        $invoice->loadMissing(['lines', 'relatedInvoice']);

        $company = $this->settings->group('company');
        $customer = $invoice->customer ?? [];
        $isPublic = (bool) ($customer['is_public_administration'] ?? false);
        $totals = $this->calculator->calculate($invoice, $invoice->lines);

        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $root = $this->document->createElementNS(self::NAMESPACE, 'p:FatturaElettronica');
        $root->setAttribute('versione', $isPublic ? 'FPA12' : 'FPR12');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->document->appendChild($root);

        $header = $this->add($root, 'FatturaElettronicaHeader');
        $this->transmission($header, $invoice, $company, $customer, $isPublic);
        $this->supplier($header, $invoice, $company);
        $this->customer($header, $customer);

        $body = $this->add($root, 'FatturaElettronicaBody');
        $this->generalData($body, $invoice, $totals);
        $this->lines($body, $invoice, $totals);
        $this->payment($body, $invoice, $company);

        return $this->document->saveXML();
    }

    /**
     * IT{partita IVA}_{progressivo}.xml, progressive of 5 alphanumeric characters.
     */
    public function filename(Invoice $invoice, ?string $progressive = null): string
    {
        $company = $this->settings->group('company');

        return 'IT'.($company['vat_number'] ?? $company['tax_code'] ?? '').'_'.($progressive ?? $this->progressive($invoice)).'.xml';
    }

    private function progressive(Invoice $invoice): string
    {
        return $this->progressiveOverride ?? str_pad(strtoupper(base_convert((string) $invoice->id, 10, 36)), 5, '0', STR_PAD_LEFT);
    }

    /**
     * ProgressivoInvio of an SDI transmission: "S" + 4 base-36 characters, never equal to the
     * progressive of the downloadable XML (which starts with "0").
     */
    public static function transmissionProgressive(int $transmissionId): string
    {
        return 'S'.str_pad(strtoupper(base_convert((string) $transmissionId, 10, 36)), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $customer
     */
    private function transmission(DOMElement $header, Invoice $invoice, array $company, array $customer, bool $isPublic): void
    {
        $data = $this->add($header, 'DatiTrasmissione');
        $sender = $this->add($data, 'IdTrasmittente');
        $this->add($sender, 'IdPaese', 'IT');
        $this->add($sender, 'IdCodice', $company['tax_code'] ?? $company['vat_number']);
        $this->add($data, 'ProgressivoInvio', $this->progressive($invoice));
        $this->add($data, 'FormatoTrasmissione', $isPublic ? 'FPA12' : 'FPR12');

        $country = $customer['country'] ?? 'IT';
        $code = strtoupper(trim((string) ($customer['sdi_code'] ?? '')));

        if ($country !== 'IT') {
            $code = 'XXXXXXX';
        } elseif ($code === '') {
            $code = $isPublic ? '999999' : '0000000';
        }

        $this->add($data, 'CodiceDestinatario', $code);

        if ($code === '0000000' && filled($customer['pec'] ?? null)) {
            $this->add($data, 'PECDestinatario', $customer['pec']);
        }
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function supplier(DOMElement $header, Invoice $invoice, array $company): void
    {
        $supplier = $this->add($header, 'CedentePrestatore');
        $registry = $this->add($supplier, 'DatiAnagrafici');
        $vat = $this->add($registry, 'IdFiscaleIVA');
        $this->add($vat, 'IdPaese', $company['country'] ?? 'IT');
        $this->add($vat, 'IdCodice', $company['vat_number']);

        if (filled($company['tax_code'] ?? null)) {
            $this->add($registry, 'CodiceFiscale', $company['tax_code']);
        }

        $this->add($this->add($registry, 'Anagrafica'), 'Denominazione', $this->text($company['name'], 80));
        $this->add($registry, 'RegimeFiscale', $invoice->regime);

        $this->address($supplier, 'Sede', [
            'address' => $company['address'] ?? null,
            'postal_code' => $company['postal_code'] ?? null,
            'city' => $company['city'] ?? null,
            'province' => $company['province'] ?? null,
            'country' => $company['country'] ?? 'IT',
        ]);

        if (preg_match('/^([A-Z]{2})[\s\-\/]*([A-Z0-9]{1,20})$/i', trim((string) ($company['rea'] ?? '')), $rea)) {
            $registration = $this->add($supplier, 'IscrizioneREA');
            $this->add($registration, 'Ufficio', strtoupper($rea[1]));
            $this->add($registration, 'NumeroREA', strtoupper($rea[2]));
            $this->add($registration, 'StatoLiquidazione', 'LN');
        }

        $phone = preg_replace('/[^0-9+]/', '', (string) ($company['phone'] ?? ''));
        $phone = strlen($phone) >= 5 && strlen($phone) <= 12 ? $phone : null;
        $email = filled($company['email'] ?? null) && strlen($company['email']) <= 256 ? $company['email'] : null;

        if ($phone || $email) {
            $contacts = $this->add($supplier, 'Contatti');

            if ($phone) {
                $this->add($contacts, 'Telefono', $phone);
            }

            if ($email) {
                $this->add($contacts, 'Email', $email);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function customer(DOMElement $header, array $customer): void
    {
        $element = $this->add($header, 'CessionarioCommittente');
        $registry = $this->add($element, 'DatiAnagrafici');
        $country = $customer['country'] ?? 'IT';

        if (filled($customer['vat_number'] ?? null)) {
            $vat = $this->add($registry, 'IdFiscaleIVA');
            $this->add($vat, 'IdPaese', $country);
            $this->add($vat, 'IdCodice', $customer['vat_number']);
        }

        if (filled($customer['tax_code'] ?? null) && $country === 'IT') {
            $this->add($registry, 'CodiceFiscale', $customer['tax_code']);
        }

        $this->add($this->add($registry, 'Anagrafica'), 'Denominazione', $this->text($customer['name'] ?? '', 80));
        $this->address($element, 'Sede', $customer);
    }

    /**
     * @param  array<string, mixed>  $data  address, postal_code, city, province, country
     */
    private function address(DOMElement $parent, string $name, array $data): void
    {
        $country = $data['country'] ?? 'IT';
        $address = $this->add($parent, $name);
        $this->add($address, 'Indirizzo', $this->text($data['address'] ?? '', 60));
        $this->add($address, 'CAP', $country === 'IT' ? $data['postal_code'] : '00000');
        $this->add($address, 'Comune', $this->text($data['city'] ?? '', 60));

        if ($country === 'IT' && filled($data['province'] ?? null)) {
            $this->add($address, 'Provincia', strtoupper($data['province']));
        }

        $this->add($address, 'Nazione', $country);
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function generalData(DOMElement $body, Invoice $invoice, array $totals): void
    {
        $general = $this->add($body, 'DatiGenerali');
        $document = $this->add($general, 'DatiGeneraliDocumento');
        $this->add($document, 'TipoDocumento', $invoice->type->documentType());
        $this->add($document, 'Divisa', 'EUR');
        $this->add($document, 'Data', $invoice->issue_date->toDateString());
        $this->add($document, 'Numero', $invoice->number);

        if ($totals['withholding_amount'] > 0) {
            $withholding = $this->add($document, 'DatiRitenuta');
            $this->add($withholding, 'TipoRitenuta', $invoice->withholding_type);
            $this->add($withholding, 'ImportoRitenuta', $this->amount($totals['withholding_amount']));
            $this->add($withholding, 'AliquotaRitenuta', $this->amount($invoice->withholding_rate));
            $this->add($withholding, 'CausalePagamento', $invoice->withholding_reason);
        }

        if ((float) $invoice->stamp_duty_amount > 0) {
            $stamp = $this->add($document, 'DatiBollo');
            $this->add($stamp, 'BolloVirtuale', 'SI');
            $this->add($stamp, 'ImportoBollo', $this->amount($invoice->stamp_duty_amount));
        }

        if ($totals['pension_amount'] != 0) {
            $pension = $this->add($document, 'DatiCassaPrevidenziale');
            $this->add($pension, 'TipoCassa', $invoice->pension_type);
            $this->add($pension, 'AlCassa', $this->amount($invoice->pension_rate));
            $this->add($pension, 'ImportoContributoCassa', $this->amount($totals['pension_amount']));
            $this->add($pension, 'ImponibileCassa', $this->amount($totals['pension_base']));
            $this->add($pension, 'AliquotaIVA', $this->amount($invoice->pension_vat_rate));

            if ($invoice->apply_withholding && $invoice->pension_withholding) {
                $this->add($pension, 'Ritenuta', 'SI');
            }

            if ((float) $invoice->pension_vat_rate == 0.0) {
                $this->add($pension, 'Natura', $invoice->pension_vat_nature ?? 'N4');
            }
        }

        $this->add($document, 'ImportoTotaleDocumento', $this->amount($totals['total']));

        if (filled($invoice->title)) {
            $this->add($document, 'Causale', $this->text($invoice->title, 200));
        }

        if (filled($invoice->customer_reference) || filled($invoice->cig) || filled($invoice->cup)) {
            $order = $this->add($general, 'DatiOrdineAcquisto');
            $this->add($order, 'IdDocumento', $this->text($invoice->customer_reference ?: 'ND', 20));

            if ($invoice->customer_reference_date) {
                $this->add($order, 'Data', $invoice->customer_reference_date->toDateString());
            }

            if (filled($invoice->cup)) {
                $this->add($order, 'CodiceCUP', strtoupper($invoice->cup));
            }

            if (filled($invoice->cig)) {
                $this->add($order, 'CodiceCIG', strtoupper($invoice->cig));
            }
        }

        if ($invoice->type === InvoiceType::CreditNote && $invoice->relatedInvoice) {
            $related = $this->add($general, 'DatiFattureCollegate');
            $this->add($related, 'IdDocumento', $invoice->relatedInvoice->number);
            $this->add($related, 'Data', $invoice->relatedInvoice->issue_date->toDateString());

            // The CIG/CUP of the credited invoice, where public bodies look for them on a credit note.
            if (filled($cup = $invoice->cup ?: $invoice->relatedInvoice->cup)) {
                $this->add($related, 'CodiceCUP', strtoupper($cup));
            }

            if (filled($cig = $invoice->cig ?: $invoice->relatedInvoice->cig)) {
                $this->add($related, 'CodiceCIG', strtoupper($cig));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    private function lines(DOMElement $body, Invoice $invoice, array $totals): void
    {
        $goods = $this->add($body, 'DatiBeniServizi');
        $number = 0;

        foreach ($invoice->lines->filter(fn (InvoiceLine $line) => $line->isItem()) as $line) {
            $detail = $this->add($goods, 'DettaglioLinee');
            $this->add($detail, 'NumeroLinea', (string) ++$number);

            if (filled($line->code)) {
                $code = $this->add($detail, 'CodiceArticolo');
                $this->add($code, 'CodiceTipo', 'INTERNO');
                $this->add($code, 'CodiceValore', $this->text($line->code, 35));
            }

            $this->add($detail, 'Descrizione', $this->text($line->description, 1000));
            $this->add($detail, 'Quantita', $this->decimal($line->quantity, 2, 8));

            if (filled($line->unit)) {
                $this->add($detail, 'UnitaMisura', $this->text($line->unit, 10));
            }

            $this->add($detail, 'PrezzoUnitario', $this->decimal($line->unit_price, 2, 8));

            if ((float) $line->discount_percent > 0) {
                $discount = $this->add($detail, 'ScontoMaggiorazione');
                $this->add($discount, 'Tipo', 'SC');
                $this->add($discount, 'Percentuale', $this->amount($line->discount_percent));
            }

            $this->add($detail, 'PrezzoTotale', $this->amount($line->total));
            $this->add($detail, 'AliquotaIVA', $this->amount($line->vat_rate));

            if ($invoice->apply_withholding && (float) $invoice->withholding_rate > 0 && $line->system !== InvoiceLine::SYSTEM_STAMP_DUTY) {
                $this->add($detail, 'Ritenuta', 'SI');
            }

            if (filled($line->vat_nature)) {
                $this->add($detail, 'Natura', $line->vat_nature);
            }
        }

        foreach ($totals['vat_summary'] as $group) {
            $summary = $this->add($goods, 'DatiRiepilogo');
            $this->add($summary, 'AliquotaIVA', $this->amount($group['rate']));

            if ($group['nature']) {
                $this->add($summary, 'Natura', $group['nature']);
            }

            $this->add($summary, 'ImponibileImporto', $this->amount($group['taxable']));
            $this->add($summary, 'Imposta', $this->amount($group['vat']));

            if ($group['nature']) {
                $this->add($summary, 'RiferimentoNormativo', $this->text($this->legalReference($group['nature']), 100));
            } else {
                $this->add($summary, 'EsigibilitaIVA', $invoice->split_payment ? 'S' : 'I');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function payment(DOMElement $body, Invoice $invoice, array $company): void
    {
        if ($invoice->type === InvoiceType::CreditNote || (float) $invoice->amount_due <= 0) {
            return;
        }

        $method = $invoice->payment_method ?: 'MP05';
        $payment = $this->add($body, 'DatiPagamento');
        $this->add($payment, 'CondizioniPagamento', 'TP02');
        $detail = $this->add($payment, 'DettaglioPagamento');
        $this->add($detail, 'ModalitaPagamento', $method);

        if ($invoice->due_date) {
            $this->add($detail, 'DataScadenzaPagamento', $invoice->due_date->toDateString());
        }

        $this->add($detail, 'ImportoPagamento', $this->amount($invoice->amount_due));

        if (filled($company['bank_name'] ?? null) && in_array($method, ['MP05', 'MP19', 'MP21'], true)) {
            $this->add($detail, 'IstitutoFinanziario', $this->text($company['bank_name'], 80));
        }

        if (filled($company['iban'] ?? null) && in_array($method, ['MP05', 'MP19', 'MP21'], true)) {
            $this->add($detail, 'IBAN', $company['iban']);
        }
    }

    private function legalReference(string $nature): string
    {
        return VatRate::query()->where('nature', $nature)->whereNotNull('legal_reference')->value('legal_reference')
            ?? match (true) {
                $nature === 'N1' => 'Escluso art. 15 DPR 633/72',
                $nature === 'N2.2' => 'Operazione in franchigia da IVA - art. 1 c. 54-89 L. 190/2014',
                str_starts_with($nature, 'N4') => 'Esente art. 10 DPR 633/72',
                default => 'Operazione senza IVA',
            };
    }

    private function add(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $this->document->createElement($name);

        if ($value !== null) {
            $element->appendChild($this->document->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Decimal with at least $min and at most $max decimals (e.g. 1.00, 12.5000 → 12.50, 0.125 → 0.125).
     */
    private function decimal(mixed $value, int $min, int $max): string
    {
        $formatted = rtrim(number_format((float) $value, $max, '.', ''), '0');
        $decimals = strlen(substr(strrchr($formatted, '.') ?: '.', 1));

        return number_format((float) $value, max($min, $decimals), '.', '');
    }

    /**
     * The schema only accepts Basic Latin and Latin-1 characters: dashes, quotes, € and the rest are transliterated.
     */
    private function text(?string $value, int $max): string
    {
        $value = strtr((string) $value, ['–' => '-', '—' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '€' => 'EUR', '…' => '...', "\t" => ' ']);
        $value = (string) preg_replace('/[^\x{0000}-\x{00FF}]/u', '?', $value);
        $value = (string) preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}]/u', '', $value);
        $value = trim($value);

        return mb_substr($value === '' ? '-' : $value, 0, $max);
    }
}
