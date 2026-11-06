<?php

namespace Tests\Feature\Sales;

use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoicePaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issued(float $amountDue, ?string $dueDate, float $paid = 0, array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create();
        $invoice->forceFill([
            'status' => InvoiceStatus::Issued, 'number' => 'FT-'.$invoice->id, 'sequence' => $invoice->id, 'year' => 2026,
            'amount_due' => $amountDue, 'due_date' => $dueDate, 'paid_amount' => $paid, 'paid_at' => $paid >= $amountDue && $paid > 0 ? '2026-10-15' : null,
            ...$attributes,
        ])->save();

        return $invoice->fresh();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function listed(array $query): Collection
    {
        $items = collect();

        $this->actingAs($this->admin)->get('/sales/invoices?'.http_build_query($query))
            ->assertInertia(fn (Assert $page) => $page->where('invoices.data', function ($data) use ($items): bool {
                $items->push(...collect($data)->all());

                return true;
            }));

        return $items->keyBy('id');
    }

    public function test_the_list_shows_whether_each_invoice_is_paid_and_filters_by_payment(): void
    {
        $unpaid = $this->issued(500, '2026-12-01');
        $overdue = $this->issued(300, '2026-10-01');
        $paid = $this->issued(400, '2026-10-01', 400);
        $partial = $this->issued(200, '2026-12-01', 50);
        $dueToday = $this->issued(100, '2026-11-06');
        $credited = $this->issued(722, '2026-10-01');
        $this->issued(722, null, 0, ['type' => InvoiceType::CreditNote, 'series' => 'NC', 'related_invoice_id' => $credited->id]);
        $lastYear = $this->issued(80, '2025-12-31', 0, ['issue_date' => '2025-12-01', 'year' => 2025]);
        $draft = Invoice::factory()->create();

        $all = $this->listed(['year' => 2026]);
        $this->assertSame('unpaid', $all[$unpaid->id]['payment_status']);
        $this->assertSame('overdue', $all[$overdue->id]['payment_status']);
        $this->assertSame('paid', $all[$paid->id]['payment_status']);
        $this->assertSame('2026-10-15', $all[$paid->id]['paid_at']);
        $this->assertSame('partial', $all[$partial->id]['payment_status']);
        $this->assertEquals(150, $all[$partial->id]['outstanding']);
        // Due today is not overdue yet.
        $this->assertSame('unpaid', $all[$dueToday->id]['payment_status']);
        $this->assertSame('credited', $all[$credited->id]['payment_status']);
        $this->assertNull($all[$draft->id]['payment_status']);
        $this->assertSame('Pagata', InvoicePaymentStatus::Paid->label());

        // Still to collect, whatever the year.
        $this->assertEqualsCanonicalizing([$unpaid->id, $overdue->id, $partial->id, $dueToday->id, $lastYear->id], $this->listed(['year' => 'all', 'payment' => 'unpaid'])->keys()->all());
        $this->assertEqualsCanonicalizing([$overdue->id, $lastYear->id], $this->listed(['year' => 'all', 'payment' => 'overdue'])->keys()->all());
        $this->assertEqualsCanonicalizing([$paid->id, $credited->id], $this->listed(['year' => 2026, 'payment' => 'paid'])->keys()->all());

        $this->actingAs($this->admin)->get('/sales/invoices?year=2026')->assertInertia(fn (Assert $page) => $page
            ->where('summary.to_collect', 1130)
            ->where('summary.to_collect_count', 5)
            ->where('summary.overdue', 380)
            ->where('summary.overdue_count', 2)
            ->where('filters.year', 2026));

        $this->actingAs($this->admin)->get('/sales/invoices?payment=late')->assertSessionHasErrors('payment');
    }
}
