<?php

namespace App\Console\Commands;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Notifications\QuoteActivityNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('quotes:check-expiry {--days=3 : Remind the owner this many days before the expiry}')]
#[Description('Mark expired quotes and remind owners of quotes about to expire')]
class CheckQuoteExpiryCommand extends Command
{
    public function handle(): int
    {
        $expired = Quote::query()
            ->with('owner')
            ->where('status', QuoteStatus::Sent->value)
            ->whereDate('valid_until', '<', today())
            ->get();

        foreach ($expired as $quote) {
            $quote->forceFill(['status' => QuoteStatus::Expired])->save();
            $quote->owner?->notify(new QuoteActivityNotification($quote, QuoteActivityNotification::EXPIRED));
        }

        $expiring = Quote::query()
            ->with('owner')
            ->where('status', QuoteStatus::Sent->value)
            ->whereNull('reminder_sent_at')
            ->whereDate('valid_until', '>=', today())
            ->whereDate('valid_until', '<=', today()->addDays((int) $this->option('days')))
            ->get();

        foreach ($expiring as $quote) {
            $quote->forceFill(['reminder_sent_at' => now()])->save();
            $quote->owner?->notify(new QuoteActivityNotification($quote, QuoteActivityNotification::EXPIRING));
        }

        $this->components->info("Expired: {$expired->count()}, reminders: {$expiring->count()}.");

        return self::SUCCESS;
    }
}
