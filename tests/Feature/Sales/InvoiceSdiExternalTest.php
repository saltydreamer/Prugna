<?php

namespace Tests\Feature\Sales;

use App\Actions\Invoices\IssueInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceSdiExternalTest extends TestCase
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
        ]);
    }

    public function test_an_invoice_transmitted_elsewhere_is_never_sent_from_prugna(): void
    {
        $invoice = app(IssueInvoice::class)->handle(Invoice::factory()->create());

        $this->actingAs($this->admin)->put("/sales/invoices/{$invoice->id}/sdi/external", ['external' => true])->assertRedirect();

        $this->assertTrue($invoice->fresh()->isSentOutsidePrugna());
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/sdi/send")->assertForbidden();

        // Back to "to send".
        $this->actingAs($this->admin)->put("/sales/invoices/{$invoice->id}/sdi/external", ['external' => false])->assertRedirect();
        $this->assertTrue($this->admin->can('sendToSdi', $invoice->fresh()));
    }

    public function test_before_the_first_send_the_current_customer_data_count(): void
    {
        $invoice = app(IssueInvoice::class)->handle(Invoice::factory()->create());
        // Data copied on the invoice when it was missing, completed since on the customer.
        $invoice->forceFill(['customer' => [...$invoice->customer, 'vat_number' => null, 'tax_code' => null]])->save();

        $this->assertSame([], app(IssueInvoice::class)->sendProblems($invoice->fresh()));
    }

    public function test_incomplete_data_are_listed_before_sending(): void
    {
        $invoice = app(IssueInvoice::class)->handle(Invoice::factory()->create());
        // E.g. an invoice imported for a customer whose data the issue would have refused.
        $invoice->organization->locations()->update(['address' => '']);

        $this->actingAs($this->admin)
            ->get("/sales/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page->where('sdi.problems.0', fn (string $problem) => str_contains($problem, 'indirizzo')));

        app(Settings::class)->set(['sdi.enabled' => true, 'sdi.username' => 'test@example.com', 'sdi.api_key' => 'key']);
        Http::fake();

        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/sdi/send")->assertRedirect();

        // Stopped before calling Openapi.
        Http::assertNothingSent();
        $this->assertSame(0, $invoice->sdiTransmissions()->count());
    }
}
