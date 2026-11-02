<?php

namespace Tests\Feature\Purchases;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RebillCostsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = Organization::factory()->withDetails(1, 1)->create();
    }

    public function test_only_committed_unbilled_costs_of_the_customer_are_offered(): void
    {
        PurchaseOrder::factory()->create(['customer_id' => $this->customer->id]);
        $sent = PurchaseOrder::factory()->sent()->create(['customer_id' => $this->customer->id]);
        PurchaseOrder::factory()->sent()->create(['customer_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->admin)->getJson("/sales/invoices/lookup/costs?organization_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.purchase_order.number', $sent->number)
            ->assertJsonPath('data.0.unit_price', 110)
            ->assertJsonPath('data.0.amount', 220);
    }

    public function test_a_cost_is_rebilled_once_and_freed_when_the_draft_is_deleted(): void
    {
        $purchaseOrder = PurchaseOrder::factory()->sent()->create(['customer_id' => $this->customer->id]);
        $cost = $purchaseOrder->lines()->where('rebill', true)->first();

        $payload = [
            'type' => 'invoice',
            'organization_id' => $this->customer->id,
            'issue_date' => today()->toDateString(),
            'lines' => [[
                'type' => 'item', 'description' => $cost->description, 'quantity' => 2, 'unit_price' => 110,
                'vat_rate_id' => $cost->vat_rate_id, 'purchase_order_line_id' => $cost->id,
            ]],
        ];

        $this->actingAs($this->admin)->post('/sales/invoices', $payload)->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame('220.00', $invoice->subtotal);
        $this->assertSame($cost->id, $invoice->lines()->first()->purchase_order_line_id);

        // Saving the same draft again is fine, a second invoice is not.
        $this->actingAs($this->admin)->put("/sales/invoices/{$invoice->id}", $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/sales/invoices', $payload)->assertSessionHasErrors('lines.0.description');

        // Another customer can't receive this cost.
        $this->actingAs($this->admin)->post('/sales/invoices', [...$payload, 'organization_id' => Organization::factory()->withDetails(1, 0)->create()->id])
            ->assertSessionHasErrors('lines.0.description');

        // Lines already charged back are frozen on the supplier order.
        $this->assertFalse($purchaseOrder->fresh()->isEditable());

        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}");
        $this->assertTrue($purchaseOrder->fresh()->isEditable());
        $this->assertSame(1, PurchaseOrder::query()->find($purchaseOrder->id)->lines()->toRebill()->count());
    }

    public function test_invoicing_an_order_can_include_its_costs(): void
    {
        $order = Order::factory()->create(['organization_id' => $this->customer->id]);
        PurchaseOrder::factory()->sent()->create(['order_id' => $order->id, 'customer_id' => $this->customer->id]);

        $this->actingAs($this->admin)->get("/sales/orders/{$order->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchasing.costs', 250)
                ->where('purchasing.rebill_pending_count', 1)
                ->where('purchasing.rebill_pending_amount', 220)
                // 1000 revenue − 50 absorbed cost + 20 markup.
                ->where('purchasing.margin', 970));

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full', 'include_costs' => true])->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame('1220.00', $invoice->subtotal);
        $this->assertSame(1, $invoice->lines()->whereNotNull('purchase_order_line_id')->count());

        // Only costs left: the order can still be invoiced for them.
        $other = PurchaseOrder::factory()->sent()->create(['order_id' => $order->id, 'customer_id' => $this->customer->id]);
        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full', 'include_costs' => true])->assertRedirect();
        $this->assertSame('220.00', Invoice::query()->latest('id')->first()->subtotal);
        $this->assertTrue(InvoiceLine::query()->where('purchase_order_line_id', $other->lines()->first()->id)->exists());
    }

    public function test_credit_notes_do_not_keep_the_cost_link(): void
    {
        $purchaseOrder = PurchaseOrder::factory()->sent()->create(['customer_id' => $this->customer->id]);
        $cost = $purchaseOrder->lines()->where('rebill', true)->first();

        $this->actingAs($this->admin)->post('/sales/invoices', [
            'type' => 'invoice',
            'organization_id' => $this->customer->id,
            'issue_date' => today()->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Switch', 'quantity' => 2, 'unit_price' => 110, 'vat_rate_id' => $cost->vat_rate_id, 'purchase_order_line_id' => $cost->id]],
        ]);
        $invoice = Invoice::sole();
        app(Settings::class)->set(['company.name' => 'Test Srl', 'company.vat_number' => '01234567897', 'company.address' => 'Via Roma 1', 'company.postal_code' => '20121', 'company.city' => 'Milano']);
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/issue")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/credit-note")->assertRedirect();

        $note = Invoice::query()->where('type', 'credit_note')->sole();
        $this->assertSame(0, $note->lines()->whereNotNull('purchase_order_line_id')->count());
        $this->assertSame(0, PurchaseOrder::find($purchaseOrder->id)->lines()->toRebill()->count());
    }
}
