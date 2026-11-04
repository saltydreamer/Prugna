<?php

namespace App\Console\Commands;

use App\Models\AiExtraction;
use App\Support\Ai\OpenRouterClient;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only report of the latest AI readings: outcome, model, size, tokens, errors and how many
 * fields were filled. Document contents are never printed.
 */
#[Signature('ai:diagnose {--limit=10 : Number of readings}')]
#[Description('Show the latest AI document readings (no document contents)')]
class AiDiagnoseCommand extends Command
{
    public function handle(Settings $settings, OpenRouterClient $client): int
    {
        $model = (string) $settings->get('ai.model');
        $this->line('Configured: '.($client->isConfigured() ? 'yes' : 'no').' · model: '.($model ?: '—').' · privacy: '.$settings->get('ai.privacy'));
        $this->line('PHP upload_max_filesize='.ini_get('upload_max_filesize').' post_max_size='.ini_get('post_max_size').' max_execution_time='.ini_get('max_execution_time'));

        try {
            $info = collect($client->models())->firstWhere('id', $model);
            $this->line('Model in catalog: '.($info ? 'yes · reads PDF natively: '.($info['reads_pdf'] ? 'yes' : 'no') : 'no'));
        } catch (Throwable $exception) {
            $this->line('Model catalog unavailable: '.$exception->getMessage());
        }

        $this->newLine();

        foreach (AiExtraction::query()->latest('id')->limit((int) $this->option('limit'))->get() as $extraction) {
            $result = $extraction->result ?? [];
            $filled = collect($result)->except(['warnings', 'lines', 'supplier', 'contact'])->filter(fn ($value) => $value !== null && $value !== '')->count();

            $this->line(sprintf(
                '#%d %s · %s/%s · %s %s KB · %s · %s · %ss · tokens %s+%s · $%s',
                $extraction->id,
                $extraction->created_at?->format('Y-m-d H:i'),
                $extraction->kind,
                $extraction->source,
                $extraction->mime_type,
                round(($extraction->file_size ?? 0) / 1024),
                $extraction->model ?? '—',
                $extraction->status,
                round(($extraction->duration_ms ?? 0) / 1000, 1),
                $extraction->prompt_tokens ?? '?',
                $extraction->completion_tokens ?? '?',
                $extraction->cost ?? '?',
            ));

            if ($extraction->error) {
                $this->line('   error: '.mb_strimwidth($extraction->error, 0, 600, '…'));
            }

            if ($extraction->succeeded()) {
                $this->line(sprintf(
                    '   filled fields: %d · supplier name: %s · lines: %d · warnings: %s',
                    $filled,
                    filled($result['supplier']['name'] ?? $result['name'] ?? null) ? 'yes' : 'no',
                    count($result['lines'] ?? []),
                    mb_strimwidth(implode(' | ', (array) ($result['warnings'] ?? [])), 0, 300, '…') ?: '—',
                ));
            }
        }

        return self::SUCCESS;
    }
}
