<?php

namespace App\Notifications;

use App\Actions\Interventions\BillInterventions;
use App\Models\ServiceContract;

/**
 * The hours of a contract are running out (80%) or are over.
 */
class ContractHoursNotification extends AppNotification
{
    public function __construct(public ServiceContract $contract, public bool $exhausted) {}

    public function title(): string
    {
        return $this->exhausted
            ? __('Monte ore esaurito: :customer', ['customer' => $this->contract->organization?->name])
            : __('Monte ore quasi esaurito: :customer', ['customer' => $this->contract->organization?->name]);
    }

    public function message(): string
    {
        $remaining = $this->contract->remainingMinutes();

        return $this->exhausted
            ? __('Il contratto :number «:title» ha usato tutte le :hours ore: i prossimi interventi andranno in fattura.', [
                'number' => $this->contract->number,
                'title' => $this->contract->title,
                'hours' => BillInterventions::hoursLabel($this->contract->totalMinutes()),
            ])
            : __('Il contratto :number «:title» ha superato l\'80% delle ore: restano :remaining.', [
                'number' => $this->contract->number,
                'title' => $this->contract->title,
                'remaining' => BillInterventions::hoursLabel($remaining),
            ]);
    }

    public function icon(): string
    {
        return 'mdi-timer-alert-outline';
    }

    public function color(): string
    {
        return $this->exhausted ? 'error' : 'warning';
    }

    public function actionUrl(): ?string
    {
        return route('interventions.contracts.show', $this->contract);
    }

    public function actionLabel(): ?string
    {
        return __('Apri il contratto');
    }
}
