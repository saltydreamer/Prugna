<?php

namespace Tests\Feature\Sales;

use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VatRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-05 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscription(array $attributes = [], float $price = 50): Subscription
    {
        return Subscription::factory()->withService($price)->create(['starts_on' => '2026-09-01', ...$attributes]);
    }

    /**
     * @return list<string>
     */
    private function due(Subscription $subscription): array
    {
        return array_map(fn (array $period) => $period['starts_on']->toDateString(), $subscription->refresh()->duePeriods(Subscription::today()));
    }

    public function test_fees_are_due_when_the_period_starts_or_ends(): void
    {
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $this->due($this->subscription()));
        $this->assertSame(['2026-09-01', '2026-10-01'], $this->due($this->subscription(['billing_mode' => Subscription::MODE_ARREARS])));
        $this->assertSame(['2026-10-01', '2026-11-01'], $this->due($this->subscription(['billed_until' => '2026-09-30'])));
        $this->assertSame(['2026-09-01', '2026-10-01'], $this->due($this->subscription(['ends_on' => '2026-10-15'])));
        $this->assertSame([], $this->due($this->subscription(['is_active' => false])));
        $this->assertSame(['2026-09-01'], $this->due($this->subscription(['billing_period' => BillingPeriod::Quarterly])));
        $this->assertSame([], $this->due($this->subscription(['billing_period' => BillingPeriod::Quarterly, 'billing_mode' => Subscription::MODE_ARREARS])));
    }

    public function test_periods_have_readable_labels(): void
    {
        $label = fn (string $from, string $to) => Subscription::periodLabel(['starts_on' => CarbonImmutable::parse($from), 'ends_on' => CarbonImmutable::parse($to)]);

        $this->assertSame('novembre 2026', $label('2026-11-01', '2026-11-30'));
        $this->assertSame('anno 2027', $label('2027-01-01', '2027-12-31'));
        $this->assertSame('dal 15/01/2026 al 14/04/2026', $label('2026-01-15', '2026-04-14'));
    }

    public function test_the_due_fees_become_one_draft_invoice_per_customer(): void
    {
        $hosting = $this->subscription([], 30);
        $customer = $hosting->organization;
        $backup = $this->subscription(['organization_id' => $customer->id, 'title' => 'Backup', 'billing_period' => BillingPeriod::Annual, 'starts_on' => '2026-01-01'], 120);
        $other = $this->subscription(['starts_on' => '2026-11-01'], 80);

        $preview = $this->actingAs($this->admin)->getJson('/sales/subscriptions/due')->assertOk()->json('data');
        $this->assertCount(2, $preview);
        $this->assertSame(['settembre 2026', 'ottobre 2026', 'novembre 2026', 'anno 2026'], array_column($preview[0]['fees'], 'period'));
        $this->assertEqualsWithDelta(210.0, $preview[0]['total'], 0.001);

        $keys = [...array_column($preview[0]['fees'], 'key'), ...array_column($preview[1]['fees'], 'key')];
        $this->actingAs($this->admin)
            ->post('/sales/subscriptions/invoices', ['date' => '2026-11-05', 'fees' => $keys])
            ->assertRedirect('/sales/invoices?status=draft');

        $invoice = Invoice::query()->where('organization_id', $customer->id)->sole();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('Canoni dei servizi', $invoice->title);
        $this->assertEqualsWithDelta(210.0, (float) $invoice->subtotal, 0.001);
        $this->assertSame(
            ['Hosting e manutenzione sito', 'Hosting sito web – canone settembre 2026', 'Hosting sito web – canone ottobre 2026', 'Hosting sito web – canone novembre 2026', 'Backup', 'Hosting sito web – canone anno 2026'],
            $invoice->lines()->orderBy('position')->pluck('description')->all(),
        );
        $this->assertSame(1, Invoice::query()->where('organization_id', $other->organization_id)->count());

        // Never twice.
        $this->assertSame([], $this->due($hosting));
        $this->assertSame([], $this->due($backup));
        $this->actingAs($this->admin)->post('/sales/subscriptions/invoices', ['date' => '2026-11-05', 'fees' => $keys]);
        $this->assertSame(2, Invoice::count());

        // Deleting the draft puts the fees back to invoice.
        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertRedirect();
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $this->due($hosting));
    }

    public function test_only_the_chosen_fees_are_invoiced(): void
    {
        $subscription = $this->subscription();

        $this->actingAs($this->admin)
            ->post('/sales/subscriptions/invoices', ['date' => '2026-11-05', 'fees' => ["{$subscription->id}:2026-11-01"]])
            ->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame($subscription->title, $invoice->title);
        $this->assertSame(['2026-09-01', '2026-10-01'], $this->due($subscription));
        $this->assertSame([$invoice->id], $subscription->periods()->pluck('invoice_id')->all());
    }

    public function test_fees_can_be_invoiced_ahead_of_time(): void
    {
        $subscription = $this->subscription(['starts_on' => '2026-11-01']);

        $preview = $this->actingAs($this->admin)->getJson("/sales/subscriptions/due?date=2026-12-01&subscription={$subscription->id}")->json('data.0.fees');

        $this->assertSame(['2026-11-01', '2026-12-01'], array_column($preview, 'starts_on'));
    }

    public function test_a_contract_is_created_with_its_services(): void
    {
        $customer = Organization::factory()->create();
        $rate = VatRate::factory()->create();

        $this->actingAs($this->admin)->post('/sales/subscriptions', [
            'organization_id' => $customer->id,
            'title' => 'Assistenza sistemistica',
            'billing_period' => 'quarterly',
            'billing_mode' => 'arrears',
            'starts_on' => '2026-10-01',
            'lines' => [['type' => 'item', 'description' => 'Canone assistenza', 'quantity' => 2, 'unit_price' => 75, 'vat_rate_id' => $rate->id]],
        ])->assertRedirect();

        $subscription = Subscription::query()->sole();
        $this->assertSame('CS-2026-0001', $subscription->number);
        $this->assertSame(150.0, $subscription->amount());

        $this->actingAs($this->admin)
            ->get("/sales/subscriptions/{$subscription->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/subscriptions/Show')
                ->where('subscription.next_period.label', 'dal 01/10/2026 al 31/12/2026')
                ->where('subscription.next_period.due_on', '2026-12-31')
                ->where('subscription.next_period.is_due', false));
    }

    public function test_start_and_period_are_frozen_once_a_fee_is_invoiced(): void
    {
        $subscription = $this->subscription();
        $this->actingAs($this->admin)->post('/sales/subscriptions/invoices', ['date' => '2026-11-05', 'fees' => ["{$subscription->id}:2026-09-01"]]);

        $this->actingAs($this->admin)->put("/sales/subscriptions/{$subscription->id}", [
            'organization_id' => Organization::factory()->create()->id,
            'title' => 'Hosting',
            'billing_period' => 'annual',
            'billing_mode' => 'advance',
            'starts_on' => '2026-01-01',
            'lines' => [['type' => 'item', 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 60, 'vat_rate_id' => VatRate::default()->id]],
        ])->assertRedirect();

        $subscription->refresh();
        $this->assertSame(BillingPeriod::Monthly, $subscription->billing_period);
        $this->assertSame('2026-09-01', $subscription->starts_on->toDateString());
        $this->assertSame(60.0, $subscription->load('lines')->amount());

        $this->actingAs($this->admin)->delete("/sales/subscriptions/{$subscription->id}")->assertForbidden();
    }

    public function test_permissions(): void
    {
        $subscription = $this->subscription();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::SubscriptionsView->value);

        $this->actingAs(User::factory()->create())->get('/sales/subscriptions')->assertForbidden();
        $this->actingAs($viewer)->get('/sales/subscriptions')->assertOk();
        $this->actingAs($viewer)->get("/sales/subscriptions/{$subscription->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->post('/sales/subscriptions/invoices', ['date' => '2026-11-05', 'fees' => ["{$subscription->id}:2026-09-01"]])->assertForbidden();

        $viewer->givePermissionTo(Permission::InvoicesManage->value);
        $this->actingAs($viewer)->post('/sales/subscriptions/invoices', ['date' => '2026-11-05', 'fees' => ["{$subscription->id}:2026-09-01"]])->assertRedirect();
        $this->assertSame(1, Invoice::count());
    }
}
