<?php

namespace App\Actions\Dolibarr;

use App\Enums\InterventionMode;
use App\Enums\OrganizationRole;
use App\Enums\QuoteLineType;
use App\Enums\UnitOfMeasure;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ServiceContract;
use App\Support\Dolibarr\DolibarrMapper;
use App\Support\Dolibarr\DolibarrSource as Source;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Contracts → hour contracts (only those with lines in hours: in Prugna a contract is a
 * package of hours) and interventions, with their lines in the description. Interventions
 * invoiced in Dolibarr are linked to the imported invoice, or marked as not billable.
 */
class ImportInterventions
{
    private ?int $categoryId = null;

    public function __construct(private DolibarrMapper $map, private ImportThirdParties $thirdParties) {}

    public function run(): void
    {
        $this->contracts();
        $this->interventions();
        $this->contractUsage();
    }

    private function contracts(): void
    {
        $this->map->report->section('contracts', __('Contratti monte ore'));

        foreach ($this->map->source->each('contrat') as $row) {
            /** @var Collection<int, stdClass> $contractLines */
            $contractLines = $this->map->source->children('contratdet', 'fk_contrat', (int) $row->rowid);
            $hours = $contractLines->filter(fn (stdClass $line) => $this->inHours($line))->sum(fn (stdClass $line) => (float) $line->qty);

            if ($hours <= 0) {
                $this->map->report->count('contracts', 'skipped');
                $this->map->report->warn(__('Contratti senza righe in ore: non importati (in Prugna un contratto è un monte ore).'), (string) $row->ref);

                continue;
            }

            $organization = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Customer);

            if (! $organization) {
                $this->map->report->count('contracts', 'skipped');

                continue;
            }

            $contract = $this->map->linked('contrat', (int) $row->rowid, ServiceContract::class);
            $startsOn = Source::date($row->date_contrat ?? null) ?? Source::date($row->datec ?? null) ?? now()->toImmutable();
            $endsOn = $contractLines->map(fn (stdClass $line) => Source::date($line->date_fin_validite ?? null))->filter()->max();
            $first = $contractLines->first();
            $nature = $first ? $this->map->nature($first->tva_tx ?? 0, $first->vat_src_code ?? null, null) : null;

            $attributes = [
                'year' => $startsOn->year,
                'sequence' => $this->map->sequence(ServiceContract::class, $startsOn->year, $contract),
                'number' => $this->map->number(ServiceContract::class, (string) $row->ref, $contract),
                'organization_id' => $organization->id,
                'title' => Source::line($first?->label ?? null, 255) ?? Source::line($first?->description ?? null, 255) ?? (string) $row->ref,
                'hours' => Source::amount($hours),
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'price' => Source::amount($row->total_ht ?? $contractLines->sum(fn (stdClass $line) => (float) $line->total_ht)),
                'product_id' => $this->map->linkedId('product', Source::int($first?->fk_product ?? null)),
                'vat_rate_id' => $first ? $this->map->vatRate($first->tva_tx ?? 0, $nature) : null,
                'manager_id' => $this->map->user($row->fk_commercial_suivi ?? null) ?? $this->map->user($row->fk_user_author ?? null),
                'is_active' => (int) ($row->statut ?? 0) === 1 && (! $endsOn || ! $endsOn->isPast()),
                'notes' => Source::join(Source::text($row->note_public ?? null), Source::text($row->note_private ?? null)),
            ];

            if ($contract) {
                $contract->forceFill($attributes)->save();
                $this->map->report->count('contracts', 'updated');
            } else {
                $contract = ServiceContract::query()->forceCreate([...$attributes, 'created_at' => Source::date($row->datec ?? null) ?? $startsOn]);
                $this->map->report->count('contracts', 'created');
            }

            $this->map->remember('contrat', (int) $row->rowid, $contract, $row->ref);
        }
    }

    private function inHours(stdClass $line): bool
    {
        if ($this->map->unit($line->fk_unit ?? null) === UnitOfMeasure::Hour) {
            return true;
        }

        $productId = $this->map->linkedId('product', Source::int($line->fk_product ?? null));

        return $productId !== null && Product::query()->whereKey($productId)->value('unit') === UnitOfMeasure::Hour->value;
    }

    private function interventions(): void
    {
        $this->map->report->section('interventions', __('Interventi'));
        $invoices = $this->map->source->related('facture', 'fichinter');
        $orders = $this->map->source->related('commande', 'fichinter');

        foreach ($this->map->source->each('fichinter') as $row) {
            $organization = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Customer);

            if (! $organization) {
                $this->map->report->count('interventions', 'skipped');
                $this->map->report->warn(__('Documenti senza terza parte valida: non importati.'), (string) $row->ref);

                continue;
            }

            /** @var Collection<int, stdClass> $details */
            $details = $this->map->source->children('fichinterdet', 'fk_fichinter', (int) $row->rowid)->sortBy(fn (stdClass $line) => [(string) $line->date, (int) ($line->rang ?? 0), (int) $line->rowid])->values();
            $start = $details->map(fn (stdClass $line) => Source::date($line->date ?? null))->filter()->min()
                ?? Source::date($row->dateo ?? null) ?? Source::date($row->datei ?? null) ?? Source::date($row->datec ?? null) ?? now()->toImmutable();
            $seconds = $details->sum(fn (stdClass $line) => (int) ($line->duree ?? 0)) ?: (int) ($row->duree ?? 0);
            $status = (int) $row->fk_statut;
            $invoiceIds = array_values(array_filter(array_map(fn (int $id) => $this->map->linkedId('facture', $id), $invoices[(int) $row->rowid] ?? [])));
            $intervention = $this->map->linked('fichinter', (int) $row->rowid, Intervention::class);

            $attributes = [
                'year' => $start->year,
                'sequence' => $this->map->sequence(Intervention::class, $start->year, $intervention),
                'number' => $this->map->number(Intervention::class, (string) $row->ref, $intervention),
                'organization_id' => $organization->id,
                'location_id' => $organization->locations()->where('is_primary', true)->value('id'),
                'order_id' => $this->map->linkedId('commande', $orders[(int) $row->rowid][0] ?? null),
                'contract_id' => $this->map->linkedId('contrat', Source::int($row->fk_contrat ?? null)),
                'category_id' => $intervention?->category_id ?? $this->category(),
                'mode' => $intervention?->mode ?? InterventionMode::Onsite,
                'technician_id' => $this->map->user($row->fk_user_author ?? null),
                'started_at' => $start->utc(),
                'duration_minutes' => (int) round($seconds / 60),
                'description' => Source::join(Source::text($row->description ?? null), $this->details($details), Source::text($row->note_public ?? null)),
                'internal_notes' => Source::join(
                    Source::text($row->note_private ?? null),
                    ($signer = Source::line($row->online_sign_name ?? null)) ? __('Firmato online da :name (Dolibarr).', ['name' => $signer]) : null,
                    ! $invoiceIds && in_array($status, [2, 3], true) ? __('Fatturato o chiuso in Dolibarr.') : null,
                ),
                // Invoiced or closed in Dolibarr without an imported invoice: nothing left to invoice.
                'billable' => $invoiceIds || ! in_array($status, [2, 3], true),
            ];

            if ($intervention) {
                $intervention->forceFill($attributes)->save();
                $this->map->report->count('interventions', 'updated');
            } else {
                $intervention = Intervention::query()->forceCreate([...$attributes, 'created_at' => Source::date($row->datec ?? null) ?? $start]);
                $this->map->report->count('interventions', 'created');
            }

            $this->map->remember('fichinter', (int) $row->rowid, $intervention, $row->ref);
            $this->linkInvoice($intervention, $invoiceIds);
        }
    }

    /**
     * Lines of a Dolibarr intervention ("12/03/2026 09:00 · 1 h 30 min · description").
     *
     * @param  Collection<int, stdClass>  $details
     */
    private function details(Collection $details): ?string
    {
        $rows = $details->map(function (stdClass $line): string {
            $minutes = (int) round((int) ($line->duree ?? 0) / 60);
            $duration = $minutes >= 60 ? intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '') : "{$minutes} min";

            return implode(' · ', array_filter([Source::date($line->date ?? null)?->format('d/m/Y H:i'), $minutes ? $duration : null, Source::text($line->description ?? null)]));
        })->filter();

        return $rows->isEmpty() ? null : $rows->implode("\n");
    }

    /**
     * @param  list<int>  $invoiceIds
     */
    private function linkInvoice(Intervention $intervention, array $invoiceIds): void
    {
        $line = $invoiceIds ? Invoice::query()->find($invoiceIds[0])?->lines()->where('type', QuoteLineType::Item->value)->orderBy('position')->first() : null;

        // Lines of imported invoices are recreated at every import: only the new link is added.
        if ($line) {
            $intervention->invoiceLines()->syncWithoutDetaching([$line->id]);
        }
    }

    /**
     * Imported interventions use the inactive category "Da Dolibarr" (change it from the intervention).
     */
    private function category(): int
    {
        return $this->categoryId ??= (InterventionCategory::query()->where('name', __('Da Dolibarr'))->first()
            ?? InterventionCategory::query()->forceCreate(['name' => __('Da Dolibarr'), 'is_active' => false, 'sort' => 999]))->id;
    }

    /**
     * Hours of the imported contracts used by their interventions, in chronological order.
     */
    private function contractUsage(): void
    {
        $imported = $this->map->linkedModelIds('fichinter');

        foreach ($this->map->linkedIds('contrat') as $dolibarrId) {
            $contract = $this->map->linked('contrat', $dolibarrId, ServiceContract::class);

            if (! $contract) {
                continue;
            }

            $used = (int) $contract->interventions()->whereNotIn('id', $imported)->sum('contract_minutes');
            $available = $contract->totalMinutes();

            foreach ($contract->interventions()->whereIn('id', $imported)->orderBy('started_at')->get() as $intervention) {
                $minutes = min($intervention->billableMinutes(), max(0, $available - $used));
                $used += $minutes;
                $intervention->forceFill(['contract_minutes' => $minutes, 'covered' => $minutes > 0 && $minutes >= $intervention->billableMinutes()])->saveQuietly();
            }
        }
    }
}
