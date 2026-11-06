<?php

namespace Tests\Feature\Sales;

use App\Actions\Interventions\ApplyContract;
use App\Actions\Quotes\CreateHourPackages;
use App\Actions\Quotes\SaveQuote;
use App\Enums\QuoteStatus;
use App\Models\Intervention;
use App\Models\Quote;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuoteHourPackageTest extends TestCase
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
     * Sent quote with a 12 hours package (12 h × 60 €), an optional 5 hours package and a normal line.
     */
    private function quote(): Quote
    {
        $quote = Quote::factory()->sent()->create();
        $rate = VatRate::default()->id;

        app(SaveQuote::class)->handle($quote, ['lines' => [
            ['type' => 'item', 'description' => "Assistenza tecnica – ore prepagate\nOn site e da remoto", 'unit' => 'h', 'quantity' => 12, 'unit_price' => 60, 'vat_rate_id' => $rate, 'package_hours' => 12, 'package_months' => 12],
            ['type' => 'item', 'description' => 'Pacchetto extra', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 250, 'vat_rate_id' => $rate, 'package_hours' => 5, 'is_optional' => true],
            ['type' => 'item', 'description' => 'Switch 8 porte', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 40, 'vat_rate_id' => $rate, 'package_hours' => null],
        ]]);

        return $quote->fresh();
    }

    public function test_accepting_the_quote_creates_the_hour_contracts(): void
    {
        $quote = $this->quote();

        $this->actingAs($this->admin)->post("/sales/quotes/{$quote->id}/decide", ['accepted' => true])->assertRedirect();

        $contract = ServiceContract::query()->sole();
        $this->assertSame($quote->organization_id, $contract->organization_id);
        $this->assertSame('Assistenza tecnica – ore prepagate', $contract->title);
        $this->assertSame('12.00', $contract->hours);
        $this->assertSame('720.00', $contract->price);
        $this->assertSame('2026-11-06', $contract->starts_on->toDateString());
        $this->assertSame('2027-11-05', $contract->ends_on->toDateString());
        $this->assertSame($quote->id, $contract->quote_id);
        $this->assertTrue($contract->isBilled());

        // Invoiced with the quote: never on its own.
        $this->actingAs($this->admin)->post("/interventions/contracts/{$contract->id}/invoice")->assertStatus(409);

        // An intervention of the customer uses the hours.
        $intervention = Intervention::factory()->create(['organization_id' => $quote->organization_id, 'duration_minutes' => 90, 'started_at' => now()->subHour()]);
        app(ApplyContract::class)->handle($intervention);
        $this->assertSame($contract->id, $intervention->fresh()->contract_id);
        $this->assertSame(90, $intervention->fresh()->contract_minutes);

        $this->actingAs($this->admin)->get("/sales/quotes/{$quote->id}")
            ->assertInertia(fn (Assert $page) => $page->where('contracts.0.number', $contract->number)->where('quote.lines.0.package_hours', 12));
    }

    public function test_the_customer_accepting_online_gets_the_chosen_packages_once(): void
    {
        Notification::fake();
        $quote = $this->quote();
        $optional = $quote->lines()->where('is_optional', true)->sole();

        $this->post("/q/{$quote->public_token}/accept", ['name' => 'Giulia Verdi', 'agree' => true, 'optional_lines' => [$optional->id]])->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::Accepted, $quote->fresh()->status);
        $this->assertSame(['12.00', '5.00'], ServiceContract::query()->orderBy('id')->pluck('hours')->all());
        $this->assertNull(ServiceContract::query()->where('hours', 5)->sole()->ends_on);

        app(CreateHourPackages::class)->handle($quote->fresh());
        $this->assertSame(2, ServiceContract::count());
    }

    public function test_rejected_quotes_and_unselected_packages_create_nothing(): void
    {
        $quote = $this->quote();

        $this->actingAs($this->admin)->post("/sales/quotes/{$quote->id}/decide", ['accepted' => false]);

        $this->assertSame(0, ServiceContract::count());
    }
}
