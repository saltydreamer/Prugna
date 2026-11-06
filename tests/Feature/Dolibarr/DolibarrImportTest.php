<?php

namespace Tests\Feature\Dolibarr;

use App\Actions\Dolibarr\ImportDolibarr;
use App\Actions\Interventions\SplitIntervention;
use App\Enums\BillingPeriod;
use App\Enums\ExpenseType;
use App\Enums\InterventionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuoteLineType;
use App\Enums\QuoteStatus;
use App\Enums\SdiStatus;
use App\Enums\UnitOfMeasure;
use App\Models\Contact;
use App\Models\DolibarrLink;
use App\Models\Expense;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\ServiceContract;
use App\Models\User;
use App\Support\Dolibarr\SqlDumpReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class DolibarrImportTest extends TestCase
{
    use RefreshDatabase;

    private const BACKUP = __DIR__.'/../../Fixtures/dolibarr/backup.sql';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Same email as the Dolibarr user "admin": documents are assigned to them.
        $this->admin = User::factory()->admin()->create(['email' => 'marco@reimg.test']);
    }

    /**
     * @return array<string, mixed>
     */
    private function import(bool $simulate = false): array
    {
        return app(ImportDolibarr::class)->handle(self::BACKUP, $simulate);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, int>
     */
    private function section(array $report, string $key): array
    {
        return collect($report['sections'])->firstWhere('key', $key);
    }

    public function test_the_dump_reader_keeps_types_escapes_and_column_lists(): void
    {
        $staging = tempnam(sys_get_temp_dir(), 'dolibarr');
        $gzip = tempnam(sys_get_temp_dir(), 'dolibarr').'.gz';
        file_put_contents($gzip, gzencode((string) file_get_contents(self::BACKUP)));

        $result = app(SqlDumpReader::class)->load($gzip, $staging, ['societe', 'c_paiement', 'facturedet']);

        $this->assertSame('llx_', $result['prefix']);
        $this->assertSame(['c_paiement' => 4, 'facturedet' => 5, 'societe' => 5], $result['tables']);
        $this->assertSame(2, $result['other']['projet']);

        $pdo = new PDO('sqlite:'.$staging);
        $alfa = $pdo->query('SELECT * FROM societe WHERE rowid = 10')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame("L'azienda; \"top\"\nsecondo rigo", $alfa['note_public']);
        $this->assertNull($alfa['siren']);
        $this->assertSame(3, $alfa['fk_pays']);
        $this->assertSame('RIBA', $pdo->query('SELECT code FROM c_paiement WHERE id = 13')->fetchColumn());
        $this->assertSame('-12.50000000', $pdo->query('SELECT subprice FROM facturedet WHERE rowid = 92')->fetchColumn());

        @unlink($staging);
        @unlink($gzip);
    }

    public function test_third_parties_become_customers_and_suppliers_with_locations_and_contacts(): void
    {
        $report = $this->import();

        $this->assertSame([], $report['errors']);
        $this->assertSame('24.0.1', $report['source']['version']);
        $this->assertSame(4, $this->section($report, 'customers')['created']);
        $this->assertSame(2, $this->section($report, 'suppliers')['created']);

        $alfa = Organization::query()->where('name', 'Alfa S.r.l.')->sole();
        $this->assertSame(OrganizationRole::Customer, $alfa->role);
        $this->assertSame('01114601006', $alfa->vat_number);
        $this->assertSame('M5UXCR1', $alfa->sdi_code);
        $this->assertStringContainsString('Cliente storico', $alfa->notes);
        $this->assertSame(30, $alfa->paymentTerm->days);
        $this->assertTrue($alfa->paymentTerm->end_of_month);
        $this->assertSame(['Via Roma 1', '20100', 'Milano', 'MI', 'IT'], array_values($alfa->locations()->sole()->only(['address', 'postal_code', 'city', 'province', 'country'])));
        $this->assertSame('Bianchi', $alfa->contacts()->sole()->last_name);

        // Both customer and supplier in Dolibarr: two records, each with the contact.
        $this->assertSame(2, Organization::query()->where('name', 'Gamma & Figli')->count());
        $this->assertSame(2, Contact::query()->where('last_name', 'Verdi')->count());

        $this->assertSame('amministrazione@pec.beta.it', Organization::query()->where('name', 'Beta Forniture')->value('pec'));

        $delta = Organization::query()->where('name', 'Comune di Delta')->sole();
        $this->assertSame(OrganizationType::PublicBody, $delta->type);
        $this->assertSame('UF1234', $delta->sdi_code);

        // Closed in Dolibarr: archived.
        $rossi = Organization::withTrashed()->where('name', 'Rossi Mario')->sole();
        $this->assertTrue($rossi->trashed());
        $this->assertSame('RSSMRA80A01F205X', $rossi->tax_code);

        $this->assertSame(1, $this->section($report, 'contacts')['skipped']);
    }

    public function test_catalog_and_customer_prices(): void
    {
        $this->import();

        $mouse = Product::query()->where('code', 'MOUSE01')->sole();
        $this->assertSame('Hardware', $mouse->category->name);
        $this->assertSame(UnitOfMeasure::Piece, $mouse->unit);
        $this->assertSame('12.5000', $mouse->price);
        $this->assertSame('6.0000', $mouse->cost);
        $this->assertSame('22.00', $mouse->vatRate->rate);

        $this->assertSame(UnitOfMeasure::Hour, Product::query()->where('code', 'ASS-ORA')->sole()->unit);
        $this->assertSame(BillingPeriod::Monthly, Product::query()->where('code', 'CANONE')->sole()->billing_period);

        $alfa = Organization::query()->where('name', 'Alfa S.r.l.')->sole();
        $this->assertSame('45.0000', $alfa->customerPrices()->sole()->price);
    }

    public function test_quotes_orders_and_invoices_keep_numbers_links_and_payments(): void
    {
        $report = $this->import();

        $quote = Quote::query()->where('number', 'PR2601-0001')->sole();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertSame($this->admin->id, $quote->owner_id);
        $this->assertSame([QuoteLineType::Section, QuoteLineType::Item, QuoteLineType::Item], $quote->lines()->orderBy('position')->get()->pluck('type')->all());
        $this->assertSame('152.50', $quote->total);

        $order = Order::query()->where('number', 'CO2601-0001')->sole();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame($quote->id, $order->quote_id);

        $invoice = Invoice::query()->where('number', 'FA2601-0005')->sole();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame(['FT', 2026, 5], [$invoice->series, $invoice->year, $invoice->sequence]);
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame($quote->id, $invoice->quote_id);
        $this->assertStringContainsString('CRO123', $invoice->internal_notes);
        $this->assertStringContainsString('Pagata', $invoice->internal_notes);
        $this->assertSame(['2.000', '2.000'], $order->lines()->orderBy('position')->pluck('quantity_invoiced')->all());

        $creditNote = Invoice::query()->where('number', 'AV2602-0002')->sole();
        $this->assertSame(InvoiceType::CreditNote, $creditNote->type);
        $this->assertSame(['NC', 2026, 2], [$creditNote->series, $creditNote->year, $creditNote->sequence]);
        $this->assertSame($invoice->id, $creditNote->related_invoice_id);
        $this->assertSame('15.25', $creditNote->total);
        $this->assertSame('12.5000', $creditNote->lines()->sole()->unit_price);

        $draft = Invoice::query()->where('status', InvoiceStatus::Draft->value)->sole();
        $this->assertNull($draft->number);
        $line = $draft->lines()->sole();
        $this->assertSame("Consulenza\nAnalisi iniziale\ne report", $line->description);
        $this->assertSame('N2.2', $line->vat_nature);

        $this->assertSame(99, Invoice::query()->where('number', 'FA2512-0099')->value('sequence'));

        // Prugna's numbering continues after the last imported invoice of the year.
        $this->assertSame(['FT-'.now()->year => now()->year === 2026 ? 6 : 1, 'NC-'.now()->year => now()->year === 2026 ? 3 : 1], $report['next_numbers']);
    }

    public function test_imported_invoices_can_not_be_sent_again_to_the_sdi(): void
    {
        $this->import();

        $invoice = Invoice::query()->where('number', 'FA2601-0005')->sole();
        $this->assertTrue($invoice->isSentOutsidePrugna());
        $this->assertFalse($this->admin->can('sendToSdi', $invoice));
        $this->assertFalse(Invoice::query()->where('status', InvoiceStatus::Draft->value)->sole()->sdi_external);
    }

    public function test_an_imported_invoice_never_transmitted_can_be_marked_to_send_and_stays_so(): void
    {
        $this->import();
        $invoice = Invoice::query()->where('number', 'FA2512-0099')->sole();
        $invoice->forceFill(['customer' => ['name' => 'Vecchio nome']])->save();

        $this->actingAs($this->admin)->put("/sales/invoices/{$invoice->id}/sdi/external", ['external' => false])->assertRedirect();

        $invoice->refresh();
        $this->assertFalse($invoice->isSentOutsidePrugna());
        $this->assertTrue($this->admin->can('sendToSdi', $invoice));
        // Nothing transmitted yet: the customer data are the current ones.
        $this->assertSame('Comune di Delta', $invoice->customer['name']);

        // Importing again keeps the choice.
        $this->import();
        $this->assertFalse($invoice->fresh()->sdi_external);
    }

    public function test_an_imported_invoice_sent_from_prugna_is_not_overwritten(): void
    {
        $this->import();
        $invoice = Invoice::query()->where('number', 'FA2512-0099')->sole();
        $invoice->forceFill(['sdi_external' => false, 'notes' => 'Inviata da Prugna'])->save();
        $invoice->sdiTransmissions()->forceCreate(['environment' => 'production', 'status' => SdiStatus::Delivered]);

        $report = $this->import();

        $this->assertSame('Inviata da Prugna', $invoice->fresh()->notes);
        $this->assertSame(1, $this->section($report, 'invoices')['linked']);
    }

    public function test_purchases_expenses_contracts_and_interventions(): void
    {
        $report = $this->import();

        $purchaseOrder = PurchaseOrder::query()->where('number', 'CF2601-0001')->sole();
        $this->assertSame(PurchaseOrderStatus::Received, $purchaseOrder->status);
        $this->assertSame(Order::query()->where('number', 'CO2601-0001')->value('id'), $purchaseOrder->order_id);
        $this->assertSame('Beta Forniture', Organization::query()->find($purchaseOrder->supplier_id)->name);

        $paid = Expense::query()->where('number', 'FT/2026/55')->sole();
        $this->assertSame(ExpenseType::Invoice, $paid->type);
        $this->assertSame('2026-02-05', $paid->paid_at->toDateString());
        $this->assertSame(PaymentMethod::BankTransfer, $paid->payment_method);
        $this->assertSame('dolibarr', $paid->source);

        $open = Expense::query()->where('number', 'A-77')->sole();
        $this->assertNull($open->paid_at);
        $this->assertSame(PaymentMethod::Riba, $open->payment_method);
        $this->assertSame(OrganizationRole::Supplier, Organization::query()->find($open->supplier_id)->role);

        $contract = ServiceContract::query()->where('number', 'CT2601-0001')->sole();
        $this->assertSame('10.00', $contract->hours);
        $this->assertSame(1, $this->section($report, 'contracts')['skipped']);

        $invoiced = Intervention::query()->where('number', 'FI2601-0001')->sole();
        $this->assertSame(InterventionStatus::Invoiced, $invoiced->status());
        $this->assertSame(120, $invoiced->duration_minutes);
        $this->assertSame('2026-01-12 08:00:00', $invoiced->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Installazione postazioni', $invoiced->description);

        $covered = Intervention::query()->where('number', 'FI2601-0002')->sole();
        $this->assertSame($contract->id, $covered->contract_id);
        $this->assertSame(90, $covered->contract_minutes);
        $this->assertSame(InterventionStatus::Covered, $covered->status());

        $this->assertSame(InterventionStatus::NotBillable, Intervention::query()->where('number', 'FI2601-0003')->sole()->status());

        $warnings = collect($report['warnings'])->pluck('examples', 'message');
        $this->assertContains('Paolo Neri (tecnico)', $warnings->first(fn ($examples, $message) => str_contains($message, 'Utenti Dolibarr')));
        $this->assertContains('Progetti (2)', $warnings->first(fn ($examples, $message) => str_contains($message, 'non gestisce')));
    }

    public function test_importing_again_updates_instead_of_duplicating(): void
    {
        $this->import();
        $counts = [Organization::withTrashed()->count(), Contact::count(), Product::count(), Quote::count(), Invoice::count(), Expense::count(), Intervention::count(), DolibarrLink::count()];

        $report = $this->import();

        $this->assertSame($counts, [Organization::withTrashed()->count(), Contact::count(), Product::count(), Quote::count(), Invoice::count(), Expense::count(), Intervention::count(), DolibarrLink::count()]);
        $this->assertSame(0, $this->section($report, 'invoices')['created']);
        $this->assertSame(4, $this->section($report, 'invoices')['updated']);
        $this->assertSame(InterventionStatus::Invoiced, Intervention::query()->where('number', 'FI2601-0001')->sole()->status());
    }

    public function test_an_intervention_split_in_prugna_is_not_merged_back(): void
    {
        $this->import();
        $intervention = Intervention::query()->where('number', 'FI2601-0003')->sole();
        $intervention->forceFill(['description' => "22/01/2026 10:00 · 1 h · Controllo\n23/01/2026 15:00 · 30 min · Verifica backup"])->save();
        app(SplitIntervention::class)->handle($intervention);

        $report = $this->import();

        $this->assertSame('Controllo', $intervention->fresh()->description);
        $this->assertSame(60, $intervention->fresh()->duration_minutes);
        $this->assertSame(1, $intervention->splitParts()->count());
        $this->assertSame(1, $this->section($report, 'interventions')['linked']);
    }

    public function test_a_simulation_changes_nothing(): void
    {
        $report = $this->import(simulate: true);

        $this->assertSame(4, $this->section($report, 'invoices')['created']);
        $this->assertSame(0, Organization::withTrashed()->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DolibarrLink::count());
    }

    public function test_invoice_numbers_already_used_in_prugna_block_the_import(): void
    {
        Invoice::factory()->create(['issue_date' => '2026-01-05'])
            ->forceFill(['status' => InvoiceStatus::Issued, 'series' => 'FT', 'year' => 2026, 'sequence' => 5, 'number' => 'FT-2026-0005'])->save();

        $report = $this->import();

        $this->assertSame(['FA2601-0005 = FT-2026-0005'], $report['errors'][0]['examples']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, Organization::query()->where('name', 'Alfa S.r.l.')->count());
    }

    public function test_records_already_in_prugna_are_linked(): void
    {
        $existing = Organization::factory()->create(['name' => 'Alfa (Prugna)', 'vat_number' => '01114601006', 'role' => OrganizationRole::Customer]);
        $expense = Expense::factory()->create(['supplier_id' => Organization::factory()->create(['vat_number' => '03000000002', 'role' => OrganizationRole::Supplier])->id, 'number' => 'A-77', 'document_date' => '2026-01-25', 'source' => 'xml']);

        $report = $this->import();

        $this->assertSame(1, $this->section($report, 'customers')['linked']);
        $this->assertSame('Alfa S.r.l.', $existing->fresh()->name);
        $this->assertSame(1, Expense::query()->where('number', 'A-77')->count());
        $this->assertSame('xml', $expense->fresh()->source);
    }
}
