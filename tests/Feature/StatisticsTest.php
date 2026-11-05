<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An issued invoice with one line of the given taxable amount (the factory line replaced).
     */
    private function invoice(Organization $customer, string $date, float $amount, InvoiceType $type = InvoiceType::Invoice, InvoiceStatus $status = InvoiceStatus::Issued): Invoice
    {
        $invoice = Invoice::factory()->create(['organization_id' => $customer->id]);
        $invoice->lines()->delete();
        $invoice->lines()->forceCreate(['position' => 1, 'type' => 'item', 'description' => 'Servizio', 'quantity' => 1, 'unit_price' => $amount, 'vat_rate' => 0, 'vat_nature' => 'N2.2', 'total' => $amount]);
        $invoice->forceFill(['issue_date' => $date, 'type' => $type, 'status' => $status, 'year' => (int) substr($date, 0, 4)])->save();

        return $invoice;
    }

    public function test_revenue_by_year_month_and_customer(): void
    {
        $this->travelTo('2026-06-15 10:00:00');
        $alfa = Organization::factory()->create(['name' => 'Alfa']);
        $beta = Organization::factory()->create(['name' => 'Beta']);

        $this->invoice($alfa, '2025-03-10', 1000);
        $this->invoice($alfa, '2025-09-10', 500);
        $this->invoice($alfa, '2026-02-01', 2000);
        $this->invoice($alfa, '2026-02-20', 300, InvoiceType::CreditNote);
        $this->invoice($beta, '2026-05-05', 800);
        // Not revenue: a draft, and the stamp duty charged back to the customer.
        $this->invoice($beta, '2026-05-06', 999, status: InvoiceStatus::Draft);
        $this->invoice($beta, '2026-08-01', 100)->lines()->forceCreate(['position' => 2, 'type' => 'item', 'system' => 'stamp_duty', 'description' => 'Rimborso imposta di bollo', 'quantity' => 1, 'unit_price' => 2, 'vat_rate' => 0, 'vat_nature' => 'N1', 'total' => 2]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/statistics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statistics/Index')
                ->where('year', 2026)
                ->where('years', [
                    ['year' => 2025, 'revenue' => 1500, 'invoices' => 2, 'customers' => 1],
                    ['year' => 2026, 'revenue' => 2600, 'invoices' => 3, 'customers' => 2],
                ])
                ->where('months.current.1', 1700)
                ->where('months.current.7', 100)
                ->where('months.previous.2', 1000)
                // Same period (up to June 15th): 2026 without August, 2025 without September.
                ->where('comparison', ['current' => 2500, 'previous' => 1000, 'until' => '2026-06-15'])
                ->where('customers.0', ['id' => $alfa->id, 'name' => 'Alfa', 'revenue' => 1700, 'invoices' => 1, 'previous' => 1500, 'share' => 65.4])
                ->where('customers.1.name', 'Beta')
                ->where('customersByYear.0', ['id' => $alfa->id, 'name' => 'Alfa', 'years' => [2025 => 1500, 2026 => 1700], 'total' => 3200])
                ->where('flatRate', false));

        $this->actingAs(User::factory()->admin()->create())
            ->get('/statistics?year=2025')
            ->assertInertia(fn (Assert $page) => $page->where('year', 2025)->where('comparison.until', null)->where('comparison.current', 1500));
    }

    public function test_the_page_requires_the_permission(): void
    {
        $this->actingAs(User::factory()->withRole(Role::User)->create())->get('/statistics')->assertForbidden();
    }

    public function test_without_invoices_the_page_is_empty(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/statistics')
            ->assertInertia(fn (Assert $page) => $page->where('years', [])->where('year', null)->where('customers', []));
    }
}
