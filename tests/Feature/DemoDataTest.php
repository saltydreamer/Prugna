<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Expense;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\User;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use App\Support\Demo\DemoData;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_fills_an_empty_database_with_consistent_work(): void
    {
        $this->artisan('app:demo-seed', ['--months' => 2])->assertSuccessful();

        $this->assertSame('RF19', app(Settings::class)->get('fiscal.regime'));
        $this->assertSame(14, Organization::query()->where('role', 'customer')->count());
        $this->assertGreaterThan(20, Intervention::query()->count());
        $this->assertGreaterThan(0, Expense::query()->whereNotNull('paid_at')->count());
        $this->assertGreaterThan(0, Quote::query()->count());

        // Issued in order with unique numbers, in the flat-rate regime (no VAT).
        $invoices = Invoice::query()->where('status', InvoiceStatus::Issued->value)->orderBy('issue_date')->orderBy('sequence')->get();
        $this->assertNotEmpty($invoices);
        $this->assertSame($invoices->count(), $invoices->pluck('number')->unique()->count());
        $this->assertSame(0.0, (float) $invoices->sum('vat_total'));
        $this->assertTrue($invoices->contains(fn (Invoice $invoice) => (float) $invoice->paid_amount > 0));

        // Fiscal codes with the right check digits.
        $this->assertTrue(Validator::make(['vat' => app(Settings::class)->get('company.vat_number')], ['vat' => [new ItalianVatNumber]])->passes());
        $this->assertTrue(Validator::make(['cf' => app(Settings::class)->get('company.tax_code')], ['cf' => [new ItalianTaxCode]])->passes());

        $this->post('/login', ['email' => DemoData::EMAIL, 'password' => DemoData::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->where('email', DemoData::EMAIL)->sole());

        // Only for an empty database.
        $this->artisan('app:demo-seed', ['--months' => 1])->assertFailed();
    }

    public function test_the_login_page_offers_the_demo_account_only_on_the_demo_instance(): void
    {
        config(['app.demo' => false]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.demo', null));

        config(['app.demo' => true]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.demo.email', DemoData::EMAIL));
    }
}
