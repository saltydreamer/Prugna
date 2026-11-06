<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Segna come già fatturati»: interventions to invoice up to a day that were invoiced outside
 * Prugna (e.g. in Dolibarr before switching) are marked, so they are never proposed again.
 * An intervention listing jobs after that day (imported from Dolibarr, see SplitIntervention)
 * is flagged: it should be split first, otherwise the later jobs would be marked too.
 */
class MarkInterventionsInvoiced
{
    public function __construct(private SplitIntervention $split) {}

    /**
     * Interventions to invoice started by the end of the day (local), oldest first.
     *
     * @return Collection<int, array{intervention: Intervention, later_jobs: int}>
     */
    public function candidates(CarbonInterface $until): Collection
    {
        $day = $until->toDateString();

        return Intervention::query()
            ->toInvoice()
            ->where('started_at', '<=', $this->endOf($day))
            ->with('organization')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Intervention $intervention) => [
                'intervention' => $intervention,
                'later_jobs' => count(array_filter(
                    $this->split->parse($intervention)['parts'],
                    fn (array $part) => $part['started_at']->toDateString() > $day,
                )),
            ]);
    }

    /**
     * @param  list<int>  $ids
     * @return int How many were marked.
     */
    public function handle(CarbonInterface $until, array $ids): int
    {
        return Intervention::query()
            ->toInvoice()
            ->whereKey($ids)
            ->where('started_at', '<=', $this->endOf($until->toDateString()))
            ->update(['invoiced_outside_at' => now()]);
    }

    private function endOf(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day, config('app.display_timezone'))->endOfDay()->utc();
    }
}
