<?php

namespace App\Notifications;

use App\Enums\SdiStatus;
use App\Models\SdiTransmission;

/**
 * An invoice sent to the SDI was rejected, refused by the public administration or not delivered.
 */
class SdiOutcomeNotification extends AppNotification
{
    public function __construct(public SdiTransmission $transmission) {}

    public function title(): string
    {
        $invoice = $this->transmission->invoice;

        return match ($this->transmission->status) {
            SdiStatus::Rejected => __('Fattura :number scartata dallo SDI', ['number' => $invoice?->number]),
            SdiStatus::Refused => __('Fattura :number rifiutata dalla PA', ['number' => $invoice?->number]),
            default => __('Fattura :number non consegnata', ['number' => $invoice?->number]),
        };
    }

    public function message(): string
    {
        return match ($this->transmission->status) {
            SdiStatus::Rejected => __('La fattura non risulta emessa: correggi quanto indicato e inviala di nuovo entro 5 giorni. :errors', ['errors' => implode(' · ', array_slice((array) $this->transmission->errors, 0, 3))]),
            SdiStatus::Refused => __('La pubblica amministrazione ha rifiutato la fattura: emetti una nota di credito se serve.'),
            default => __('Lo SDI non è riuscito a consegnarla: la fattura è comunque emessa ed è nel cassetto fiscale del cliente. Inviagli una copia di cortesia.'),
        };
    }

    public function icon(): string
    {
        return 'mdi-alert-circle-outline';
    }

    public function color(): string
    {
        return $this->transmission->status === SdiStatus::NotDelivered ? 'warning' : 'error';
    }

    public function actionUrl(): ?string
    {
        return route('sales.invoices.show', $this->transmission->invoice_id);
    }

    public function actionLabel(): ?string
    {
        return __('Apri la fattura');
    }
}
