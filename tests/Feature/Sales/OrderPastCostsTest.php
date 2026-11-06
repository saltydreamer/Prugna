<?php

namespace Tests\Feature\Sales;

use App\Actions\Purchases\RebillCosts;
use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderPastCostsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        // 10 h × 50 + 4 × 125 = 1000, invoiced before Prugna.
        $this->order = Order::factory()->create(['status' => OrderStatus::Completed]);
    }

    private function expense(array $attributes = []): Expense
    {
        $expense = Expense::factory()->create();
        $expense->forceFill(['paid_at' => '2025-06-10', ...$attributes])->save();

        return $expense->fresh();
    }

    public function test_paid_expenses_become_past_costs_of_the_order(): void
    {
        $paid = $this->expense();
        $unpaid = $this->expense(['paid_at' => null]);
        $otherOrder = $this->expense(['order_id' => Order::factory()->create()->id]);

        $candidates = $this->actingAs($this->admin)->getJson("/sales/orders/{$this->order->id}/past-costs")->assertOk()->json('data');
        $this->assertSame([$paid->id], array_column($candidates, 'id'));

        $this->actingAs($this->admin)->post("/sales/orders/{$this->order->id}/past-costs", ['expenses' => [$paid->id]])->assertRedirect();

        $paid->refresh();
        $this->assertTrue($paid->past_cost);
        $this->assertSame($this->order->organization_id, $paid->customer_id);
        $this->assertSame(0, $paid->lines()->where('rebill', true)->count());

        // In the costs and the margin, never proposed to be charged back.
        $this->assertTrue(app(RebillCosts::class)->available($this->order->organization_id, $this->order)->isEmpty());
        $this->actingAs($this->admin)->get("/sales/orders/{$this->order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('purchasing.costs', fn ($costs) => (float) $costs === (float) $paid->subtotal)
            ->where('purchasing.margin', fn ($margin) => (float) $margin === round(1000 - (float) $paid->subtotal, 2))
            ->where('purchasing.available_count', 0)
            ->where('purchasing.expenses.0.past_cost', true));

        $this->actingAs($this->admin)->post("/sales/orders/{$this->order->id}/past-costs", ['expenses' => [$unpaid->id, $otherOrder->id]])
            ->assertSessionHasErrors('expenses');
    }

    public function test_a_past_cost_can_be_taken_off_the_order(): void
    {
        $expense = $this->expense();
        $this->actingAs($this->admin)->post("/sales/orders/{$this->order->id}/past-costs", ['expenses' => [$expense->id]]);

        $this->actingAs($this->admin)->delete("/sales/orders/{$this->order->id}/past-costs/{$expense->id}")->assertRedirect();

        $expense->refresh();
        $this->assertNull($expense->order_id);
        $this->assertFalse($expense->past_cost);
    }

    public function test_assigning_past_costs_needs_the_expenses_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('orders.view');

        $this->actingAs($viewer)->post("/sales/orders/{$this->order->id}/past-costs", ['expenses' => [$this->expense()->id]])->assertForbidden();
    }
}
