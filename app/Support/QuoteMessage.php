<?php

namespace App\Support;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Number;

/**
 * Fills the placeholders of the quote email template (Impostazioni → Preventivi).
 */
class QuoteMessage
{
    public function __construct(private Settings $settings) {}

    /**
     * @return array{subject: string, body: string}
     */
    public function defaults(Quote $quote, User $sender): array
    {
        return [
            'subject' => $this->fill((string) $this->settings->get('quotes.email_subject'), $quote, $sender),
            'body' => $this->fill((string) $this->settings->get('quotes.email_body'), $quote, $sender),
        ];
    }

    public function fill(string $template, Quote $quote, User $sender): string
    {
        $quote->loadMissing('contact');

        return strtr($template, [
            '{numero}' => $quote->number,
            '{cliente}' => $quote->contact?->full_name ?? ($quote->customer['name'] ?? ''),
            '{titolo}' => (string) $quote->title,
            '{totale}' => Number::currency((float) $quote->total, 'EUR', 'it'),
            '{scadenza}' => $quote->valid_until->format('d/m/Y'),
            '{link}' => $quote->publicUrl(),
            '{mittente}' => $sender->name,
            '{azienda}' => (string) ($this->settings->get('company.name') ?: config('app.name')),
        ]);
    }
}
