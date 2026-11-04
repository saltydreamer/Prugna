<?php

namespace Tests\Feature\Purchases;

use App\Enums\Role;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $supplier, array $overrides = []): array
    {
        return [
            'type' => 'invoice',
            'supplier_id' => $supplier->id,
            'number' => 'A/123',
            'document_date' => today()->toDateString(),
            'payment_method' => 'bank_transfer',
            'due_date' => today()->addDays(30)->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Toner', 'quantity' => 2, 'unit_price' => 45, 'vat_rate_id' => VatRate::default()->id]],
            ...$overrides,
        ];
    }

    public function test_an_expense_is_recorded_with_attachment(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->supplier()->create();

        $this->actingAs($this->admin)->post('/purchases/expenses', [
            ...$this->payload($supplier),
            'attachment' => UploadedFile::fake()->create('fattura.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $expense = Expense::sole();
        $this->assertSame('90.00', $expense->subtotal);
        $this->assertSame('109.80', $expense->total);
        $this->assertSame($supplier->name, $expense->supplier['name']);
        $this->assertFalse($expense->isPaid());
        Storage::disk('local')->assertExists($expense->attachment_path);

        $this->actingAs($this->admin)->get("/purchases/expenses/{$expense->id}/attachment")->assertOk();

        // The same supplier document can't be recorded twice.
        $this->actingAs($this->admin)->post('/purchases/expenses', $this->payload($supplier))->assertSessionHasErrors('number');
    }

    public function test_only_suppliers_can_be_used(): void
    {
        $customer = Organization::factory()->create();

        $this->actingAs($this->admin)->post('/purchases/expenses', $this->payload($customer))->assertSessionHasErrors('supplier_id');
    }

    public function test_due_dates_and_payments(): void
    {
        $overdue = Expense::factory()->overdue()->create();
        Expense::factory()->paid()->create();
        Expense::factory()->create();

        $this->actingAs($this->admin)->get('/purchases/expenses?status=overdue')
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchases/expenses/Index')
                ->has('expenses.data', 1)
                ->where('expenses.data.0.id', $overdue->id)
                ->where('summary.overdue_count', 1)
                ->where('summary.unpaid_count', 2)
                ->where('summary.unpaid', 244));

        $this->actingAs($this->admin)->patch("/purchases/expenses/{$overdue->id}/payment", ['paid_at' => today()->toDateString(), 'payment_method' => 'bank_transfer'])
            ->assertRedirect();
        $this->assertTrue($overdue->fresh()->isPaid());
        $this->assertSame(0, Expense::query()->overdue()->count());

        $this->actingAs($this->admin)->patch("/purchases/expenses/{$overdue->id}/payment", ['paid_at' => null])->assertRedirect();
        $this->assertFalse($overdue->fresh()->isPaid());
    }

    public function test_expense_costs_are_rebilled_and_count_in_the_order_margin(): void
    {
        $customer = Organization::factory()->withDetails(1, 0)->create();
        $order = Order::factory()->create(['organization_id' => $customer->id]);
        $expense = Expense::factory()->create(['order_id' => $order->id, 'customer_id' => $customer->id]);
        $line = $expense->lines()->first();

        $this->actingAs($this->admin)->getJson("/sales/invoices/lookup/costs?organization_id={$customer->id}")
            ->assertJsonPath('data.0.key', "ex-{$line->id}")
            ->assertJsonPath('data.0.amount', 120);

        $this->actingAs($this->admin)->get("/sales/orders/{$order->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchasing.costs', 100)
                ->where('purchasing.margin', 1020)
                ->has('purchasing.expenses', 1));

        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full', 'include_costs' => true])->assertRedirect();
        $this->assertSame('1120.00', Invoice::sole()->subtotal);
        $this->assertSame($line->id, Invoice::sole()->lines()->has('costLinks')->first()->costLinks()->value('expense_line_id'));

        // Charged back: frozen and not deletable.
        $this->assertFalse($expense->fresh()->isEditable());
        $this->actingAs($this->admin)->delete("/purchases/expenses/{$expense->id}")->assertForbidden();
    }

    public function test_expenses_are_linked_to_an_order_in_bulk(): void
    {
        $order = Order::factory()->create();
        $first = Expense::factory()->create();
        $second = Expense::factory()->create();
        $first->lines()->update(['rebill' => false, 'markup_percent' => 0]);

        $this->actingAs($this->admin)->getJson('/purchases/expenses/link-options')
            ->assertOk()
            ->assertJsonPath('orders.0.value', $order->id);

        $this->actingAs($this->admin)->patch('/purchases/expenses/link', [
            'ids' => [$first->id, $second->id],
            'order_id' => $order->id,
            'rebill' => true,
            'markup_percent' => 15,
        ])->assertRedirect();

        foreach ([$first, $second] as $expense) {
            $expense->refresh();
            $this->assertSame($order->id, $expense->order_id);
            $this->assertSame($order->organization_id, $expense->customer_id);
            $this->assertTrue($expense->lines()->first()->rebill);
            $this->assertSame('15.00', $expense->lines()->first()->markup_percent);
        }

        // The costs are now offered for the order.
        $this->actingAs($this->admin)->getJson("/sales/invoices/lookup/costs?organization_id={$order->organization_id}&order_id={$order->id}")
            ->assertJsonCount(2, 'data');

        // Only to a customer, then back to an internal cost.
        $customer = Organization::factory()->create();
        $this->actingAs($this->admin)->patch('/purchases/expenses/link', ['ids' => [$first->id], 'customer_id' => $customer->id, 'rebill' => false]);
        $this->assertNull($first->fresh()->order_id);
        $this->assertSame($customer->id, $first->fresh()->customer_id);
        $this->assertFalse($first->fresh()->lines()->first()->rebill);

        $this->actingAs($this->admin)->patch('/purchases/expenses/link', ['ids' => [$first->id], 'rebill' => true]);
        $this->assertNull($first->fresh()->customer_id);
        $this->assertFalse($first->fresh()->lines()->first()->rebill);

        // A supplier is not a customer.
        $this->actingAs($this->admin)->patch('/purchases/expenses/link', ['ids' => [$first->id], 'customer_id' => Organization::factory()->supplier()->create()->id])
            ->assertSessionHasErrors('customer_id');
    }

    public function test_invoiced_expenses_cannot_be_relinked(): void
    {
        $customer = Organization::factory()->withDetails(1, 0)->create();
        $order = Order::factory()->create(['organization_id' => $customer->id]);
        $expense = Expense::factory()->create(['order_id' => $order->id, 'customer_id' => $customer->id]);
        $this->actingAs($this->admin)->post("/sales/orders/{$order->id}/invoice", ['mode' => 'full', 'include_costs' => true]);

        $this->actingAs($this->admin)->patch('/purchases/expenses/link', ['ids' => [$expense->id], 'order_id' => Order::factory()->create()->id])
            ->assertSessionHasErrors('ids');
        $this->assertSame($order->id, $expense->fresh()->order_id);
    }

    public function test_expenses_require_their_permissions(): void
    {
        $user = User::factory()->withRole(Role::User)->create();
        $expense = Expense::factory()->create();

        $this->actingAs($user)->get('/purchases/expenses')->assertForbidden();
        $this->actingAs($user)->get("/purchases/expenses/{$expense->id}/attachment")->assertForbidden();
    }
}
