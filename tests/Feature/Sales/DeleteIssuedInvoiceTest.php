<?php

namespace Tests\Feature\Sales;

use App\Actions\Invoices\DeleteIssuedInvoice;
use App\Actions\Invoices\IssueInvoice;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SdiStatus;
use App\Models\DolibarrLink;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\SdiTransmission;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeleteIssuedInvoiceTest extends TestCase
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

    private function issued(array $attributes = []): Invoice
    {
        return app(IssueInvoice::class)->handle(Invoice::factory()->create($attributes));
    }

    public function test_the_last_issued_invoice_not_sent_to_the_sdi_is_deleted_and_its_number_reused(): void
    {
        $order = Order::factory()->create(['organization_id' => Organization::factory()->withDetails(1, 1)->create()->id]);
        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full']);
        $invoice = app(IssueInvoice::class)->handle(Invoice::query()->sole());
        $this->assertSame('FT-'.now()->year.'-0001', $invoice->number);
        $this->assertGreaterThan(0, (float) $order->lines()->sum('quantity_invoiced'));

        $this->actingAs($this->admin)
            ->get("/sales/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page->where('deleteProblems', [])->where('invoice.can.delete', true));

        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertRedirect('/sales/invoices');

        $this->assertModelMissing($invoice);
        $this->assertSame(0.0, (float) $order->lines()->sum('quantity_invoiced'));
        $this->assertSame('FT-'.now()->year.'-0001', $this->issued()->number);
    }

    public function test_only_the_last_invoice_of_the_series_can_be_deleted(): void
    {
        $first = $this->issued();
        $second = $this->issued();

        $this->actingAs($this->admin)->delete("/sales/invoices/{$first->id}")->assertStatus(409);
        $this->assertModelExists($first);

        $this->actingAs($this->admin)
            ->get("/sales/invoices/{$first->id}")
            ->assertInertia(fn (Assert $page) => $page->where('deleteProblems.0', fn (string $problem) => str_contains($problem, $second->number)));
    }

    public function test_invoices_sent_to_the_sdi_can_not_be_deleted_but_failed_sends_can(): void
    {
        $invoice = $this->issued();
        $transmission = SdiTransmission::query()->forceCreate(['invoice_id' => $invoice->id, 'environment' => 'production', 'status' => SdiStatus::Error]);

        $this->assertSame([], app(DeleteIssuedInvoice::class)->problems($invoice));

        $transmission->forceFill(['status' => SdiStatus::Sent])->save();

        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertStatus(409);
        $this->assertModelExists($invoice);
    }

    public function test_sends_to_the_sdi_sandbox_do_not_count(): void
    {
        $invoice = $this->issued();
        SdiTransmission::query()->forceCreate(['invoice_id' => $invoice->id, 'environment' => 'test', 'status' => SdiStatus::Delivered]);

        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertRedirect('/sales/invoices');

        $this->assertModelMissing($invoice);
        $this->assertSame(0, SdiTransmission::count());
    }

    public function test_invoices_imported_from_dolibarr_can_not_be_deleted(): void
    {
        $invoice = $this->issued();
        DolibarrLink::query()->create(['source' => 'facture', 'source_id' => 80, 'linkable_type' => $invoice->getMorphClass(), 'linkable_id' => $invoice->id, 'reference' => $invoice->number]);

        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertStatus(409);
        $this->assertModelExists($invoice);
    }

    public function test_an_invoice_with_a_credit_note_can_not_be_deleted(): void
    {
        $invoice = $this->issued();
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/credit-note");
        app(IssueInvoice::class)->handle(Invoice::query()->where('related_invoice_id', $invoice->id)->sole());

        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertStatus(409);
        $this->assertModelExists($invoice);
    }

    public function test_deleting_an_issued_invoice_requires_the_issue_permission(): void
    {
        $invoice = $this->issued();
        $clerk = User::factory()->create();
        $clerk->givePermissionTo(Permission::InvoicesView->value, Permission::InvoicesManage->value);

        $this->actingAs($clerk)->delete("/sales/invoices/{$invoice->id}")->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::User)->create())->delete("/sales/invoices/{$invoice->id}")->assertForbidden();
        $this->assertModelExists($invoice);
    }
}
