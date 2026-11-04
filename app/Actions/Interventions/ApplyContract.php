<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use App\Models\ServiceContract;
use App\Notifications\ContractHoursNotification;
use Illuminate\Support\Facades\DB;

/**
 * Uses the hours of the customer's contract for a completed intervention: the billed (rounded) minutes
 * are taken from the contract as long as hours remain, the rest is invoiced normally.
 * Sends the 80% and exhausted alerts to the contract manager.
 */
class ApplyContract
{
    public function handle(Intervention $intervention): void
    {
        if ($intervention->isInvoiced()) {
            return;
        }

        DB::transaction(function () use ($intervention) {
            $contract = $this->contractFor($intervention);
            $minutes = 0;

            if ($contract) {
                // Lock the contract row: two interventions saved together must not use the same hours.
                $contract = ServiceContract::query()->lockForUpdate()->find($contract->id);
                $minutes = min($contract->remainingMinutes($intervention->id), $intervention->billableMinutes());
            }

            $intervention->forceFill([
                'contract_id' => $minutes > 0 ? $contract->id : null,
                'contract_minutes' => $minutes,
            ]);
            $intervention->forceFill(['covered' => $minutes > 0 && $minutes >= $intervention->billableMinutes() && ! $this->hasExtras($intervention)]);
            $intervention->saveQuietly();

            if ($minutes > 0) {
                $this->alert($contract);
            }
        });
    }

    /**
     * The contract in use stays if it still covers the intervention; otherwise the first one ending.
     */
    public function contractFor(Intervention $intervention): ?ServiceContract
    {
        if ($intervention->isRunning() || ! $intervention->billable || $intervention->skip_contract || $intervention->billableMinutes() === 0) {
            return null;
        }

        $date = $intervention->localStart();

        return ServiceContract::query()
            ->usableFor($intervention->organization_id, $date)
            ->get()
            ->filter(fn (ServiceContract $contract) => $contract->covers($intervention->mode, $intervention->category_id, $date))
            ->filter(fn (ServiceContract $contract) => $contract->id === $intervention->contract_id || $contract->remainingMinutes($intervention->id) > 0)
            ->sortBy(fn (ServiceContract $contract) => [$contract->id === $intervention->contract_id ? 0 : 1, $contract->ends_on?->timestamp ?? PHP_INT_MAX, $contract->id])
            ->first();
    }

    private function hasExtras(Intervention $intervention): bool
    {
        return $intervention->isOnsite() && ($intervention->callout || (float) $intervention->km > 0 || $intervention->travel_minutes > 0 || $intervention->materials()->exists());
    }

    private function alert(ServiceContract $contract): void
    {
        $total = $contract->totalMinutes();
        $used = $contract->usedMinutes();

        if ($total <= 0 || ! $contract->manager) {
            return;
        }

        if ($used >= $total && ! $contract->alerted_exhausted_at) {
            $contract->forceFill(['alerted_exhausted_at' => now(), 'alerted_threshold_at' => $contract->alerted_threshold_at ?? now()])->save();
            $contract->manager->notify(new ContractHoursNotification($contract, true));
        } elseif ($used >= $total * ServiceContract::ALERT_THRESHOLD && $used < $total && ! $contract->alerted_threshold_at) {
            $contract->forceFill(['alerted_threshold_at' => now()])->save();
            $contract->manager->notify(new ContractHoursNotification($contract, false));
        }
    }
}
