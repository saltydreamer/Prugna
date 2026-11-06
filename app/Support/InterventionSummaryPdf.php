<?php

namespace App\Support;

use App\Actions\Interventions\BillInterventions;
use App\Models\Intervention;
use App\Models\ServiceContract;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Riepilogo interventi (resources/views/pdf/intervention-summary.blade.php): the interventions chosen in
 * the list, by customer and date, with time, travel and the hour contracts they used. Like the rapportino
 * it never prints internal notes or prices.
 */
class InterventionSummaryPdf extends CompanyPdf
{
    /**
     * @param  Collection<int, Intervention>  $interventions  Completed interventions.
     */
    public function make(Collection $interventions): DomPdf
    {
        $interventions = $interventions
            ->loadMissing(['organization', 'location', 'category', 'technician:id,name', 'materials', 'contract'])
            ->sortBy([['started_at', 'asc'], ['id', 'asc']])
            ->values();

        $customers = $interventions->groupBy('organization_id')
            ->map(fn (Collection $group) => [
                'organization' => $group->first()->organization,
                'interventions' => $group,
                'totals' => $this->totals($group),
            ])
            ->sortBy(fn (array $customer) => mb_strtolower((string) $customer['organization']?->name))
            ->values();

        return $this->render('pdf.intervention-summary', [
            'customers' => $customers,
            'customer' => $customers->count() === 1 && $customers->first()['organization'] ? CustomerSnapshot::from($customers->first()['organization']) : null,
            'period' => BillInterventions::period($interventions),
            'totals' => $this->totals($interventions),
            'contracts' => $this->contracts($interventions),
        ]);
    }

    /**
     * @param  Collection<int, Intervention>  $interventions
     */
    public function filename(Collection $interventions): string
    {
        $customers = $interventions->pluck('organization_id')->unique();
        $name = $customers->count() === 1 ? Str::slug((string) $interventions->first()->organization?->name) : '';

        return 'Riepilogo-interventi-'.($name !== '' ? "{$name}-" : '').now(config('app.display_timezone'))->format('Y-m-d').'.pdf';
    }

    /**
     * @param  Collection<int, Intervention>  $interventions
     * @return array{count: int, minutes: int, billable_minutes: int, travel_minutes: int, km: float}
     */
    private function totals(Collection $interventions): array
    {
        $onsite = $interventions->filter(fn (Intervention $intervention) => $intervention->isOnsite());

        return [
            'count' => $interventions->count(),
            'minutes' => (int) $interventions->sum('duration_minutes'),
            'billable_minutes' => (int) $interventions->where('billable', true)->sum(fn (Intervention $intervention) => $intervention->billableMinutes()),
            'travel_minutes' => (int) $onsite->sum('travel_minutes'),
            'km' => (float) $onsite->sum(fn (Intervention $intervention) => (float) $intervention->km),
        ];
    }

    /**
     * Hour contracts used by these interventions: hours taken by them and what is left today.
     *
     * @param  Collection<int, Intervention>  $interventions
     * @return Collection<int, array{contract: ServiceContract, minutes: int, remaining: int}>
     */
    private function contracts(Collection $interventions): Collection
    {
        return $interventions->filter(fn (Intervention $intervention) => $intervention->contract !== null && $intervention->contract_minutes > 0)
            ->groupBy('contract_id')
            ->map(fn (Collection $group) => [
                'contract' => $group->first()->contract,
                'minutes' => (int) $group->sum('contract_minutes'),
                'remaining' => $group->first()->contract->remainingMinutes(),
            ])
            ->sortBy(fn (array $row) => $row['contract']->number)
            ->values();
    }
}
