<?php

namespace Tests\Feature\Sales;

use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\CustomerPrice;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $organization, array $overrides = []): array
    {
        $rate = VatRate::default();

        return [
            'organization_id' => $organization->id,
            'contact_id' => null,
            'owner_id' => null,
            'title' => 'Fornitura',
            'issue_date' => today()->toDateString(),
            'valid_until' => today()->addDays(30)->toDateString(),
            'payment_term_id' => PaymentTerm::default()->id,
            'lines' => [
                ['type' => 'section', 'description' => 'Hardware'],
                ['type' => 'item', 'description' => 'Notebook', 'unit' => 'pz', 'quantity' => 2, 'unit_price' => 500, 'discount_percent' => 10, 'vat_rate_id' => $rate->id],
                ['type' => 'item', 'description' => 'Borsa', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 30, 'vat_rate_id' => $rate->id, 'is_optional' => true, 'is_selected' => false],
                ['type' => 'text', 'description' => 'Consegna inclusa'],
            ],
            ...$overrides,
        ];
    }

    private function salesperson(): User
    {
        return User::factory()->withRole(Role::User)->create();
    }

    public function test_salespeople_see_only_their_quotes_and_supervisors_see_all(): void
    {
        $mario = $this->salesperson();
        $luigi = $this->salesperson();
        $own = Quote::factory()->create(['owner_id' => $mario->id]);
        $other = Quote::factory()->create(['owner_id' => $luigi->id]);

        $this->actingAs($mario)->get('/sales/quotes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('sales/quotes/Index')->has('quotes.data', 1)->where('quotes.data.0.id', $own->id)->where('owners', null));
        $this->actingAs($mario)->get("/sales/quotes/{$other->id}")->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get('/sales/quotes')
            ->assertInertia(fn (Assert $page) => $page->has('quotes.data', 2)->has('owners'));
    }

    public function test_create_page_proposes_validity_and_the_customer_payment_term(): void
    {
        $term = PaymentTerm::where('method', 'MP12')->firstOrFail();
        $organization = Organization::factory()->create(['payment_term_id' => $term->id]);

        $this->actingAs($this->salesperson())->get("/sales/quotes/create?organization={$organization->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/quotes/Edit')
                ->where('defaults.organization_id', $organization->id)
                ->where('defaults.payment_term_id', $term->id)
                ->where('defaults.valid_until', today()->addDays(30)->toDateString()));
    }

    public function test_quotes_are_numbered_and_totals_follow_the_italian_rules(): void
    {
        $mario = $this->salesperson();
        $organization = Organization::factory()->create(['name' => 'Acme S.r.l.']);
        $other = User::factory()->create();

        $this->actingAs($mario)->post('/sales/quotes', $this->payload($organization, ['owner_id' => $other->id]))->assertSessionHasNoErrors();
        $this->actingAs($mario)->post('/sales/quotes', $this->payload($organization))->assertSessionHasNoErrors();

        [$first, $second] = Quote::orderBy('id')->get();
        $this->assertSame(['PRV-00001', 'PRV-00002'], [$first->number, $second->number]);
        $this->assertTrue($first->owner->is($mario), 'A salesperson always owns the quotes they create.');
        $this->assertSame('Acme S.r.l.', $first->customer['name']);
        $this->assertSame(QuoteStatus::Draft, $first->status);

        // 2 × 500 − 10% = 900; the optional line (30) is excluded; VAT 22% = 198.
        $this->assertSame('900.00', $first->subtotal);
        $this->assertSame('198.00', $first->vat_total);
        $this->assertSame('1098.00', $first->total);
        $this->assertCount(4, $first->lines);
        $this->assertSame('22.00', $first->lines[1]->vat_rate);
        $this->assertSame('Bonifico bancario 30 gg d.f.', $first->payment_terms);
    }

    public function test_vat_summary_groups_rates_and_natures(): void
    {
        $organization = Organization::factory()->create();
        $ten = VatRate::where('rate', 10)->firstOrFail();
        $exempt = VatRate::where('nature', 'N4')->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())->post('/sales/quotes', $this->payload($organization, [
            'lines' => [
                ['type' => 'item', 'description' => 'A', 'quantity' => 3, 'unit_price' => 33.333, 'vat_rate_id' => VatRate::default()->id],
                ['type' => 'item', 'description' => 'B', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => $ten->id],
                ['type' => 'item', 'description' => 'C', 'quantity' => 1, 'unit_price' => 50, 'vat_rate_id' => $exempt->id],
            ],
        ]))->assertSessionHasNoErrors();

        $quote = Quote::firstOrFail();
        // 100.00 (22% → 22.00) + 100 (10% → 10.00) + 50 (N4 → 0).
        $this->assertSame('250.00', $quote->subtotal);
        $this->assertSame('32.00', $quote->vat_total);
        $this->assertSame('N4', $quote->lines[2]->vat_nature);
    }

    public function test_quotes_are_validated(): void
    {
        $organization = Organization::factory()->create();
        $foreignContact = Contact::factory()->create();

        $this->actingAs($this->salesperson())->post('/sales/quotes', $this->payload($organization, [
            'contact_id' => $foreignContact->id,
            'valid_until' => today()->subDay()->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'X', 'quantity' => 0, 'unit_price' => 10, 'vat_rate_id' => null]],
        ]))->assertSessionHasErrors(['contact_id', 'valid_until', 'lines.0.quantity', 'lines.0.vat_rate_id']);

        $this->actingAs($this->salesperson())->post('/sales/quotes', $this->payload($organization, [
            'lines' => [['type' => 'section', 'description' => 'Solo titolo']],
        ]))->assertSessionHasErrors('lines');
    }

    public function test_only_drafts_can_be_edited_or_deleted(): void
    {
        $mario = $this->salesperson();
        $quote = Quote::factory()->sent()->create(['owner_id' => $mario->id]);

        $this->actingAs($mario)->get("/sales/quotes/{$quote->id}/edit")->assertRedirect("/sales/quotes/{$quote->id}");
        $this->actingAs($mario)->put("/sales/quotes/{$quote->id}", $this->payload($quote->organization))->assertStatus(409);
        $this->actingAs($mario)->delete("/sales/quotes/{$quote->id}")->assertForbidden();

        $this->actingAs($mario)->post("/sales/quotes/{$quote->id}/reopen")->assertRedirect("/sales/quotes/{$quote->id}/edit");
        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);

        $this->actingAs($mario)->put("/sales/quotes/{$quote->id}", $this->payload($quote->organization, ['title' => 'Aggiornato']))->assertSessionHasNoErrors();
        $this->assertSame('Aggiornato', $quote->fresh()->title);

        $this->actingAs($mario)->delete("/sales/quotes/{$quote->id}")->assertRedirect('/sales/quotes');
        $this->assertModelMissing($quote);
    }

    public function test_quotes_can_be_marked_sent_decided_and_duplicated(): void
    {
        $mario = $this->salesperson();
        $quote = Quote::factory()->create(['owner_id' => $mario->id]);

        $this->actingAs($mario)->post("/sales/quotes/{$quote->id}/mark-sent")->assertRedirect();
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
        $this->assertNotNull($quote->fresh()->sent_at);

        $this->actingAs($mario)->post("/sales/quotes/{$quote->id}/decide", ['accepted' => true, 'note' => 'Confermato al telefono'])->assertRedirect();
        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertSame($mario->name, $quote->decision_name);

        // Accepted quotes are final.
        $this->actingAs($mario)->post("/sales/quotes/{$quote->id}/reopen")->assertForbidden();

        $this->actingAs($mario)->post("/sales/quotes/{$quote->id}/duplicate")->assertRedirect();
        $copy = Quote::latest('id')->firstOrFail();
        $this->assertSame('PRV-00002', $copy->number);
        $this->assertSame(QuoteStatus::Draft, $copy->status);
        $this->assertSame($quote->lines()->count(), $copy->lines()->count());
        $this->assertSame($quote->total, $copy->total);
    }

    public function test_the_product_lookup_applies_the_customer_price(): void
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->create(['name' => 'Router Wi-Fi', 'price' => 200]);
        CustomerPrice::factory()->for($organization)->for($product)->create(['discount_percent' => 25]);

        $this->actingAs($this->salesperson())
            ->getJson("/sales/quotes/lookup/products?search=router&organization_id={$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.0.price', 150)
            ->assertJsonPath('data.0.list_price', 200)
            ->assertJsonPath('data.0.price_source', 'customer_discount');
    }

    public function test_the_organization_lookup_returns_contacts_and_payment_term(): void
    {
        $term = PaymentTerm::first();
        $organization = Organization::factory()->create(['payment_term_id' => $term->id]);
        $contact = Contact::factory()->for($organization)->create();
        $location = Location::factory()->for($organization)->create(['city' => 'Bologna']);

        $this->actingAs($this->salesperson())
            ->getJson("/sales/quotes/lookup/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('payment_term_id', $term->id)
            ->assertJsonPath('contacts.0.value', $contact->id)
            ->assertJsonPath('locations.0.id', $location->id)
            ->assertJsonPath('locations.0.city', 'Bologna');
    }
}
