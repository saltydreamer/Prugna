<?php

namespace Tests\Feature\Sales;

use App\Actions\Invoices\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        app(Settings::class)->set([
            'company.name' => 'Officina Digitale Srl',
            'company.vat_number' => '01234567897',
            'company.address' => 'Via Roma 1',
            'company.postal_code' => '20121',
            'company.city' => 'Milano',
            'company.province' => 'MI',
            'company.iban' => 'IT60X0542811101000000123456',
        ]);
    }

    private function order(): Order
    {
        return Order::factory()->create(['organization_id' => Organization::factory()->withDetails(1, 1)->create()->id]);
    }

    /**
     * Rate 22% of the default VAT rate (seeded).
     */
    private function rate(): VatRate
    {
        return VatRate::default();
    }

    public function test_an_order_is_invoiced_in_one_click_and_then_has_nothing_left(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full'])->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame('1000.00', $invoice->subtotal);
        $this->assertSame('220.00', $invoice->vat_total);
        $this->assertSame('1220.00', $invoice->amount_due);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(100.0, $order->fresh()->billingSummary()['percent']);

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full'])->assertSessionHasErrors('selection');
    }

    public function test_partial_invoices_track_the_remaining_quantities(): void
    {
        $order = $this->order();
        $installation = $order->lines()->where('description', 'Installazione')->first();

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", [
            'mode' => 'selection',
            'selection' => [$installation->id => 4],
        ])->assertRedirect();

        $this->assertSame(6.0, $installation->fresh()->remainingQuantity());
        $this->assertSame('200.00', Invoice::sole()->subtotal);

        $invoice = Invoice::sole();
        $line = $invoice->lines()->first();

        // Can't invoice more than ordered.
        $this->actingAs($this->admin)->put("/sales/invoices/{$invoice->id}", $this->payloadFrom($invoice, [
            ['order_line_id' => $line->order_line_id, 'type' => 'item', 'description' => $line->description, 'quantity' => 11, 'unit_price' => 50, 'vat_rate_id' => $line->vat_rate_id],
        ]))->assertSessionHasErrors('lines.0.quantity');

        // Deleting the draft gives the quantities back.
        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertRedirect("/sales/orders/{$order->id}");
        $this->assertSame(10.0, $installation->fresh()->remainingQuantity());
    }

    public function test_deposit_and_balance(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'deposit', 'percent' => 30])->assertRedirect();
        $deposit = Invoice::sole();
        $this->assertSame(InvoiceType::Deposit, $deposit->type);
        $this->assertSame('300.00', $deposit->subtotal);

        // A draft deposit is not deducted yet.
        $this->actingAs($this->admin)->post("/sales/invoices/{$deposit->id}/issue")->assertRedirect();
        $this->assertSame('FT-'.today()->year.'-0001', $deposit->fresh()->number);

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full'])->assertRedirect();
        $balance = Invoice::query()->where('type', InvoiceType::Invoice->value)->sole();

        $this->assertSame('700.00', $balance->subtotal);
        $this->assertSame('854.00', $balance->total);
        $this->assertTrue($balance->lines()->where('system', InvoiceLine::SYSTEM_DEPOSIT_DEDUCTION)->where('deducts_invoice_id', $deposit->id)->exists());
        $this->assertSame(0, $order->fresh()->openDeposits()->count());
    }

    public function test_deposit_from_an_accepted_quote_by_amount(): void
    {
        $quote = Quote::factory()->create(['organization_id' => Organization::factory()->withDetails(1, 1)->create()->id]);
        $quote->decide(true);

        $this->actingAs($this->admin)->post("/sales/quotes/{$quote->id}/invoice", ['mode' => 'deposit', 'amount' => 2000])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)->post("/sales/quotes/{$quote->id}/invoice", ['mode' => 'deposit', 'amount' => 500])->assertRedirect();
        $this->assertSame('500.00', Invoice::sole()->subtotal);
        $this->assertSame($quote->id, Invoice::sole()->quote_id);
    }

    public function test_issuing_numbers_by_series_and_checks_the_data(): void
    {
        $first = Invoice::factory()->create();
        $second = Invoice::factory()->create();
        $year = today()->year;

        $this->actingAs($this->admin)->post("/sales/invoices/{$first->id}/issue")->assertRedirect();
        $this->actingAs($this->admin)->post("/sales/invoices/{$second->id}/issue")->assertRedirect();

        $this->assertSame("FT-{$year}-0001", $first->fresh()->number);
        $this->assertSame("FT-{$year}-0002", $second->fresh()->number);
        // "Bonifico 30 gg d.f." is the default payment term.
        $this->assertSame(today()->addDays(30)->toDateString(), $first->fresh()->due_date->toDateString());

        // Credit note: own series, gives back nothing to edit on the invoice.
        $this->actingAs($this->admin)->post("/sales/invoices/{$first->id}/credit-note")->assertRedirect();
        $note = Invoice::query()->where('type', InvoiceType::CreditNote->value)->sole();
        $this->assertSame($first->id, $note->related_invoice_id);
        $this->assertSame($first->total, $note->total);

        $this->actingAs($this->admin)->post("/sales/invoices/{$note->id}/issue")->assertRedirect();
        $this->assertSame("NC-{$year}-0001", $note->fresh()->number);

        // Issued documents are final (deletable only when last and never sent to the SDI: not the case here).
        $this->actingAs($this->admin)->delete("/sales/invoices/{$first->id}")->assertStatus(409);
        $this->actingAs($this->admin)->put("/sales/invoices/{$first->id}", $this->payloadFrom($first))->assertForbidden();
    }

    public function test_issue_is_blocked_when_data_is_missing(): void
    {
        app(Settings::class)->set(['company.vat_number' => null]);
        $customer = Organization::factory()->create(['vat_number' => null, 'tax_code' => null]);
        $invoice = Invoice::factory()->create(['organization_id' => $customer->id]);

        $this->actingAs($this->admin)->get("/sales/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page->component('sales/invoices/Show')->has('problems.company')->has('problems.customer'));

        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/issue")->assertSessionHasErrors(['company', 'customer']);
        $this->assertTrue($invoice->fresh()->isDraft());
    }

    public function test_professional_with_pension_fund_and_withholding(): void
    {
        app(Settings::class)->set([
            'fiscal.withholding_enabled' => true,
            'fiscal.withholding_rate' => 20,
            'fiscal.pension_enabled' => true,
            'fiscal.pension_type' => 'TC22',
            'fiscal.pension_rate' => 4,
        ]);

        $customer = Organization::factory()->withDetails(1, 0)->create(['type' => OrganizationType::Company]);

        $this->actingAs($this->admin)->post('/sales/invoices', [
            'type' => 'invoice',
            'organization_id' => $customer->id,
            'issue_date' => today()->toDateString(),
            'apply_withholding' => true,
            'apply_pension' => true,
            'lines' => [['type' => 'item', 'description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate_id' => $this->rate()->id]],
        ])->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame('1000.00', $invoice->subtotal);
        $this->assertSame('40.00', $invoice->pension_amount);
        $this->assertSame('228.80', $invoice->vat_total);
        $this->assertSame('1268.80', $invoice->total);
        $this->assertSame('200.00', $invoice->withholding_amount);
        $this->assertSame('1068.80', $invoice->amount_due);
    }

    public function test_flat_rate_regime_adds_the_stamp_duty(): void
    {
        app(Settings::class)->set(['fiscal.regime' => 'RF19']);
        $order = $this->order();

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full'])->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame('RF19', $invoice->regime);
        $this->assertSame('0.00', $invoice->vat_total);
        $this->assertSame('2.00', $invoice->stamp_duty_amount);
        $this->assertSame('1002.00', $invoice->total);
        $this->assertTrue($invoice->lines()->where('system', InvoiceLine::SYSTEM_STAMP_DUTY)->exists());
        $this->assertSame([], app(IssueInvoice::class)->problems($invoice));
    }

    public function test_split_payment_customers_pay_only_the_taxable(): void
    {
        $customer = Organization::factory()->withDetails(1, 0)->create(['type' => OrganizationType::PublicBody, 'sdi_code' => 'UFABCD', 'split_payment' => true]);
        $invoice = Invoice::factory()->create(['organization_id' => $customer->id]);

        $this->assertTrue($invoice->split_payment);
        $this->assertSame('480.00', $invoice->subtotal);
        $this->assertSame('585.60', $invoice->total);
        $this->assertSame('480.00', $invoice->amount_due);
    }

    public function test_invoices_require_their_permissions(): void
    {
        $user = User::factory()->withRole(Role::User)->create();
        $invoice = Invoice::factory()->create();

        $this->actingAs($user)->get('/sales/invoices')->assertForbidden();
        $this->actingAs($user)->get("/sales/invoices/{$invoice->id}/pdf")->assertForbidden();
    }

    public function test_pdf_and_xml_downloads(): void
    {
        $invoice = Invoice::factory()->create();

        $this->actingAs($this->admin)->get("/sales/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin)->get("/sales/invoices/{$invoice->id}/xml")->assertStatus(409);

        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/issue");

        $this->actingAs($this->admin)->get("/sales/invoices/{$invoice->id}/xml")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="IT01234567897_'.str_pad(strtoupper(base_convert((string) $invoice->id, 10, 36)), 5, '0', STR_PAD_LEFT).'.xml"');
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    private function payloadFrom(Invoice $invoice, ?array $lines = null): array
    {
        return [
            'organization_id' => $invoice->organization_id,
            'issue_date' => $invoice->issue_date->toDateString(),
            'lines' => $lines ?? $invoice->lines->map(fn (InvoiceLine $line) => $line->only(['type', 'description', 'quantity', 'unit_price', 'vat_rate_id']))->all(),
        ];
    }
}
