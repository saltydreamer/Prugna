<?php

namespace Tests\Feature\Sales;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function acceptedQuote(): Quote
    {
        $quote = Quote::factory()->create();
        $quote->decide(true, 'Cliente');

        return $quote;
    }

    public function test_an_accepted_quote_becomes_an_order_once(): void
    {
        $admin = $this->admin();
        $quote = $this->acceptedQuote();

        $this->actingAs($admin)->post("/sales/quotes/{$quote->id}/order")
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success');

        $order = Order::sole();
        $year = today()->year;

        $this->assertSame("ORD-{$year}-0001", $order->number);
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame($quote->id, $order->quote_id);
        // The optional line not chosen by the customer is left out: 10 × 80 + 200 − 10%.
        $this->assertSame(3, $order->lines()->count());
        $this->assertSame('980.00', $order->subtotal);

        $this->actingAs($admin)->post("/sales/quotes/{$quote->id}/order")->assertRedirect("/sales/orders/{$order->id}");
        $this->assertSame(1, Order::count());
    }

    public function test_only_accepted_quotes_become_orders(): void
    {
        $quote = Quote::factory()->sent()->create();

        $this->actingAs($this->admin())->post("/sales/quotes/{$quote->id}/order")->assertStatus(409);
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
    }

    public function test_orders_require_their_permissions(): void
    {
        $order = Order::factory()->create();
        $user = User::factory()->withRole(Role::User)->create();

        $this->actingAs($user)->get('/sales/orders')->assertForbidden();
        $this->actingAs($user)->get("/sales/orders/{$order->id}")->assertForbidden();
    }

    public function test_index_lists_active_orders_with_invoiced_percentage(): void
    {
        $open = Order::factory()->create();
        Order::factory()->create(['status' => OrderStatus::Completed]);
        $open->lines()->first()->forceFill(['quantity_invoiced' => 5])->save();

        $this->actingAs($this->admin())->get('/sales/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $open->id)
                // 5 h × 50 € of 1000 €.
                ->where('orders.data.0.invoiced_percent', 25)
                ->where('counts.active', 1)
                ->where('counts.to_invoice', 2));
    }

    public function test_orders_are_listed_newest_first_by_default(): void
    {
        $older = Order::factory()->create(['order_date' => '2026-09-01']);
        $newer = Order::factory()->create(['order_date' => '2026-10-15']);
        $oldest = Order::factory()->create(['order_date' => '2026-03-20']);

        $this->actingAs($this->admin())->get('/sales/orders')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort_by', 'order_date')
                ->where('orders.data', fn ($orders) => collect($orders)->pluck('id')->all() === [$newer->id, $older->id, $oldest->id]));
    }

    public function test_a_manual_order_can_be_created_and_edited_until_invoiced(): void
    {
        $admin = $this->admin();
        $organization = Organization::factory()->create();
        $rate = VatRate::default();

        $payload = [
            'organization_id' => $organization->id,
            'title' => 'Ordine telefonico',
            'order_date' => today()->toDateString(),
            'delivery_date' => today()->addWeek()->toDateString(),
            'customer_reference' => 'PO-77',
            'lines' => [
                ['type' => 'item', 'description' => 'Cavo di rete', 'quantity' => 10, 'unit_price' => 3.5, 'vat_rate_id' => $rate->id],
            ],
        ];

        $this->actingAs($admin)->post('/sales/orders', $payload)->assertRedirect();
        $order = Order::sole();
        $this->assertSame('35.00', $order->subtotal);
        $this->assertSame($admin->id, $order->owner_id);

        $payload['lines'][0]['quantity'] = 20;
        $this->actingAs($admin)->put("/sales/orders/{$order->id}", $payload)->assertRedirect();
        $this->assertSame('70.00', $order->fresh()->subtotal);

        // Once invoiced the lines are frozen: only the header changes.
        Invoice::factory()->create(['order_id' => $order->id, 'organization_id' => $organization->id]);
        $payload['lines'][0]['quantity'] = 1;
        $payload['delivery_date'] = today()->addWeeks(2)->toDateString();

        $this->actingAs($admin)->put("/sales/orders/{$order->id}", $payload)->assertRedirect();
        $this->assertSame('70.00', $order->fresh()->subtotal);
        $this->assertSame(today()->addWeeks(2)->toDateString(), $order->fresh()->delivery_date->toDateString());
    }

    public function test_status_changes_and_deletion_rules(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create();

        $this->actingAs($admin)->patch("/sales/orders/{$order->id}/status", ['status' => 'completed'])->assertRedirect();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        Invoice::factory()->create(['order_id' => $order->id, 'organization_id' => $order->organization_id]);
        $this->actingAs($admin)->delete("/sales/orders/{$order->id}")->assertForbidden();

        $other = Order::factory()->create();
        $this->actingAs($admin)->delete("/sales/orders/{$other->id}")->assertRedirect('/sales/orders');
        $this->assertModelMissing($other);
    }

    public function test_order_confirmation_pdf(): void
    {
        $order = Order::factory()->create();

        $response = $this->actingAs($this->admin())->get("/sales/orders/{$order->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
