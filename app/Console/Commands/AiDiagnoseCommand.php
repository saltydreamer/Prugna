<?php

namespace App\Console\Commands;

use App\Actions\Documents\ExpenseDraft;
use App\Models\AiExtraction;
use App\Models\Expense;
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
    public function handle(Settings $settings, OpenRouterClient $client, ExpenseDraft $draft): int
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

            if ($extraction->succeeded() && $extraction->kind === AiExtraction::KIND_EXPENSE) {
                $this->checkDraft($extraction, $draft);
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

    /**
     * Why the prefilled expense might not save: saved already, supplier, duplicates, invalid lines.
     */
    private function checkDraft(AiExtraction $extraction, ExpenseDraft $draft): void
    {
        $saved = Expense::query()->where('ai_extraction_id', $extraction->id)->value('id');
        $defaults = $draft->fromExtraction($extraction)['defaults'];
        $duplicate = $defaults['supplier_id'] && $defaults['number']
            ? Expense::query()->where('supplier_id', $defaults['supplier_id'])->where('number', $defaults['number'])->whereYear('document_date', substr($defaults['document_date'], 0, 4))->value('id')
            : null;
        $badLines = collect($defaults['lines'])->filter(fn (array $line) => (float) $line['quantity'] <= 0 || blank($line['vat_rate_id']) || blank($line['description']))->count();

        $this->line(sprintf(
            '   expense saved: %s · supplier matched: %s · same supplier document already recorded: %s · invalid lines: %d of %d',
            $saved ? "yes (#{$saved})" : 'no',
            $defaults['supplier_id'] ? 'yes' : 'no',
            $duplicate ? "yes (#{$duplicate})" : 'no',
            $badLines,
            count($defaults['lines']),
        ));
    }
}
