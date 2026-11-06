<?php

namespace App\Actions\Estimates;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Support\Ai\OpenRouterClient;
use App\Support\Estimates\EstimateCalculator;

/**
 * The AI drafts the main advantages of the proposed solution for the customer (one per line),
 * from what is included and the financial plan; the user edits them before the report.
 */
class GenerateAdvantages
{
    public function __construct(private OpenRouterClient $ai, private EstimateCalculator $calculator) {}

    public function handle(Estimate $estimate): string
    {
        $estimate->loadMissing(['items', 'organization']);
        $totals = $this->calculator->summarize($estimate);

        $included = $estimate->items
            ->groupBy(fn (EstimateItem $item) => $item->category ?? 'Altro')
            ->map(fn ($items, string $category) => $category.': '.$items->map(fn (EstimateItem $item) => rtrim(rtrim(number_format((float) $item->quantity, 3, ',', ''), '0'), ',').' × '.$item->description)->implode('; '))
            ->implode("\n");

        $text = implode("\n", array_filter([
            "Soluzione: {$estimate->title}",
            $estimate->organization ? "Cliente: {$estimate->organization->name}" : null,
            $estimate->intro ? "Descrizione: {$estimate->intro}" : null,
            "Cosa è incluso:\n{$included}",
            sprintf('Piano: contratto di %d mesi, anticipo %s €, canone mensile %s €.', $totals['months'], number_format($totals['upfront'], 2, ',', '.'), number_format($totals['monthly'], 2, ',', '.')),
        ]));

        $data = $this->ai->structured(<<<'TXT'
            Scrivi in italiano i principali vantaggi, per il cliente, della soluzione proposta da un'azienda di servizi informatici.
            Da 4 a 6 vantaggi, una frase ciascuno (massimo 25 parole), concreti e basati solo sui dati forniti:
            componenti inclusi, canone mensile fisso sulla durata indicata, assenza o presenza dell'anticipo, prevedibilità dei costi.
            Non inventare servizi, garanzie, sconti o caratteristiche tecniche che non sono nei dati. Tono professionale, niente punti esclamativi.
            TXT, 'advantages', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['advantages'],
            'properties' => ['advantages' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ], $text)['data'];

        return collect($data['advantages'] ?? [])->map(fn ($line) => trim((string) $line))->filter()->take(8)->implode("\n");
    }
}
