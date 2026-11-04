<?php

namespace Tests\Feature\Interventions;

use App\Enums\Role;
use App\Models\CustomerPrice;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InterventionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InterventionCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->category = InterventionCategory::create([
            'name' => 'Assistenza sistemistica',
            'remote_product_id' => Product::factory()->service()->create(['name' => 'Assistenza da remoto', 'price' => 50])->id,
            'onsite_product_id' => Product::factory()->service()->create(['name' => 'Assistenza on site', 'price' => 70])->id,
        ]);
    }

    private function configureExtras(): void
    {
        app(Settings::class)->set([
            'interventions.callout_product_id' => Product::factory()->service()->create(['name' => 'Diritto di uscita', 'price' => 30, 'unit' => 'forfait'])->id,
            'interventions.km_product_id' => Product::factory()->service()->create(['name' => 'Rimborso km', 'price' => 0.5, 'unit' => 'km'])->id,
            'interventions.travel_product_id' => Product::factory()->service()->create(['name' => 'Viaggio', 'price' => 40])->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $customer, array $overrides = []): array
    {
        return [
            'organization_id' => $customer->id,
            'category_id' => $this->category->id,
            'mode' => 'remote',
            'started_at' => now()->subDay()->setTime(9, 30)->format('Y-m-d\TH:i'),
            'duration_minutes' => 70,
            'description' => 'Ripristino backup',
            'billable' => true,
            ...$overrides,
        ];
    }

    public function test_an_intervention_is_recorded_and_numbered(): void
    {
        $customer = Organization::factory()->create();
        $material = Product::factory()->create(['name' => 'Cavo HDMI', 'price' => 12]);

        $this->actingAs($this->admin)->post('/interventions', $this->payload($customer, [
            'mode' => 'onsite',
            'callout' => true,
            'km' => 32.5,
            'travel_minutes' => 40,
            'materials' => [['product_id' => $material->id, 'description' => 'Cavo HDMI', 'unit' => 'pz', 'quantity' => 2, 'unit_price' => 12]],
        ]))->assertRedirect();

        $intervention = Intervention::sole();
        $this->assertSame('INT-'.now()->subDay()->year.'-0001', $intervention->number);
        $this->assertSame($this->admin->id, $intervention->technician_id);
        $this->assertSame(90, $intervention->billableMinutes());
        $this->assertSame(60, $intervention->billableTravelMinutes());
        $this->assertCount(1, $intervention->materials);

        // Switching to remote drops the on-site extras.
        $this->actingAs($this->admin)->put("/interventions/{$intervention->id}", $this->payload($customer, ['materials' => []]))->assertRedirect();
        $intervention->refresh();
        $this->assertFalse($intervention->callout);
        $this->assertSame(0, $intervention->travel_minutes);
        $this->assertCount(0, $intervention->materials);

        $this->actingAs($this->admin)->get("/interventions/{$intervention->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('interventions/Show')->where('intervention.status', 'to_invoice'));
    }

    public function test_time_is_rounded_up_to_the_configured_block(): void
    {
        $intervention = Intervention::factory()->make(['duration_minutes' => 40]);

        $this->assertSame(60, $intervention->billableMinutes());

        app(Settings::class)->set(['interventions.rounding_minutes' => 15]);
        $this->assertSame(45, $intervention->billableMinutes());

        app(Settings::class)->set(['interventions.rounding_minutes' => 1]);
        $this->assertSame(40, $intervention->billableMinutes());
    }

    public function test_the_timer_starts_and_stops(): void
    {
        $customer = Organization::factory()->create();

        $this->actingAs($this->admin)->post('/interventions/start', ['organization_id' => $customer->id, 'category_id' => $this->category->id, 'mode' => 'remote'])->assertRedirect();

        $intervention = Intervention::sole();
        $this->assertTrue($intervention->isRunning());

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('runningIntervention.number', $intervention->number));

        // One timer at a time.
        $this->actingAs($this->admin)->post('/interventions/start', ['organization_id' => $customer->id, 'category_id' => $this->category->id, 'mode' => 'remote'])
            ->assertSessionHasErrors('organization_id');

        $this->travel(25)->minutes();

        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/stop")->assertRedirect("/interventions/{$intervention->id}/edit");

        $this->assertSame(25, $intervention->refresh()->duration_minutes);
        $this->assertSame(30, $intervention->billableMinutes());
    }

    public function test_technicians_see_only_their_own_interventions(): void
    {
        $technician = User::factory()->withRole(Role::User)->create();
        $mine = Intervention::factory()->create(['technician_id' => $technician->id]);
        $other = Intervention::factory()->create();

        $this->actingAs($technician)->get('/interventions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('interventions/Index')->has('interventions.data', 1)->where('interventions.data.0.id', $mine->id));

        $this->actingAs($technician)->get("/interventions/{$other->id}")->assertForbidden();
        $this->actingAs($technician)->put("/interventions/{$other->id}", $this->payload($other->organization))->assertForbidden();

        // A technician can't assign the intervention to someone else.
        $this->actingAs($technician)->post('/interventions', $this->payload(Organization::factory()->create(), ['technician_id' => $this->admin->id]))->assertRedirect();
        $this->assertSame($technician->id, Intervention::latest('id')->first()->technician_id);

        $this->actingAs($this->admin)->get('/interventions')->assertInertia(fn (Assert $page) => $page->has('interventions.data', 3));
    }

    public function test_interventions_are_invoiced_one_by_one_with_on_site_extras(): void
    {
        $this->configureExtras();
        $customer = Organization::factory()->create();
        CustomerPrice::create(['organization_id' => $customer->id, 'product_id' => $this->category->remote_product_id, 'price' => 45]);

        $remote = Intervention::factory()->create(['organization_id' => $customer->id, 'duration_minutes' => 50, 'started_at' => now()->subDays(3)]);
        $onsite = Intervention::factory()->onsite()->create([
            'organization_id' => $customer->id, 'duration_minutes' => 100, 'callout' => true, 'km' => 30, 'travel_minutes' => 35,
            'started_at' => now()->subDays(2),
        ]);
        $onsite->materials()->create(['description' => 'Switch 8 porte', 'quantity' => 1, 'unit_price' => 39.9, 'unit' => 'pz']);
        Intervention::factory()->create(['organization_id' => $customer->id, 'billable' => false]);

        $this->actingAs($this->admin)->getJson("/sales/invoices/lookup/interventions?organization_id={$customer->id}")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.amount', 45)->assertJsonPath('data.1.amount', 30 + 2 * 70 + 40 + 15 + 39.9);

        $this->actingAs($this->admin)->post('/sales/invoices/from-interventions', [
            'organization_id' => $customer->id,
            'interventions' => [$remote->id, $onsite->id],
        ])->assertRedirect();

        $invoice = Invoice::sole();
        $lines = $invoice->lines()->with('interventions')->get();

        $this->assertSame(['45.0000', '70.0000', '30.0000', '40.0000', '0.5000', '39.9000'], $lines->pluck('unit_price')->all());
        $this->assertSame(['1.000', '2.000', '1.000', '1.000', '30.000', '1.000'], $lines->pluck('quantity')->all());
        $this->assertSame([$remote->id], $lines[0]->interventions->pluck('id')->all());
        $this->assertSame([$onsite->id], $lines[5]->interventions->pluck('id')->all());
        $this->assertStringContainsString($remote->number, $lines[0]->description);

        $this->assertSame('to_invoice', Intervention::factory()->make()->status()->value);
        $this->assertTrue($remote->refresh()->isInvoiced());
        $this->assertSame('invoiced', $remote->status()->value);

        // Invoiced interventions are final and can't be billed twice.
        $this->actingAs($this->admin)->put("/interventions/{$remote->id}", $this->payload($customer))->assertForbidden();
        $this->actingAs($this->admin)->post('/sales/invoices/from-interventions', ['organization_id' => $customer->id, 'interventions' => [$remote->id]])
            ->assertSessionHasErrors('interventions');

        // Deleting the draft frees them.
        $this->actingAs($this->admin)->delete("/sales/invoices/{$invoice->id}")->assertRedirect();
        $this->assertFalse($remote->refresh()->isInvoiced());
    }

    public function test_interventions_are_grouped_by_category_and_mode(): void
    {
        $customer = Organization::factory()->create();
        $first = Intervention::factory()->create(['organization_id' => $customer->id, 'duration_minutes' => 50, 'started_at' => now()->subDays(3)]);
        $second = Intervention::factory()->create(['organization_id' => $customer->id, 'duration_minutes' => 20, 'started_at' => now()->subDays(1)]);

        $this->actingAs($this->admin)->post('/sales/invoices/from-interventions', [
            'organization_id' => $customer->id,
            'interventions' => [$first->id, $second->id],
            'grouped' => true,
        ])->assertRedirect();

        $line = Invoice::sole()->lines()->with('interventions')->sole();
        $this->assertSame('1.500', $line->quantity);
        $this->assertSame('75.00', $line->total);
        $this->assertStringContainsString('1,5 h', $line->description);
        $this->assertStringContainsString($second->number, $line->description);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $line->interventions->pluck('id')->all());
    }

    public function test_missing_services_block_invoicing(): void
    {
        $customer = Organization::factory()->create();
        $intervention = Intervention::factory()->onsite()->create(['organization_id' => $customer->id, 'callout' => true]);

        $this->actingAs($this->admin)->post('/sales/invoices/from-interventions', ['organization_id' => $customer->id, 'interventions' => [$intervention->id]])
            ->assertSessionHasErrors('interventions');

        $this->assertSame(0, Invoice::count());
    }

    public function test_invoice_lines_cannot_bill_interventions_of_another_customer(): void
    {
        $customer = Organization::factory()->create();
        $intervention = Intervention::factory()->create();

        $this->actingAs($this->admin)->post('/sales/invoices', [
            'type' => 'invoice',
            'organization_id' => $customer->id,
            'issue_date' => today()->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Assistenza', 'quantity' => 1, 'unit_price' => 50, 'vat_rate_id' => $this->category->remoteProduct->vat_rate_id, 'interventions' => [$intervention->id]]],
        ])->assertSessionHasErrors('lines.0.description');
    }

    public function test_categories_create_their_services(): void
    {
        $this->actingAs($this->admin)->post('/interventions/settings/categories', [
            'name' => 'Sviluppo',
            'remote_new_price' => 60,
            'onsite_new_price' => 80,
        ])->assertRedirect();

        $category = InterventionCategory::where('name', 'Sviluppo')->sole();
        $this->assertSame('60.0000', $category->remoteProduct->price);
        $this->assertSame('h', $category->onsiteProduct->unit->value);

        $this->actingAs($this->admin)->put('/interventions/settings', ['rounding_minutes' => 15, 'callout_new_price' => 35])->assertRedirect();
        $this->assertSame(15, Intervention::rounding());
        $this->assertSame('35.0000', Product::find(app(Settings::class)->get('interventions.callout_product_id'))->price);

        // A category in use is only deactivated.
        Intervention::factory()->create(['category_id' => $this->category->id]);
        $this->actingAs($this->admin)->delete("/interventions/settings/categories/{$this->category->id}")->assertRedirect();
        $this->assertFalse($this->category->refresh()->is_active);
    }
}
