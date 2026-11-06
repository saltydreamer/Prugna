<?php

namespace App\Support\Ai;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OpenRouter chat completions (https://openrouter.ai/api/v1) for reading documents:
 * images as data URLs, PDFs as "file" parts with the file-parser plugin, JSON schema responses.
 */
class OpenRouterClient
{
    public const BASE_URL = 'https://openrouter.ai/api/v1';

    /**
     * Privacy levels → provider routing preferences.
     *
     * @var array<string, string>
     */
    public const PRIVACY_LEVELS = [
        'no_training' => 'Solo fornitori che non conservano né usano i dati per addestramento',
        'zdr' => 'Solo fornitori con conservazione zero (ZDR): più riservato, meno modelli',
        'any' => 'Qualsiasi fornitore (sconsigliato per documenti aziendali)',
    ];

    private const MODELS_CACHE_KEY = 'ai.openrouter.models';

    public function __construct(private Settings $settings) {}

    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('ai.enabled')
            && filled($this->settings->get('ai.api_key'))
            && filled($this->settings->get('ai.model'));
    }

    /**
     * Models that read images and support JSON schema responses, cheapest first.
     *
     * @return list<array{id: string, name: string, prompt_price: float, completion_price: float, reads_pdf: bool, context_length: int|null}>
     */
    public function models(bool $refresh = false, ?string $apiKey = null): array
    {
        if ($refresh) {
            Cache::forget(self::MODELS_CACHE_KEY);
        }

        return Cache::remember(self::MODELS_CACHE_KEY, now()->addHours(6), function () use ($apiKey): array {
            $response = $this->request($apiKey)->timeout(20)->get(self::BASE_URL.'/models', ['input_modalities' => 'image']);
            $this->throwOnError($response);

            return collect($response->json('data', []))
                ->filter(fn (array $model) => in_array('image', $model['architecture']['input_modalities'] ?? [], true)
                    && array_intersect(['structured_outputs', 'response_format'], $model['supported_parameters'] ?? []))
                ->map(fn (array $model) => [
                    'id' => $model['id'],
                    'name' => $model['name'] ?? $model['id'],
                    // Prices are USD per token: shown per million tokens.
                    'prompt_price' => round((float) ($model['pricing']['prompt'] ?? 0) * 1_000_000, 4),
                    'completion_price' => round((float) ($model['pricing']['completion'] ?? 0) * 1_000_000, 4),
                    'reads_pdf' => in_array('file', $model['architecture']['input_modalities'] ?? [], true),
                    'context_length' => $model['context_length'] ?? null,
                ])
                ->sortBy([['prompt_price', 'asc'], ['name', 'asc']])
                ->values()
                ->all();
        });
    }

    /**
     * Reads a document and returns data matching the JSON schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string, cost: float|null, prompt_tokens: int|null, completion_tokens: int|null}
     */
    public function extract(string $instructions, string $schemaName, array $schema, string $contents, string $mimeType, string $filename, ?string $model = null): array
    {
        $model ??= (string) $this->settings->get('ai.model');
        $isPdf = $mimeType === 'application/pdf';
        $dataUrl = 'data:'.$mimeType.';base64,'.base64_encode($contents);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => [
                    $isPdf
                        ? ['type' => 'file', 'file' => ['filename' => $filename, 'file_data' => $dataUrl]]
                        : ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ['type' => 'text', 'text' => 'Estrai i dati dal documento allegato secondo lo schema.'],
                ]],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
            ],
            'provider' => $this->providerPreferences(),
            'max_tokens' => 8000,
        ];

        if ($isPdf) {
            // Models that read PDFs natively get the file as is; the others get it parsed by OpenRouter.
            $payload['plugins'] = [['id' => 'file-parser', 'pdf' => ['engine' => $this->readsPdf($model) ? 'native' : 'mistral-ocr']]];
        }

        try {
            $response = $this->request()->timeout(150)->post(self::BASE_URL.'/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new AiException(__('OpenRouter non ha risposto in tempo. Riprova tra poco o con un file più piccolo.'));
        }

        $this->throwOnError($response);

        $content = $response->json('choices.0.message.content');

        if (is_array($content)) {
            $content = collect($content)->pluck('text')->implode('');
        }

        $data = $this->decodeJson((string) $content);

        return [
            'data' => $data,
            'model' => (string) ($response->json('model') ?? $model),
            'cost' => $response->json('usage.cost') !== null ? (float) $response->json('usage.cost') : null,
            'prompt_tokens' => $response->json('usage.prompt_tokens'),
            'completion_tokens' => $response->json('usage.completion_tokens'),
        ];
    }

    /**
     * Answers from text only (e.g. a web page, or data to write about) with data matching the JSON schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string, cost: float|null, prompt_tokens: int|null, completion_tokens: int|null}
     */
    public function structured(string $instructions, string $schemaName, array $schema, string $text, ?string $model = null): array
    {
        $model ??= (string) $this->settings->get('ai.model');

        try {
            $response = $this->request()->timeout(90)->post(self::BASE_URL.'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $text],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
                ],
                'provider' => $this->providerPreferences(),
                'max_tokens' => 4000,
            ]);
        } catch (ConnectionException) {
            throw new AiException(__('OpenRouter non ha risposto in tempo. Riprova tra poco.'));
        }

        $this->throwOnError($response);

        $content = $response->json('choices.0.message.content');

        if (is_array($content)) {
            $content = collect($content)->pluck('text')->implode('');
        }

        return [
            'data' => $this->decodeJson((string) $content),
            'model' => (string) ($response->json('model') ?? $model),
            'cost' => $response->json('usage.cost') !== null ? (float) $response->json('usage.cost') : null,
            'prompt_tokens' => $response->json('usage.prompt_tokens'),
            'completion_tokens' => $response->json('usage.completion_tokens'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPreferences(): array
    {
        $preferences = ['require_parameters' => true];

        return match ($this->settings->get('ai.privacy')) {
            'any' => $preferences,
            'zdr' => [...$preferences, 'data_collection' => 'deny', 'zdr' => true],
            default => [...$preferences, 'data_collection' => 'deny'],
        };
    }

    private function readsPdf(string $model): bool
    {
        try {
            return (bool) (collect($this->models())->firstWhere('id', $model)['reads_pdf'] ?? false);
        } catch (AiException) {
            return false;
        }
    }

    private function request(?string $apiKey = null): PendingRequest
    {
        $apiKey ??= (string) $this->settings->get('ai.api_key');

        if ($apiKey === '') {
            throw new AiException(__('Inserisci la chiave API di OpenRouter in Impostazioni → AI.'));
        }

        return Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => (string) config('app.name'),
            ]);
    }

    private function throwOnError(Response $response): void
    {
        if ($response->successful() && $response->json('error') === null) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? $response->body());

        throw new AiException(match ($response->status()) {
            401 => __('La chiave API di OpenRouter non è valida.'),
            402 => __('Credito OpenRouter esaurito: ricaricalo su openrouter.ai.'),
            404 => __('Nessun fornitore disponibile per il modello scelto con queste impostazioni di privacy e formato. Prova un altro modello o un livello di privacy diverso. (:message)', ['message' => $message]),
            429 => __('Troppe richieste a OpenRouter: riprova tra poco.'),
            default => __('Errore di OpenRouter: :message', ['message' => mb_strimwidth($message, 0, 300, '…')]),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        // Some models wrap the JSON in a Markdown code block.
        $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($content)));
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new AiException(__('Il modello non ha restituito dati leggibili. Riprova o scegli un altro modello.'));
        }

        return $data;
    }
}
