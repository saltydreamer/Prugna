<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Demo\DemoData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Fills an empty database with the demonstration data (dev.reimg.it): run on a freshly migrated
 * database (deploy/scripts/demo-reset.sh, workflow "Reset demo").
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'app:demo-seed
        {--months=36 : How many months of work, up to today}
        {--force : Run in production (the demo server runs with APP_ENV=production)}';

    protected $description = 'Fill an empty database with three years of work of a demo freelance system administrator (flat-rate regime)';

    public function handle(DemoData $demo): int
    {
        if (Organization::query()->withTrashed()->exists() || Invoice::query()->exists()) {
            $this->error('The database already holds data: the demo is only for an empty database (workflow «Reset demo»).');

            return self::FAILURE;
        }

        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->error('Production: confirm with --force.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $stats = $demo->run(fn (CarbonImmutable $month) => $this->line('  '.$month->locale('it')->translatedFormat('F Y')), max(1, (int) $this->option('months')));

        $this->newLine();
        $this->table(['Dato', 'Quanti'], collect($stats)->map(fn (int $count, string $key) => [$key, $count])->values()->all());
        $this->info(sprintf('Demo pronta in %d s: accedi con %s / %s', (int) (microtime(true) - $started), DemoData::EMAIL, DemoData::PASSWORD));

        return self::SUCCESS;
    }
}
