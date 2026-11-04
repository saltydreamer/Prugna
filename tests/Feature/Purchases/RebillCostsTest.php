<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\RebillCosts;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLineCost;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VatRate;
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

    public function test_all_unbilled_costs_of_committed_orders_are_offered(): void
    {
        PurchaseOrder::factory()->create(['customer_id' => $this->customer->id]);
        $sent = PurchaseOrder::factory()->sent()->create(['customer_id' => $this->customer->id]);
        PurchaseOrder::factory()->sent()->create(['customer_id' => Organization::factory()->create()->id]);
        [$marked, $internal] = $sent->lines()->orderBy('position')->get()->all();

        // Lines marked "rebill" come preselected, the others can be chosen too.
        $this->actingAs($this->admin)->getJson("/sales/invoices/lookup/costs?organization_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', "po-{$marked->id}")
            ->assertJsonPath('data.0.rebill', true)
            ->assertJsonPath('data.0.source.label', "Ordine {$sent->number}")
            ->assertJsonPath('data.0.amount', 220)
            ->assertJsonPath('data.1.key', "po-{$internal->id}")
            ->assertJsonPath('data.1.rebill', false);
    }

    public function test_costs_become_one_line_each_or_a_single_line_per_vat_rate(): void
    {
        $order = Order::factory()->create(['organization_id' => $this->customer->id]);
        $purchase = PurchaseOrder::factory()->sent()->create(['order_id' => $order->id, 'customer_id' => $this->customer->id]);
        $expense = Expense::factory()->create(['order_id' => $order->id, 'customer_id' => $this->customer->id]);
        $exempt = VatRate::query()->where('nature', 'N1')->first();
        $expense->lines()->create(['position' => 2, 'type' => 'item', 'description' => 'Marca da bollo', 'quantity' => 1, 'unit_price' => 16, 'vat_rate_id' => $exempt->id, 'vat_rate' => 0, 'vat_nature' => 'N1', 'total' => 16]);

        $keys = [
            'po-'.$purchase->lines()->where('rebill', true)->value('id'),   // 200 € cost
            'ex-'.$expense->lines()->where('position', 1)->value('id'),      // 100 € cost
            'ex-'.$expense->lines()->where('position', 2)->value('id'),      // 16 € exempt
        ];

        // One line per cost, markup chosen now (0% instead of the stored 10% / 20%).
        $this->actingAs($this->admin)->postJson('/sales/invoices/lookup/cost-lines', [
            'organization_id' => $this->customer->id,
            'costs' => $keys,
            'markups' => [$keys[0] => 0, $keys[1] => 0],
        ])->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.unit_price', 100)
            ->assertJsonPath('data.0.costs', [$keys[0]]);

        // A single line per VAT rate, with the list of costs.
        $this->actingAs($this->admin)->post('/sales/invoices/from-costs', [
            'organization_id' => $this->customer->id,
            'order_id' => $order->id,
            'costs' => $keys,
            'markups' => [$keys[0] => 10, $keys[1] => 0, $keys[2] => 0],
            'grouped' => true,
            'description' => 'Rimborso spese di cantiere',
            'list_details' => true,
        ])->assertRedirect();

        $invoice = Invoice::sole();
        $lines = $invoice->lines()->with('costLinks')->orderBy('position')->get();

        $this->assertSame($order->id, $invoice->order_id);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('Rimborso spese di cantiere', $lines[0]->description);
        $this->assertStringContainsString('Cavi e connettori', $lines[0]->description);
        // 220 (200 + 10%) + 100 at 22%; the exempt cost on its own line.
        $this->assertSame('320.00', $lines[0]->total);
        $this->assertSame(2, $lines[0]->costLinks->count());
        $this->assertSame('16.00', $lines[1]->total);
        $this->assertSame('N1', $lines[1]->vat_nature);

        // Charged back: nothing left to offer and the margin counts the invoiced amount.
        $this->assertSame(1, app(RebillCosts::class)->available($this->customer->id, $order)->count());
        $this->actingAs($this->admin)->get("/sales/orders/{$order->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchasing.costs', 366)
                ->where('purchasing.invoiced_costs', 336)
                // 1000 revenue + 336 charged back − 366 costs.
                ->where('purchasing.margin', 970)
                ->where('purchasing.available_count', 1));

        // The same costs can't be invoiced twice.
        $this->actingAs($this->admin)->post('/sales/invoices/from-costs', [
            'organization_id' => $this->customer->id,
            'costs' => [$keys[0]],
        ])->assertSessionHasErrors('costs');
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
                'vat_rate_id' => $cost->vat_rate_id, 'costs' => ["po-{$cost->id}"],
            ]],
        ];

        $this->actingAs($this->admin)->post('/sales/invoices', $payload)->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame('220.00', $invoice->subtotal);
        $this->assertSame($cost->id, $invoice->lines()->first()->costLinks()->value('purchase_order_line_id'));

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
        $this->assertSame(1, $invoice->lines()->has('costLinks')->count());

        // Only costs left: the order can still be invoiced for them.
        $other = PurchaseOrder::factory()->sent()->create(['order_id' => $order->id, 'customer_id' => $this->customer->id]);
        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full', 'include_costs' => true])->assertRedirect();
        $this->assertSame('220.00', Invoice::query()->latest('id')->first()->subtotal);
        $this->assertTrue(InvoiceLineCost::query()->where('purchase_order_line_id', $other->lines()->first()->id)->exists());
    }

    public function test_credit_notes_do_not_keep_the_cost_link(): void
    {
        $purchaseOrder = PurchaseOrder::factory()->sent()->create(['customer_id' => $this->customer->id]);
        $cost = $purchaseOrder->lines()->where('rebill', true)->first();

        $this->actingAs($this->admin)->post('/sales/invoices', [
            'type' => 'invoice',
            'organization_id' => $this->customer->id,
            'issue_date' => today()->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Switch', 'quantity' => 2, 'unit_price' => 110, 'vat_rate_id' => $cost->vat_rate_id, 'costs' => ["po-{$cost->id}"]]],
        ]);
        $invoice = Invoice::sole();
        app(Settings::class)->set(['company.name' => 'Test Srl', 'company.vat_number' => '01234567897', 'company.address' => 'Via Roma 1', 'company.postal_code' => '20121', 'company.city' => 'Milano']);
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/issue")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/credit-note")->assertRedirect();

        $note = Invoice::query()->where('type', 'credit_note')->sole();
        $this->assertSame(0, $note->lines()->has('costLinks')->count());
        $this->assertSame(0, PurchaseOrder::find($purchaseOrder->id)->lines()->toRebill()->count());
    }
}
