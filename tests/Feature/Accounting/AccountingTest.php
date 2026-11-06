<?php

namespace Tests\Feature\Accounting;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AccountingCategory;
use App\Models\AccountMovement;
use App\Models\BusinessLine;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FinancialAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');
        $this->admin = User::factory()->admin()->create();
        $this->bank = FinancialAccount::factory()->create(['name' => 'Fineco', 'opening_balance' => 1000, 'opening_date' => '2026-01-01']);
        app(Settings::class)->set(['fiscal.regime' => 'RF19']);
    }

    /**
     * Issued invoice with the given lines ([total, product]) and amounts.
     *
     * @param  list<array{0: float, 1: Product|null}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    private function issued(array $lines, array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create();
        $invoice->lines()->delete();

        foreach ($lines as $position => [$total, $product]) {
            $invoice->lines()->create([
                'position' => $position + 1, 'type' => 'item', 'description' => 'Servizio', 'quantity' => 1, 'unit_price' => $total,
                'discount_percent' => 0, 'vat_rate' => 0, 'vat_nature' => 'N2.2', 'total' => $total, 'product_id' => $product?->id,
            ]);
        }

        $subtotal = array_sum(array_column($lines, 0));
        $invoice->forceFill([
            'status' => InvoiceStatus::Issued, 'number' => 'FT-2026-'.str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT), 'sequence' => $invoice->id, 'year' => 2026,
            'subtotal' => $subtotal, 'vat_total' => 0, 'total' => $subtotal, 'amount_due' => $subtotal, 'withholding_amount' => 0,
            ...$attributes,
        ])->save();

        return $invoice->fresh();
    }

    private function category(string $key): AccountingCategory
    {
        return AccountingCategory::query()->where('legacy_key', $key)->sole();
    }

    public function test_the_profit_and_loss_on_a_cash_basis_with_the_flat_rate_estimate(): void
    {
        $hosting = BusinessLine::query()->create(['name' => 'Hosting']);
        $invoice = $this->issued([[800, Product::factory()->service()->create(['business_line_id' => $hosting->id])], [200, null]]);

        $this->actingAs($this->admin)
            ->post("/accounting/invoices/{$invoice->id}/collections", ['financial_account_id' => $this->bank->id, 'date' => '2026-03-10', 'amount' => 1000])
            ->assertRedirect();
        $this->assertTrue($invoice->fresh()->isPaid());

        // Software paid in April, half for the business: VAT included in the flat-rate regime.
        $expense = Expense::factory()->create();
        $expense->forceFill(['subtotal' => 100, 'vat_total' => 22, 'total' => 122, 'paid_at' => '2026-04-02', 'category_id' => $this->category('software')->id, 'business_share' => 50])->save();

        $movement = fn (float $amount, ?string $category, string $date) => AccountMovement::query()->create([
            'financial_account_id' => $this->bank->id, 'date' => $date, 'amount' => $amount, 'category_id' => $category ? $this->category($category)->id : null,
        ]);
        $movement(-50, 'bank', '2026-05-31');
        $movement(-500, 'contributions', '2026-06-16');
        $movement(-100, null, '2026-07-01');

        $this->actingAs($this->admin)->get('/accounting?year=2026')->assertInertia(fn (Assert $page) => $page
            ->component('accounting/Index')
            ->where('report.collected', 1000)
            ->where('report.costs', 111)
            ->where('report.contributions', 500)
            ->where('report.net', 389)
            ->where('report.months.2.revenue', 1000)
            ->where('report.unclassified', ['count' => 1, 'in' => 0, 'out' => 100])
            ->where('report.lines.0.name', 'Hosting')
            ->where('report.lines.0.revenue', 800)
            ->where('report.lines.1.revenue', 200)
            ->where('report.lines.1.costs', 111)
            // 1000 × 67% = 670; − 500 contributions paid = 170 × 15% = 25,50; INPS 26,07% of 670 = 174,67.
            ->where('estimate.gross_income', 670)
            ->where('estimate.tax', 25.5)
            ->where('estimate.contributions_due', 174.67)
            ->where('estimate.set_aside', 200.17));

        // 1000 opening + 1000 collected − 650 out (the expense was paid without an account).
        $this->assertSame(1350.0, $this->bank->balance());
    }

    public function test_collections_scale_to_the_taxable_and_credit_notes_close_the_invoice(): void
    {
        app(Settings::class)->set(['fiscal.regime' => 'RF01']);
        // 100 + VAT 22 − withholding 20: the customer pays 102, the revenue is 100.
        $invoice = $this->issued([[100, null]], ['vat_total' => 22, 'total' => 122, 'withholding_amount' => 20, 'amount_due' => 102]);

        $this->actingAs($this->admin)->post("/accounting/invoices/{$invoice->id}/collections", ['financial_account_id' => $this->bank->id, 'date' => '2026-02-01', 'amount' => 102]);
        $this->actingAs($this->admin)->get('/accounting?year=2026')->assertInertia(fn (Assert $page) => $page->where('report.collected', 100));

        $unpaid = $this->issued([[722, null]]);
        $this->assertSame(722.0, $unpaid->outstanding());
        $note = $this->issued([[722, null]], ['type' => InvoiceType::CreditNote, 'series' => 'NC', 'related_invoice_id' => $unpaid->id]);

        $this->assertSame(0.0, $unpaid->fresh()->outstanding());
        $this->assertSame(0.0, $note->outstanding());
        $this->actingAs($this->admin)->get('/accounting/cash')->assertInertia(fn (Assert $page) => $page->where('open.receivable_count', 0));
    }

    public function test_deleting_a_collection_reopens_the_invoice(): void
    {
        $invoice = $this->issued([[500, null]]);
        $this->actingAs($this->admin)->post("/accounting/invoices/{$invoice->id}/collections", ['financial_account_id' => $this->bank->id, 'date' => '2026-10-01', 'amount' => 300]);
        $this->assertSame(200.0, $invoice->fresh()->outstanding());

        $this->actingAs($this->admin)->delete('/accounting/movements/'.$invoice->movements()->value('id'))->assertRedirect();

        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(500.0, $invoice->fresh()->outstanding());
    }

    public function test_paying_an_expense_from_an_account_records_the_movement(): void
    {
        $expense = Expense::factory()->create();
        $total = (float) $expense->total;

        $this->actingAs($this->admin)
            ->patch("/purchases/expenses/{$expense->id}/payment", ['paid_at' => '2026-11-02', 'financial_account_id' => $this->bank->id])
            ->assertRedirect();

        $this->assertSame(-$total, (float) $expense->movements()->sole()->amount);
        $this->assertSame(round(1000 - $total, 2), $this->bank->balance());

        $this->actingAs($this->admin)->patch("/purchases/expenses/{$expense->id}/payment", ['paid_at' => null]);

        $this->assertSame(0, $expense->movements()->count());
        $this->actingAs($this->admin)->delete('/accounting/movements/0')->assertNotFound();
    }

    public function test_transfers_move_money_without_touching_the_profit_and_loss(): void
    {
        $card = FinancialAccount::factory()->create(['name' => 'Carta', 'opening_date' => '2026-01-01']);

        $this->actingAs($this->admin)->post('/accounting/transfers', [
            'from_account_id' => $this->bank->id, 'to_account_id' => $card->id, 'date' => '2026-09-01', 'amount' => 250,
        ])->assertRedirect();

        $this->assertSame(750.0, $this->bank->balance());
        $this->assertSame(250.0, $card->balance());
        $this->actingAs($this->admin)->get('/accounting?year=2026')->assertInertia(fn (Assert $page) => $page
            ->where('report.costs', 0)->where('report.unclassified.count', 0));

        $this->actingAs($this->admin)->delete('/accounting/movements/'.$card->movements()->value('id'));
        $this->assertSame(0, AccountMovement::query()->count());
    }

    public function test_dolibarr_collections_are_recovered_from_the_notes(): void
    {
        $invoice = $this->issued([[1334.56, null]], [
            'internal_notes' => "Incassi registrati in Dolibarr:\n05/11/2025 · 1.234,56\u{00A0}€ · Bonifico · 77\n10/11/2025 · 100,00 € · Contanti\n\nPagata (Dolibarr).",
        ]);

        $this->actingAs($this->admin)->post('/accounting/dolibarr-collections', ['financial_account_id' => $this->bank->id])->assertRedirect();
        $this->actingAs($this->admin)->post('/accounting/dolibarr-collections', ['financial_account_id' => $this->bank->id]);

        $this->assertSame(['2025-11-05', '2025-11-10'], $invoice->movements()->orderBy('date')->get()->map(fn ($movement) => $movement->date->toDateString())->all());
        $this->assertTrue($invoice->fresh()->isPaid());
        // Before the opening date: history for the profit and loss, not in the balance.
        $this->assertSame(1000.0, $this->bank->balance());
        $this->actingAs($this->admin)->get('/accounting?year=2025')->assertInertia(fn (Assert $page) => $page->where('report.collected', 1334.56));
    }

    public function test_categories_replace_the_fixed_ones_and_permissions_apply(): void
    {
        $software = $this->category('software');
        $this->assertSame('Software e abbonamenti', $software->name);

        $expense = Expense::factory()->create();
        $expense->forceFill(['category_id' => $software->id])->save();

        $this->actingAs($this->admin)->delete("/accounting/settings/categories/{$software->id}")->assertStatus(409);
        $this->actingAs($this->admin)->post('/accounting/settings/categories', ['name' => 'Auto', 'kind' => 'cost', 'business_share' => 50])->assertRedirect();
        $this->assertSame('50.00', AccountingCategory::query()->where('name', 'Auto')->sole()->business_share);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/accounting')->assertForbidden();
        $this->actingAs($user)->get('/accounting/cash')->assertForbidden();
        $user->givePermissionTo('accounting.view');
        $this->actingAs($user)->get('/accounting')->assertOk();
        $this->actingAs($user)->post('/accounting/movements', ['financial_account_id' => $this->bank->id, 'date' => '2026-11-01', 'direction' => 'out', 'amount' => 10])->assertForbidden();
    }
}
