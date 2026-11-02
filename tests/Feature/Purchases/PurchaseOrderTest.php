<?php

namespace Tests\Feature\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Mail\DocumentMail;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_a_supplier_order_is_numbered_with_costs_and_rebill_prices(): void
    {
        $supplier = Organization::factory()->supplier()->create();
        $customer = Organization::factory()->create();
        $rate = VatRate::default();

        $this->actingAs($this->admin)->post('/purchases/orders', [
            'supplier_id' => $supplier->id,
            'customer_id' => $customer->id,
            'order_date' => today()->toDateString(),
            'expected_date' => today()->addWeek()->toDateString(),
            'lines' => [
                ['type' => 'item', 'description' => 'Router', 'quantity' => 1, 'unit_price' => 300, 'vat_rate_id' => $rate->id, 'rebill' => true, 'markup_percent' => 20],
                ['type' => 'item', 'description' => 'Trasporto', 'quantity' => 1, 'unit_price' => 15, 'vat_rate_id' => $rate->id, 'rebill' => false],
            ],
        ])->assertRedirect();

        $purchaseOrder = PurchaseOrder::sole();

        $this->assertSame('OF-'.today()->year.'-0001', $purchaseOrder->number);
        $this->assertSame(PurchaseOrderStatus::Draft, $purchaseOrder->status);
        $this->assertSame('315.00', $purchaseOrder->subtotal);
        $this->assertSame(360.0, $purchaseOrder->lines()->first()->rebillUnitPrice());
    }

    public function test_costs_without_a_customer_are_never_rebilled(): void
    {
        $supplier = Organization::factory()->supplier()->create();

        $this->actingAs($this->admin)->post('/purchases/orders', [
            'supplier_id' => $supplier->id,
            'order_date' => today()->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Carta', 'quantity' => 1, 'unit_price' => 20, 'vat_rate_id' => VatRate::default()->id, 'rebill' => true]],
        ])->assertRedirect();

        $this->assertFalse(PurchaseOrder::sole()->lines()->first()->rebill);
    }

    public function test_create_page_is_prefilled_from_a_customer_order_at_cost(): void
    {
        $product = Product::factory()->create(['cost' => 42.5]);
        $order = Order::factory()->create();
        $order->lines()->first()->forceFill(['product_id' => $product->id])->save();

        $this->actingAs($this->admin)->get("/purchases/orders/create?order={$order->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchases/orders/Edit')
                ->where('defaults.order_id', $order->id)
                ->where('defaults.customer_id', $order->organization_id)
                ->where('defaults.lines.0.unit_price', 42.5)
                ->where('defaults.lines.0.rebill', false));
    }

    public function test_the_customer_follows_the_linked_order(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin)->post('/purchases/orders', [
            'supplier_id' => Organization::factory()->supplier()->create()->id,
            'customer_id' => Organization::factory()->create()->id,
            'order_id' => $order->id,
            'order_date' => today()->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Cavi', 'quantity' => 1, 'unit_price' => 20, 'vat_rate_id' => VatRate::default()->id]],
        ])->assertRedirect();

        $this->assertSame($order->organization_id, PurchaseOrder::sole()->customer_id);
    }

    public function test_purchases_require_their_permissions(): void
    {
        $user = User::factory()->withRole(Role::User)->create();
        $purchaseOrder = PurchaseOrder::factory()->create();

        $this->actingAs($user)->get('/purchases/orders')->assertForbidden();
        $this->actingAs($user)->get("/purchases/orders/{$purchaseOrder->id}/pdf")->assertForbidden();
    }

    public function test_index_show_and_pdf(): void
    {
        $purchaseOrder = PurchaseOrder::factory()->create();

        $this->actingAs($this->admin)->get('/purchases/orders')
            ->assertInertia(fn (Assert $page) => $page->component('purchases/orders/Index')->has('purchaseOrders.data', 1)->where('counts.open', 1));
        $this->actingAs($this->admin)->get("/purchases/orders/{$purchaseOrder->id}")
            ->assertInertia(fn (Assert $page) => $page->component('purchases/orders/Show')->has('purchaseOrder.lines', 2)->where('purchaseOrder.lines.0.rebill_amount', 220));
        $this->actingAs($this->admin)->get("/purchases/orders/{$purchaseOrder->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_sending_by_email_marks_it_sent(): void
    {
        Mail::fake();
        app(Settings::class)->set(['mail.enabled' => true, 'mail.host' => 'smtp.example.com', 'mail.from_address' => 'ordini@example.com']);

        $purchaseOrder = PurchaseOrder::factory()->create();
        $contact = Contact::factory()->for($purchaseOrder->supplierOrganization)->create(['email' => 'vendite@fornitore.example']);
        $purchaseOrder->update(['contact_id' => $contact->id]);

        $this->actingAs($this->admin)->getJson("/purchases/orders/{$purchaseOrder->id}/compose")
            ->assertOk()
            ->assertJsonPath('to.0', 'vendite@fornitore.example');

        $this->actingAs($this->admin)->post("/purchases/orders/{$purchaseOrder->id}/send", [
            'to' => ['vendite@fornitore.example'],
            'subject' => 'Ordine',
            'body' => 'In allegato il nostro ordine.',
            'attach_pdf' => true,
        ])->assertRedirect();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('vendite@fornitore.example') && count($mail->attachments()) === 1);
        $this->assertSame(PurchaseOrderStatus::Sent, $purchaseOrder->fresh()->status);
    }

    public function test_status_and_deletion_rules(): void
    {
        $purchaseOrder = PurchaseOrder::factory()->sent()->create();

        $this->actingAs($this->admin)->delete("/purchases/orders/{$purchaseOrder->id}")->assertForbidden();

        $this->actingAs($this->admin)->patch("/purchases/orders/{$purchaseOrder->id}/status", ['status' => 'cancelled'])->assertRedirect();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $purchaseOrder->fresh()->status);

        $this->actingAs($this->admin)->delete("/purchases/orders/{$purchaseOrder->id}")->assertRedirect('/purchases/orders');
        $this->assertModelMissing($purchaseOrder);
        $this->assertSame(0, Invoice::count());
    }
}
