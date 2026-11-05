<?php

namespace App\Jobs;

use App\Actions\Dolibarr\ImportDolibarr;
use App\Models\DolibarrImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Simulates or runs the import of an uploaded Dolibarr backup and stores the report.
 * After a successful import the backup (a full copy of the Dolibarr data) is deleted.
 */
class RunDolibarrImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public int $importId, public bool $simulate) {}

    public function handle(ImportDolibarr $importer): void
    {
        $status = $this->simulate ? DolibarrImport::SIMULATING : DolibarrImport::IMPORTING;

        // Claimed once: a second delivery of the same job (queue retry) does nothing.
        $claimed = DolibarrImport::query()->whereKey($this->importId)->where('status', $status)->whereNull('started_at')->update(['started_at' => now()]);
        $import = DolibarrImport::query()->find($this->importId);

        if (! $claimed || ! $import || ! $import->file_path) {
            return;
        }

        try {
            $report = $importer->handle(Storage::disk('local')->path($import->file_path), $this->simulate);
            $imported = ! $this->simulate && empty($report['errors']);

            if ($imported) {
                Storage::disk('local')->delete($import->file_path);
            }

            $import->forceFill([
                'status' => $imported ? DolibarrImport::IMPORTED : DolibarrImport::SIMULATED,
                'report' => $report,
                'error' => null,
                'file_path' => $imported ? null : $import->file_path,
                'finished_at' => now(),
                'imported_at' => $imported ? now() : $import->imported_at,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $import->forceFill([
                'status' => DolibarrImport::FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();
        }
    }
}
