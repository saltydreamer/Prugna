<?php

namespace Tests\Feature\Sales;

use App\Enums\OrderStatus;
use App\Models\Intervention;
use App\Models\Order;
use App\Models\ServiceContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderHourPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-11-06 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    public function test_an_invoiced_order_gets_its_hour_package(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Completed, 'order_date' => '2026-11-01']);
        $customer = $order->organization_id;
        $pending = Intervention::factory()->create(['organization_id' => $customer, 'duration_minutes' => 90, 'started_at' => '2026-11-03 09:00:00']);
        $before = Intervention::factory()->create(['organization_id' => $customer, 'duration_minutes' => 60, 'started_at' => '2026-10-20 09:00:00']);
        $outside = Intervention::factory()->create(['organization_id' => $customer, 'duration_minutes' => 60, 'started_at' => '2026-11-04 09:00:00', 'invoiced_outside_at' => now()]);

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/hour-packages", [
            'title' => 'Assistenza – 10 ore prepagate',
            'hours' => 10,
            'starts_on' => '2026-11-01',
            'ends_on' => null,
            'price' => 500,
            'apply_to_pending' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $contract = ServiceContract::query()->sole();
        $this->assertSame($order->id, $contract->order_id);
        $this->assertSame($customer, $contract->organization_id);
        $this->assertNull($contract->ends_on);
        $this->assertTrue($contract->isBilled());

        // The intervention still to invoice from the start date is used up; the others are untouched.
        $this->assertSame($contract->id, $pending->fresh()->contract_id);
        $this->assertSame(90, $pending->fresh()->contract_minutes);
        $this->assertNull($before->fresh()->contract_id);
        $this->assertNull($outside->fresh()->contract_id);

        // Invoiced with the order: never on its own.
        $this->actingAs($this->admin)->post("/interventions/contracts/{$contract->id}/invoice")->assertStatus(409);

        $this->actingAs($this->admin)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('hourPackages.0.number', $contract->number)
            ->where('hourPackages.0.used_minutes', 90)
            ->where('hourPackages.0.total_minutes', 600));
    }

    public function test_hour_packages_need_the_contracts_permission_and_a_live_order(): void
    {
        $order = Order::factory()->create();
        $payload = ['title' => 'Pacchetto', 'hours' => 5, 'starts_on' => '2026-11-06'];

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('orders.view');
        $this->actingAs($viewer)->post("/sales/orders/{$order->id}/hour-packages", $payload)->assertForbidden();
        $this->actingAs($viewer)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page->where('hourPackages', null));

        $order->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/hour-packages", $payload)->assertSessionHasErrors('hours');
        $this->assertSame(0, ServiceContract::query()->count());
    }
}
