<?php

namespace App\Notifications;

use App\Models\Quote;
use Illuminate\Support\Number;

/**
 * Tells the quote owner what happened: viewed, accepted, rejected, expiring, expired.
 */
class QuoteActivityNotification extends AppNotification
{
    public const VIEWED = 'viewed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public function __construct(public Quote $quote, public string $event)
    {
        // Opening the link is not worth an email.
        $this->mailable = $event !== self::VIEWED;
    }

    public function title(): string
    {
        $number = $this->quote->number;

        return match ($this->event) {
            self::VIEWED => __('Preventivo :number aperto dal cliente', ['number' => $number]),
            self::ACCEPTED => __('Preventivo :number accettato', ['number' => $number]),
            self::REJECTED => __('Preventivo :number rifiutato', ['number' => $number]),
            self::EXPIRING => __('Preventivo :number in scadenza', ['number' => $number]),
            self::EXPIRED => __('Preventivo :number scaduto', ['number' => $number]),
        };
    }

    public function message(): string
    {
        $customer = $this->quote->customer['name'] ?? '';
        $total = Number::currency((float) $this->quote->total, 'EUR', 'it');

        return match ($this->event) {
            self::VIEWED => __(':customer ha aperto il preventivo (:total).', ['customer' => $customer, 'total' => $total]),
            self::ACCEPTED => trim(__(':name ha accettato il preventivo per :customer (:total).', [
                'name' => $this->quote->decision_name ?: __('Il cliente'), 'customer' => $customer, 'total' => $total,
            ]).($this->quote->decision_note ? "\n«{$this->quote->decision_note}»" : '')),
            self::REJECTED => trim(__(':customer ha rifiutato il preventivo.', ['customer' => $customer])
                .($this->quote->decision_note ? "\n".__('Motivo: :note', ['note' => $this->quote->decision_note]) : '')),
            self::EXPIRING => __('Il preventivo per :customer (:total) scade il :date e non ha ancora una risposta.', [
                'customer' => $customer, 'total' => $total, 'date' => $this->quote->valid_until->format('d/m/Y'),
            ]),
            self::EXPIRED => __('Il preventivo per :customer è scaduto senza risposta. Puoi riaprirlo e aggiornarlo.', ['customer' => $customer]),
        };
    }

    public function icon(): string
    {
        return match ($this->event) {
            self::VIEWED => 'mdi-eye-outline',
            self::ACCEPTED => 'mdi-check-decagram-outline',
            self::REJECTED => 'mdi-close-octagon-outline',
            self::EXPIRING => 'mdi-timer-sand',
            self::EXPIRED => 'mdi-timer-sand-complete',
        };
    }

    public function color(): string
    {
        return match ($this->event) {
            self::VIEWED => 'info',
            self::ACCEPTED => 'success',
            self::REJECTED => 'error',
            self::EXPIRING, self::EXPIRED => 'warning',
        };
    }

    public function actionUrl(): ?string
    {
        return route('sales.quotes.show', $this->quote);
    }

    public function actionLabel(): ?string
    {
        return __('Apri il preventivo');
    }
}
