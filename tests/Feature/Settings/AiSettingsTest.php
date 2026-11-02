<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_key_is_stored_encrypted_and_never_shown(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/ai', ['enabled' => true, 'api_key' => 'sk-or-secret', 'model' => 'google/gemini-test', 'privacy' => 'zdr'])
            ->assertRedirect();

        $this->assertSame('sk-or-secret', app(Settings::class)->get('ai.api_key'));
        $this->assertStringNotContainsString('sk-or-secret', (string) Setting::query()->where('key', 'ai.api_key')->value('value'));

        $this->actingAs($admin)->get('/settings?tab=ai')
            ->assertInertia(fn (Assert $page) => $page
                ->where('ai.has_api_key', true)
                ->where('ai.model', 'google/gemini-test')
                ->missing('ai.api_key'));

        // Saving without a key keeps the stored one.
        $this->actingAs($admin)->put('/settings/ai', ['enabled' => true, 'api_key' => '', 'model' => 'other/model', 'privacy' => 'no_training']);
        $this->assertSame('sk-or-secret', app(Settings::class)->get('ai.api_key'));
    }

    public function test_only_models_reading_images_with_structured_output_are_listed(): void
    {
        $admin = User::factory()->admin()->create();
        app(Settings::class)->set(['ai.api_key' => 'sk-or-secret']);

        Http::fake(['openrouter.ai/api/v1/models*' => Http::response(['data' => [
            ['id' => 'a/vision-json', 'name' => 'Vision JSON', 'architecture' => ['input_modalities' => ['text', 'image']], 'supported_parameters' => ['structured_outputs'], 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002']],
            ['id' => 'b/text-only', 'name' => 'Text', 'architecture' => ['input_modalities' => ['text']], 'supported_parameters' => ['structured_outputs'], 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'c/no-json', 'name' => 'No JSON', 'architecture' => ['input_modalities' => ['image']], 'supported_parameters' => ['temperature'], 'pricing' => ['prompt' => '0', 'completion' => '0']],
        ]])]);

        $this->actingAs($admin)->getJson('/settings/ai/models?refresh=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'a/vision-json')
            ->assertJsonPath('data.0.prompt_price', 1)
            ->assertJsonPath('data.0.reads_pdf', false);
    }

    public function test_a_sample_document_can_be_tried_with_a_model(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        app(Settings::class)->set(['ai.api_key' => 'sk-or-secret', 'ai.model' => 'a/vision-json']);

        Http::fake([
            'openrouter.ai/api/v1/models*' => Http::response(['data' => []]),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'model' => 'other/model',
                'choices' => [['message' => ['content' => "```json\n{\"name\": \"Prova\"}\n```"]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0001],
            ]),
        ]);

        $this->actingAs($admin)->post('/settings/ai/test', [
            'file' => UploadedFile::fake()->create('visura.pdf', 50, 'application/pdf'),
            'kind' => 'organization',
            'model' => 'other/model',
        ])->assertOk()->assertJsonPath('result.name', 'Prova')->assertJsonPath('model', 'other/model');

        // PDFs go as "file" parts, parsed by OpenRouter when the model doesn't read them natively.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat/completions')
            && $request['messages'][1]['content'][0]['type'] === 'file'
            && $request['plugins'][0]['pdf']['engine'] === 'mistral-ocr');
    }
}
