<?php

namespace Tests\Feature\Settings;

use App\Models\AiCall;
use App\Models\User;
use App\Support\Ai\AiException;
use App\Support\Ai\OpenRouterClient;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiCostsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
        app(Settings::class)->set(['ai.enabled' => true, 'ai.api_key' => 'sk-or-test', 'ai.model' => 'anthropic/claude-test', 'ai.privacy' => 'no_training']);
    }

    /**
     * @param  array<string, mixed>|string  $content  Data the model answers with, or raw text.
     * @return array<string, mixed>
     */
    private function completion(array|string $content, float $cost): array
    {
        return [
            'model' => 'anthropic/claude-test',
            'choices' => [['message' => ['role' => 'assistant', 'content' => is_string($content) ? $content : json_encode($content)]]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'cost' => $cost],
        ];
    }

    public function test_every_openrouter_call_is_logged_with_its_feature_and_cost(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models*' => Http::response(['data' => []]),
            'openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push($this->completion(['name' => 'Router', 'price' => 99], 0.002))
                ->push($this->completion(['name' => 'Router', 'price' => 99], 0.004))
                ->push($this->completion('non è JSON', 0.001))
                ->push(['error' => ['message' => 'Provider down']], 502),
        ]);
        $client = app(OpenRouterClient::class);

        $this->actingAs($this->admin);
        $client->structured('Leggi il prezzo', 'product_price', ['type' => 'object'], 'Pagina del prodotto');
        $client->extract('Leggi il prezzo', 'product_price', ['type' => 'object'], 'png', 'image/png', 'screenshot');

        // A reply that can't be read is still paid: logged with its cost and the error.
        foreach ([1, 2] as $attempt) {
            try {
                $client->structured('Scrivi i vantaggi', 'advantages', ['type' => 'object'], 'Proposta');
                $this->fail('An exception was expected.');
            } catch (AiException) {
            }
        }

        $calls = AiCall::query()->orderBy('id')->get();
        $this->assertSame(['product_link', 'product_screenshot', 'advantages', 'advantages'], $calls->pluck('feature')->all());
        $this->assertSame([true, true, false, false], $calls->pluck('success')->all());
        $this->assertSame('0.004000', $calls[1]->cost);
        $this->assertSame(1200, $calls[0]->prompt_tokens + $calls[0]->completion_tokens);
        $this->assertSame('0.001000', $calls[2]->cost);
        $this->assertNotNull($calls[2]->error);
        $this->assertNull($calls[3]->cost);
        $this->assertSame($this->admin->id, $calls[0]->user_id);
    }

    public function test_document_readings_are_logged_and_the_page_sums_them_up(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models*' => Http::response(['data' => []]),
            'openrouter.ai/api/v1/chat/completions' => Http::response($this->completion([
                'name' => 'Elettroforniture Nord Srl', 'type' => 'company', 'vat_number' => null, 'tax_code' => null, 'sdi_code' => null, 'pec' => null,
                'email' => null, 'phone' => null, 'website' => null, 'address' => null, 'postal_code' => null, 'city' => null, 'province' => null, 'country' => 'IT',
                'contact' => null, 'warnings' => [],
            ], 0.0123)),
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'Prugna', 'usage' => 12.5, 'limit' => 50, 'limit_remaining' => 37.5]]),
        ]);
        AiCall::create(['feature' => 'expense', 'model' => 'anthropic/claude-test', 'cost' => 0.5, 'created_at' => '2026-10-15 09:00:00']);

        $this->actingAs($this->admin)->post('/documents/read', ['kind' => 'supplier', 'file' => UploadedFile::fake()->image('biglietto.jpg')])->assertRedirect();
        $this->assertSame('organization', AiCall::query()->latest('id')->value('feature'));

        $this->actingAs($this->admin)->get('/settings/ai-costs')->assertInertia(fn (Assert $page) => $page
            ->component('settings/AiCosts')
            ->where('summary.month_total', 0.0123)
            ->where('summary.month_calls', 1)
            ->where('summary.previous_total', 0.5)
            ->where('summary.year_total', 0.5123)
            ->where('byFeature.0.label', 'Lettura di clienti e fornitori')
            ->where('byUser.0.name', $this->admin->name)
            ->has('months', 12)
            ->where('months.11.total', 0.0123)
            ->where('months.10.total', 0.5));

        $this->actingAs($this->admin)->get('/settings/ai-costs?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('summary.month_total', 0.5)->where('byUser.0.name', 'Sistema'));
        $this->actingAs($this->admin)->getJson('/settings/ai-costs/key')->assertOk()->assertJsonPath('usage', 12.5)->assertJsonPath('limit_remaining', 37.5);

        $this->actingAs(User::factory()->create())->get('/settings/ai-costs')->assertForbidden();
    }
}
