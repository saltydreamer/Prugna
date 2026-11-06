<?php

namespace Tests\Feature\Sales;

use App\Actions\Invoices\BuildInvoice;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\SaveInvoice;
use App\Enums\OrganizationType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\VatRate;
use App\Support\FatturaPa\FatturaPaXml;
use App\Support\Settings;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The generated XML is validated against the official FatturaPA schema (tests/Fixtures/fatturapa).
 */
class FatturaPaXmlTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEMA = __DIR__.'/../../Fixtures/fatturapa/Schema_del_file_xml_FatturaPA_v1.2.3.xsd';

    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->set([
            'company.name' => 'Officina Digitale Srl – Società benefit',
            'company.vat_number' => '01234567897',
            'company.tax_code' => '01234567897',
            'company.address' => 'Via Roma 1',
            'company.postal_code' => '20121',
            'company.city' => 'Milano',
            'company.province' => 'MI',
            'company.rea' => 'MI-1234567',
            'company.phone' => '02 1234567',
            'company.email' => 'amministrazione@example.com',
            'company.iban' => 'IT60X0542811101000000123456',
            'company.bank_name' => 'Banca di Prova',
        ]);
    }

    private function issue(Organization $customer, array $lines, array $data = []): Invoice
    {
        $invoice = app(SaveInvoice::class)->handle(new Invoice, [
            'organization_id' => $customer->id,
            'issue_date' => today()->toDateString(),
            'title' => 'Servizi di novembre – canone',
            'payment_term_id' => PaymentTerm::default()->id,
            ...$data,
            'lines' => $lines,
        ]);

        return app(IssueInvoice::class)->handle($invoice);
    }

    /**
     * @return array{0: DOMDocument, 1: DOMXPath}
     */
    private function validXml(Invoice $invoice): array
    {
        $document = new DOMDocument;
        $document->loadXML(app(FatturaPaXml::class)->make($invoice->fresh()));

        libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(self::SCHEMA);
        $errors = collect(libxml_get_errors())->map(fn ($error) => trim($error->message))->implode("\n");
        libxml_clear_errors();

        $this->assertTrue($valid, "XML non valido:\n{$errors}");

        return [$document, new DOMXPath($document)];
    }

    private function value(DOMXPath $xpath, string $path): ?string
    {
        $nodes = $xpath->query($path);

        return $nodes->length ? $nodes->item(0)->textContent : null;
    }

    public function test_ordinary_invoice(): void
    {
        $customer = Organization::factory()->withDetails(1, 0)->create(['sdi_code' => 'ABC1234']);
        $rate = VatRate::default();

        $invoice = $this->issue($customer, [
            ['type' => 'section', 'description' => 'Hardware'],
            ['type' => 'item', 'code' => 'P00001', 'description' => 'Notebook “Pro” 14″', 'unit' => 'pz', 'quantity' => 2, 'unit_price' => 499.99, 'discount_percent' => 10, 'vat_rate_id' => $rate->id],
            ['type' => 'item', 'description' => 'Assistenza', 'unit' => 'h', 'quantity' => 1.5, 'unit_price' => 60, 'vat_rate_id' => $rate->id],
        ], ['customer_reference' => 'PO-2026-77', 'customer_reference_date' => today()->subWeek()->toDateString()]);

        [, $xpath] = $this->validXml($invoice);

        $this->assertSame('FPR12', $this->value($xpath, '//DatiTrasmissione/FormatoTrasmissione'));
        $this->assertSame('ABC1234', $this->value($xpath, '//DatiTrasmissione/CodiceDestinatario'));
        $this->assertSame('TD01', $this->value($xpath, '//TipoDocumento'));
        $this->assertSame($invoice->number, $this->value($xpath, '//DatiGeneraliDocumento/Numero'));
        $this->assertSame('2', (string) $xpath->query('//DettaglioLinee')->length);
        $this->assertSame('899.98', $this->value($xpath, '//DettaglioLinee[1]/PrezzoTotale'));
        $this->assertSame('989.98', $this->value($xpath, '//DatiRiepilogo/ImponibileImporto'));
        $this->assertSame('217.80', $this->value($xpath, '//DatiRiepilogo/Imposta'));
        $this->assertSame('1207.78', $this->value($xpath, '//ImportoTotaleDocumento'));
        $this->assertSame('PO-2026-77', $this->value($xpath, '//DatiOrdineAcquisto/IdDocumento'));
        $this->assertSame('MP05', $this->value($xpath, '//ModalitaPagamento'));
        $this->assertSame('IT60X0542811101000000123456', $this->value($xpath, '//IBAN'));
        $this->assertSame('MI', $this->value($xpath, '//IscrizioneREA/Ufficio'));
    }

    public function test_professional_with_withholding_and_pension_fund(): void
    {
        app(Settings::class)->set([
            'fiscal.withholding_enabled' => true,
            'fiscal.pension_enabled' => true,
            'fiscal.pension_type' => 'TC04',
            'fiscal.pension_rate' => 4,
            'fiscal.pension_withholding' => false,
        ]);

        $customer = Organization::factory()->withDetails(1, 0)->create(['type' => OrganizationType::Company]);

        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Progettazione', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate_id' => VatRate::default()->id],
        ], ['apply_withholding' => true, 'apply_pension' => true]);

        [, $xpath] = $this->validXml($invoice);

        $this->assertSame('RT01', $this->value($xpath, '//DatiRitenuta/TipoRitenuta'));
        $this->assertSame('200.00', $this->value($xpath, '//DatiRitenuta/ImportoRitenuta'));
        $this->assertSame('TC04', $this->value($xpath, '//DatiCassaPrevidenziale/TipoCassa'));
        $this->assertSame('40.00', $this->value($xpath, '//DatiCassaPrevidenziale/ImportoContributoCassa'));
        $this->assertSame('1000.00', $this->value($xpath, '//DatiCassaPrevidenziale/ImponibileCassa'));
        $this->assertSame('SI', $this->value($xpath, '//DettaglioLinee/Ritenuta'));
        $this->assertSame('1040.00', $this->value($xpath, '//DatiRiepilogo/ImponibileImporto'));
        $this->assertSame('1268.80', $this->value($xpath, '//ImportoTotaleDocumento'));
        $this->assertSame('1068.80', $this->value($xpath, '//ImportoPagamento'));
    }

    public function test_flat_rate_regime_with_stamp_duty(): void
    {
        app(Settings::class)->set(['fiscal.regime' => 'RF19']);
        $customer = Organization::factory()->withDetails(1, 0)->create(['sdi_code' => null, 'pec' => 'cliente@pec.example.com']);

        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Sito web', 'quantity' => 1, 'unit_price' => 800, 'vat_rate_id' => VatRate::query()->where('nature', 'N2.2')->value('id')],
        ]);

        [, $xpath] = $this->validXml($invoice);

        $this->assertSame('RF19', $this->value($xpath, '//RegimeFiscale'));
        $this->assertSame('0000000', $this->value($xpath, '//CodiceDestinatario'));
        $this->assertSame('cliente@pec.example.com', $this->value($xpath, '//PECDestinatario'));
        $this->assertSame('SI', $this->value($xpath, '//DatiBollo/BolloVirtuale'));
        $this->assertSame('2.00', $this->value($xpath, '//DatiBollo/ImportoBollo'));
        $this->assertSame('N1', $this->value($xpath, '//DettaglioLinee[2]/Natura'));
        $this->assertSame('802.00', $this->value($xpath, '//ImportoTotaleDocumento'));
    }

    public function test_public_administration_from_the_flat_rate_regime(): void
    {
        app(Settings::class)->set(['fiscal.regime' => 'RF19']);
        $customer = Organization::factory()->withDetails(1, 0)->create([
            'type' => OrganizationType::PublicBody,
            'sdi_code' => 'UFX9Z1',
            // No VAT in the flat-rate regime: split payment is ignored.
            'split_payment' => true,
            'tax_code' => '80012345678',
        ]);

        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Assistenza informatica', 'quantity' => 1, 'unit_price' => 800, 'vat_rate_id' => VatRate::query()->where('nature', 'N2.2')->value('id')],
        ], ['cig' => 'Z1234ABCDE', 'cup' => 'J11B21000000001']);

        $this->assertFalse($invoice->fresh()->split_payment);

        [, $xpath] = $this->validXml($invoice);

        $this->assertSame('FPA12', $this->value($xpath, '//FormatoTrasmissione'));
        $this->assertSame('FPA12', $xpath->document->documentElement->getAttribute('versione'));
        $this->assertSame('UFX9Z1', $this->value($xpath, '//CodiceDestinatario'));
        $this->assertSame('RF19', $this->value($xpath, '//RegimeFiscale'));
        $this->assertSame(0, $xpath->query('//EsigibilitaIVA')->length);
        $this->assertSame('N2.2', $this->value($xpath, '//DettaglioLinee[1]/Natura'));
        $this->assertSame('SI', $this->value($xpath, '//DatiBollo/BolloVirtuale'));
        $this->assertSame('Z1234ABCDE', $this->value($xpath, '//DatiOrdineAcquisto/CodiceCIG'));
        $this->assertSame('J11B21000000001', $this->value($xpath, '//DatiOrdineAcquisto/CodiceCUP'));
        $this->assertSame('802.00', $this->value($xpath, '//ImportoPagamento'));
    }

    public function test_public_administration_with_split_payment_and_credit_note(): void
    {
        $customer = Organization::factory()->withDetails(1, 0)->create([
            'type' => OrganizationType::PublicBody,
            'sdi_code' => 'UFX9Z1',
            'split_payment' => true,
            'tax_code' => '80012345678',
        ]);

        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Manutenzione', 'quantity' => 1, 'unit_price' => 500, 'vat_rate_id' => VatRate::default()->id],
        ], ['cig' => 'Z1234ABCDE', 'cup' => 'J11B21000000001']);

        [, $xpath] = $this->validXml($invoice);

        $this->assertSame('FPA12', $this->value($xpath, '//FormatoTrasmissione'));
        $this->assertSame('UFX9Z1', $this->value($xpath, '//CodiceDestinatario'));
        $this->assertSame('S', $this->value($xpath, '//EsigibilitaIVA'));
        $this->assertSame('Z1234ABCDE', $this->value($xpath, '//DatiOrdineAcquisto/CodiceCIG'));
        $this->assertSame('ND', $this->value($xpath, '//DatiOrdineAcquisto/IdDocumento'));
        $this->assertSame('500.00', $this->value($xpath, '//ImportoPagamento'));

        $note = app(SaveInvoice::class)->handle(new Invoice, app(BuildInvoice::class)->creditNote($invoice));
        $note = app(IssueInvoice::class)->handle($note);

        [, $xpath] = $this->validXml($note);

        $this->assertSame('TD04', $this->value($xpath, '//TipoDocumento'));
        $this->assertSame($invoice->number, $this->value($xpath, '//DatiFattureCollegate/IdDocumento'));
        $this->assertSame('Z1234ABCDE', $this->value($xpath, '//DatiFattureCollegate/CodiceCIG'));
        $this->assertSame('J11B21000000001', $this->value($xpath, '//DatiFattureCollegate/CodiceCUP'));
        $this->assertSame('Z1234ABCDE', $this->value($xpath, '//DatiOrdineAcquisto/CodiceCIG'));
        $this->assertSame(0, $xpath->query('//DatiPagamento')->length);
    }

    /**
     * Invoice refused by a public body (e.g. CIG or amount): it stays issued, a credit note cancels it whole.
     */
    public function test_credit_note_to_a_public_body_from_the_flat_rate_regime(): void
    {
        app(Settings::class)->set(['fiscal.regime' => 'RF19', 'fiscal.stamp_duty_enabled' => true, 'fiscal.stamp_duty_charge_customer' => true]);
        $customer = Organization::factory()->withDetails(1, 0)->create([
            'type' => OrganizationType::PublicBody,
            'vat_number' => null,
            'tax_code' => '80007790506',
            'sdi_code' => 'EPAKB1',
        ]);
        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Assistenza tecnica - ore prepagate', 'quantity' => 12, 'unit_price' => 60, 'vat_rate_id' => VatRate::query()->where('nature', 'N2.2')->value('id')],
        ], ['cig' => 'BDABECBC19']);
        $this->assertSame('722.00', $invoice->fresh()->total);

        $this->actingAs(User::factory()->admin()->create())->post("/sales/invoices/{$invoice->id}/credit-note")->assertRedirect();
        $note = app(IssueInvoice::class)->handle(Invoice::query()->where('related_invoice_id', $invoice->id)->sole());

        [, $xpath] = $this->validXml($note);

        $this->assertSame('FPA12', $this->value($xpath, '//FormatoTrasmissione'));
        $this->assertSame('EPAKB1', $this->value($xpath, '//CodiceDestinatario'));
        $this->assertSame('TD04', $this->value($xpath, '//TipoDocumento'));
        $this->assertSame('722.00', $this->value($xpath, '//ImportoTotaleDocumento'));
        $this->assertSame('SI', $this->value($xpath, '//DatiBollo/BolloVirtuale'));
        $this->assertSame($invoice->number, $this->value($xpath, '//DatiFattureCollegate/IdDocumento'));
        $this->assertSame('BDABECBC19', $this->value($xpath, '//DatiFattureCollegate/CodiceCIG'));
        $this->assertSame('80007790506', $this->value($xpath, '//CessionarioCommittente//CodiceFiscale'));
    }

    /**
     * Stamp duty not charged back: still due and declared, no reimbursement line, total without the 2 €.
     */
    public function test_stamp_duty_not_charged_to_the_customer(): void
    {
        app(Settings::class)->set(['fiscal.regime' => 'RF19']);
        $customer = Organization::factory()->withDetails(1, 0)->create(['sdi_code' => 'ABC1234']);

        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Sito web', 'quantity' => 1, 'unit_price' => 800, 'vat_rate_id' => VatRate::query()->where('nature', 'N2.2')->value('id')],
        ], ['charge_stamp_duty' => false]);

        [, $xpath] = $this->validXml($invoice);

        $this->assertSame('SI', $this->value($xpath, '//DatiBollo/BolloVirtuale'));
        $this->assertSame('2.00', $this->value($xpath, '//DatiBollo/ImportoBollo'));
        $this->assertSame(1, $xpath->query('//DettaglioLinee')->length);
        $this->assertSame('800.00', $this->value($xpath, '//ImportoTotaleDocumento'));
        $this->assertSame('800.00', $this->value($xpath, '//ImportoPagamento'));

        $note = app(SaveInvoice::class)->handle(new Invoice, app(BuildInvoice::class)->creditNote($invoice));
        $this->assertFalse($note->charge_stamp_duty);
        $this->assertSame('800.00', $note->total);
    }

    public function test_credit_note_to_a_private_person(): void
    {
        $customer = Organization::factory()->withDetails(1, 0)->create([
            'type' => OrganizationType::Other,
            'name' => 'Mario Rossi',
            'vat_number' => null,
            'tax_code' => 'RSSMRA80A01G702Z',
            'sdi_code' => null,
            'pec' => null,
        ]);
        $invoice = $this->issue($customer, [
            ['type' => 'item', 'description' => 'Riparazione PC', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => VatRate::default()->id],
        ]);

        $note = app(SaveInvoice::class)->handle(new Invoice, app(BuildInvoice::class)->creditNote($invoice));
        $note = app(IssueInvoice::class)->handle($note);

        [, $xpath] = $this->validXml($note);

        $this->assertSame('FPR12', $this->value($xpath, '//FormatoTrasmissione'));
        $this->assertSame('0000000', $this->value($xpath, '//CodiceDestinatario'));
        $this->assertSame('TD04', $this->value($xpath, '//TipoDocumento'));
        $this->assertSame(0, $xpath->query('//CessionarioCommittente//IdFiscaleIVA')->length);
        $this->assertSame('RSSMRA80A01G702Z', $this->value($xpath, '//CessionarioCommittente//CodiceFiscale'));
        $this->assertSame('122.00', $this->value($xpath, '//ImportoTotaleDocumento'));
    }
}
