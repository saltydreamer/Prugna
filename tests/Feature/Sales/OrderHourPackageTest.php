<?php

namespace Tests\Feature\Sales;

use App\Actions\Quotes\SaveQuote;
use App\Enums\OrderStatus;
use App\Models\Intervention;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Quote;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderHourPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $package;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-11-06 10:00:00');
        $this->admin = User::factory()->admin()->create();
        $this->package = Product::factory()->service()->create([
            'name' => 'Assistenza tecnica on site e remota - Monte ore prepagato',
            'unit' => 'h',
            'price' => 45,
            'hour_package' => true,
            'package_months' => 12,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $customer, float $hours): array
    {
        $rate = VatRate::default()->id;

        return [
            'organization_id' => $customer->id,
            'title' => 'Assistenza 2027',
            'order_date' => '2026-11-06',
            'lines' => [
                ['type' => 'item', 'product_id' => $this->package->id, 'description' => $this->package->name, 'unit' => 'h', 'quantity' => $hours, 'unit_price' => 45, 'vat_rate_id' => $rate],
                ['type' => 'item', 'description' => 'Switch 8 porte', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 40, 'vat_rate_id' => $rate],
            ],
        ];
    }

    public function test_selling_the_article_in_an_order_makes_its_contract(): void
    {
        $customer = Organization::factory()->create();
        $pending = Intervention::factory()->create(['organization_id' => $customer->id, 'duration_minutes' => 90, 'started_at' => '2026-11-06 08:00:00']);

        $this->actingAs($this->admin)->post('/sales/orders', $this->payload($customer, 10))->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::sole();
        $contract = ServiceContract::query()->sole();
        $this->assertSame($order->id, $contract->order_id);
        $this->assertSame($order->lines()->first()->id, $contract->order_line_id);
        $this->assertSame('Assistenza tecnica on site e remota - Monte ore prepagato', $contract->title);
        $this->assertSame('10.00', $contract->hours);
        $this->assertSame('450.00', $contract->price);
        $this->assertSame('2026-11-06', $contract->starts_on->toDateString());
        $this->assertSame('2027-11-05', $contract->ends_on->toDateString());
        $this->assertTrue($contract->isBilled());
        $this->assertSame('10.00', $order->lines()->first()->package_hours);
        // The intervention of the day still to invoice is used up at once.
        $this->assertSame($contract->id, $pending->fresh()->contract_id);

        // Invoiced with the order: never on its own.
        $this->actingAs($this->admin)->post("/interventions/contracts/{$contract->id}/invoice")->assertStatus(409);

        // Editing the order keeps the same contract in step with the line.
        $this->actingAs($this->admin)->put("/sales/orders/{$order->id}", $this->payload($customer, 20))->assertRedirect();
        $this->assertSame($contract->id, ServiceContract::query()->sole()->id);
        $this->assertSame('20.00', $contract->fresh()->hours);
        $this->assertSame($order->lines()->first()->id, $contract->fresh()->order_line_id);

        $this->actingAs($this->admin)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('hourPackages.contracts.0.number', $contract->number)
            ->where('hourPackages.contracts.0.total_minutes', 1200)
            ->where('hourPackages.missing', 0));

        // Without the line the contract goes, unless already used.
        $withoutPackage = $this->payload($customer, 20);
        array_shift($withoutPackage['lines']);
        $pending->forceFill(['contract_id' => null, 'contract_minutes' => 0])->save();
        $this->actingAs($this->admin)->put("/sales/orders/{$order->id}", $withoutPackage)->assertRedirect();
        $this->assertSame(0, ServiceContract::query()->count());
    }

    public function test_an_accepted_quote_and_its_order_share_the_contract(): void
    {
        $quote = Quote::factory()->sent()->create();
        app(SaveQuote::class)->handle($quote, ['lines' => [
            ['type' => 'item', 'product_id' => $this->package->id, 'description' => $this->package->name, 'unit' => 'h', 'quantity' => 8, 'unit_price' => 45, 'vat_rate_id' => VatRate::default()->id],
        ]]);
        $this->assertSame('8.00', $quote->lines()->first()->package_hours);

        $this->actingAs($this->admin)->post("/sales/quotes/{$quote->id}/decide", ['accepted' => true])->assertRedirect();
        $this->actingAs($this->admin)->post("/sales/quotes/{$quote->id}/order")->assertRedirect();

        $contract = ServiceContract::query()->sole();
        $this->assertSame($quote->id, $contract->quote_id);
        $this->assertSame(Order::sole()->id, $contract->order_id);
        $this->assertSame('8.00', $contract->hours);
    }

    public function test_orders_saved_before_get_their_contracts_on_request(): void
    {
        // Imported and already invoiced: the line sells the article but no contract was made.
        $order = Order::factory()->create(['status' => OrderStatus::Completed, 'order_date' => '2026-10-01']);
        $order->lines()->first()->forceFill(['product_id' => $this->package->id, 'quantity' => 12])->save();

        $this->actingAs($this->admin)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page->where('hourPackages.missing', 1));

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('orders.view');
        $this->actingAs($viewer)->post("/sales/orders/{$order->id}/hour-packages")->assertForbidden();

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/hour-packages")->assertRedirect();
        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/hour-packages")->assertRedirect();

        $contract = ServiceContract::query()->sole();
        $this->assertSame('12.00', $contract->hours);
        $this->assertSame('2026-10-01', $contract->starts_on->toDateString());
        $this->assertSame(OrderLine::query()->where('order_id', $order->id)->orderBy('position')->value('id'), $contract->order_line_id);

        // A cancelled order stops its packages.
        $this->actingAs($this->admin)->patch("/sales/orders/{$order->id}/status", ['status' => 'cancelled'])->assertRedirect();
        $this->assertFalse($contract->fresh()->is_active);
    }

    public function test_an_hour_package_is_sold_by_the_hour(): void
    {
        $payload = [
            'type' => 'service', 'name' => 'Monte ore', 'unit' => 'pz', 'price' => 45,
            'vat_rate_id' => VatRate::default()->id, 'hour_package' => true, 'package_months' => 12, 'is_active' => true,
        ];

        $this->actingAs($this->admin)->post('/catalog/products', $payload)->assertSessionHasErrors('unit');
        $this->actingAs($this->admin)->post('/catalog/products', [...$payload, 'unit' => 'h'])->assertSessionHasNoErrors();
        $this->assertTrue(Product::query()->where('name', 'Monte ore')->sole()->hour_package);
    }
}
