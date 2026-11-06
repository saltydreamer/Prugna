<?php

namespace Tests\Feature\Sales;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Estimates\EstimateCalculator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');
        $this->admin = User::factory()->admin()->create();
        app(Settings::class)->set(['fiscal.regime' => 'RF19']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'organization_id' => Organization::factory()->create()->id,
            'title' => 'Infrastruttura server e rete',
            'months' => 36,
            'markup_percent' => 30,
            'category_markups' => ['Rete' => 20],
            'upfront' => 600,
            'items' => [
                ['category' => 'Calcolo', 'description' => 'Server tower', 'quantity' => 2, 'unit_cost' => 1000],
                ['category' => 'Rete', 'description' => 'Switch 24 porte', 'quantity' => 1, 'unit_cost' => 500, 'url' => 'https://shop.example.com/switch'],
            ],
            ...$overrides,
        ];
    }

    public function test_items_are_priced_by_category_and_spread_over_the_months(): void
    {
        $this->actingAs($this->admin)->post('/sales/estimates', $this->payload())->assertRedirect();

        $estimate = Estimate::query()->with('items')->sole();
        $this->assertSame('PS-2026-0001', $estimate->number);
        $totals = app(EstimateCalculator::class)->summarize($estimate);

        $this->assertSame(['Calcolo', 'Rete'], array_column($totals['categories'], 'name'));
        $this->assertSame([2600.0, 600.0], array_column($totals['categories'], 'price'));
        $this->assertSame(2500.0, $totals['cost']);
        $this->assertSame(3200.0, $totals['price']);
        $this->assertSame(700.0, $totals['margin']);
        // (3200 − 600) / 36
        $this->assertSame(72.22, $totals['monthly']);
        $this->assertSame(3199.92, $totals['contract_total']);

        $this->actingAs($this->admin)->get("/sales/estimates/{$estimate->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_ai_reads_name_and_price_from_the_product_page(): void
    {
        app(Settings::class)->set(['ai.enabled' => true, 'ai.api_key' => 'sk-or-test', 'ai.model' => 'anthropic/claude-test']);
        Http::fake([
            '93.184.216.34/*' => Http::response('<html><head><title>Switch XS-24</title><script type="application/ld+json">{"@type":"Product","name":"Switch XS-24","offers":{"price":"122.00","priceCurrency":"EUR"}}</script></head><body>Switch 24 porte gigabit, 122,00 € IVA esclusa</body></html>'),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'model' => 'anthropic/claude-test',
                'choices' => [['message' => ['content' => json_encode(['found' => true, 'name' => 'Switch XS-24 24 porte', 'price' => 122, 'vat_included' => false, 'note' => ''])]]],
            ]),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/sales/estimates/read-link', ['url' => 'https://93.184.216.34/switch'])->assertOk();

        // Flat-rate regime: VAT is a cost, 122 + 22%.
        $this->assertSame('Switch XS-24 24 porte', $response->json('data.description'));
        $this->assertEquals(148.84, $response->json('data.unit_cost'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'openrouter.ai') && str_contains($request['messages'][1]['content'], '"price":122'));
    }

    public function test_the_ai_reads_name_and_price_from_a_screenshot(): void
    {
        app(Settings::class)->set(['ai.enabled' => true, 'ai.api_key' => 'sk-or-test', 'ai.model' => 'anthropic/claude-test']);
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'model' => 'anthropic/claude-test',
            'choices' => [['message' => ['content' => json_encode(['found' => true, 'name' => 'Monitor 27" QHD', 'price' => 249.9, 'vat_included' => true, 'note' => 'Prezzo Amazon Business'])]]],
        ])]);

        $response = $this->actingAs($this->admin)->post('/sales/estimates/read-image', [
            'image' => UploadedFile::fake()->image('screenshot.png', 1600, 900),
            'url' => 'https://www.amazon.it/dp/B0TEST',
        ], ['Accept' => 'application/json'])->assertOk();

        // Flat-rate regime: the VAT included stays in the cost.
        $this->assertSame('Monitor 27" QHD', $response->json('data.description'));
        $this->assertEquals(249.9, $response->json('data.unit_cost'));
        $this->assertSame('https://www.amazon.it/dp/B0TEST', $response->json('data.url'));
        $this->assertStringStartsWith('Letto dallo screenshot', $response->json('data.note'));
        Http::assertSent(fn ($request) => str_starts_with($request['messages'][1]['content'][0]['image_url']['url'], 'data:image/png;base64,'));

        // Without the AI there is nothing to read the image with.
        app(Settings::class)->set(['ai.enabled' => false]);
        $this->actingAs($this->admin)->post('/sales/estimates/read-image', ['image' => UploadedFile::fake()->image('screenshot.png')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');
        $this->actingAs($this->admin)->post('/sales/estimates/read-image', ['image' => UploadedFile::fake()->create('page.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');
    }

    public function test_amazon_links_suggest_the_screenshot_without_downloading(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->postJson('/sales/estimates/read-link', ['url' => 'https://www.amazon.it/dp/B0TEST'])
            ->assertJsonValidationErrors(['url' => 'Amazon non permette la lettura automatica delle sue pagine: usa «Screenshot» (cattura la parte con nome e prezzo) o inserisci il costo a mano.']);
        $this->actingAs($this->admin)->postJson('/sales/estimates/read-link', ['url' => 'https://amzn.eu/d/abc'])->assertJsonValidationErrors('url');
        Http::assertNothingSent();
    }

    public function test_only_public_addresses_are_read(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->postJson('/sales/estimates/read-link', ['url' => 'http://127.0.0.1/admin'])->assertJsonValidationErrors(['url' => 'Si possono leggere solo siti pubblici.']);
        $this->actingAs($this->admin)->postJson('/sales/estimates/read-link', ['url' => 'http://192.168.1.10/'])->assertJsonValidationErrors('url');
        Http::assertNothingSent();
    }

    public function test_the_ai_drafts_the_advantages(): void
    {
        app(Settings::class)->set(['ai.enabled' => true, 'ai.api_key' => 'sk-or-test', 'ai.model' => 'anthropic/claude-test']);
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['advantages' => ['Canone fisso per 36 mesi', 'Hardware professionale incluso']])]]],
        ])]);
        $this->actingAs($this->admin)->post('/sales/estimates', $this->payload());
        $estimate = Estimate::query()->sole();

        $this->actingAs($this->admin)->postJson("/sales/estimates/{$estimate->id}/advantages")
            ->assertOk()
            ->assertJsonPath('data', "Canone fisso per 36 mesi\nHardware professionale incluso");
    }

    public function test_accepting_creates_the_service_contract_and_the_upfront_invoice(): void
    {
        $this->actingAs($this->admin)->post('/sales/estimates', $this->payload(['starts_on' => '2026-12-01']));
        $estimate = Estimate::query()->sole();

        $this->actingAs($this->admin)->post("/sales/estimates/{$estimate->id}/accept")->assertRedirect();

        $subscription = Subscription::query()->with('lines')->sole();
        $this->assertSame($estimate->organization_id, $subscription->organization_id);
        $this->assertSame('2026-12-01', $subscription->starts_on->toDateString());
        $this->assertSame('2029-11-30', $subscription->ends_on->toDateString());
        $this->assertSame(72.22, $subscription->amount());
        // The upfront invoice (plus the stamp duty charged back, as on every flat-rate invoice over 77,47 €).
        $this->assertSame('600.00', Invoice::query()->sole()->lines()->whereNull('system')->sole()->total);
        $this->assertSame($subscription->id, $estimate->fresh()->subscription_id);

        $this->actingAs($this->admin)->put("/sales/estimates/{$estimate->id}", $this->payload())->assertStatus(409);
        $this->actingAs($this->admin)->post("/sales/estimates/{$estimate->id}/accept")->assertStatus(409);
    }

    public function test_the_estimator_needs_its_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/sales/estimates')->assertForbidden();
    }
}
