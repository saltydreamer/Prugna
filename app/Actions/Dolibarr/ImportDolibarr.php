<?php

namespace App\Actions\Dolibarr;

use App\Support\Dolibarr\DolibarrMapper;
use App\Support\Dolibarr\DolibarrSource;
use App\Support\Dolibarr\ImportReport;
use App\Support\Dolibarr\SqlDumpReader;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports a Dolibarr backup into Prugna, or simulates it.
 *
 * The backup is read into a SQLite staging database (nothing in it is executed), then every
 * step runs in one transaction: a simulation does the whole import and rolls it back, so its
 * report is exactly what the real import will do; the real import is committed only without
 * blocking errors. Every imported row is linked to its Prugna record (dolibarr_links), so
 * importing again updates instead of duplicating.
 */
class ImportDolibarr
{
    /** Dolibarr data without a place in Prugna, reported when present. */
    private const NOT_IMPORTED = [
        'projet' => 'Progetti',
        'projet_task' => 'Attività dei progetti',
        'actioncomm' => 'Eventi dell\'agenda',
        'expensereport' => 'Note spese',
        'bank' => 'Movimenti bancari',
        'ticket' => 'Ticket',
        'expedition' => 'Spedizioni',
        'reception' => 'Ricevimenti merce',
        'stock_mouvement' => 'Movimenti di magazzino',
        'holiday' => 'Ferie e permessi',
        'salary' => 'Stipendi',
        'chargesociales' => 'Contributi e tasse',
        'facture_rec' => 'Modelli di fatture ricorrenti',
        'don' => 'Donazioni',
        'adherent' => 'Membri',
    ];

    public function __construct(private SqlDumpReader $reader, private Settings $settings) {}

    /**
     * @return array<string, mixed> The report (see ImportReport::toArray()).
     */
    public function handle(string $backupPath, bool $simulate): array
    {
        $directory = storage_path('app/private/dolibarr');
        is_dir($directory) || mkdir($directory, 0775, true);
        $stagingPath = $directory.'/staging-'.Str::random(12).'.sqlite';

        try {
            $loaded = $this->reader->load($backupPath, $stagingPath, DolibarrSource::TABLES);
            $source = new DolibarrSource($stagingPath);
            $report = new ImportReport;

            $report->source([
                'version' => $source->constant('MAIN_VERSION_LAST_UPGRADE') ?? $source->constant('MAIN_VERSION_LAST_INSTALL'),
                'company' => $source->constant('MAIN_INFO_SOCIETE_NOM'),
                'prefix' => $loaded['prefix'],
                'tables' => $loaded['tables'],
                'skipped_rows' => $loaded['skipped_rows'],
                'simulation' => $simulate,
            ]);

            if ($loaded['skipped_rows'] > 0) {
                $report->warn(__('Righe del backup non leggibili (senza struttura della tabella o con colonne diverse): ignorate.'), (string) $loaded['skipped_rows']);
            }

            $this->notImported($report, $loaded['other']);

            DB::beginTransaction();

            try {
                $map = new DolibarrMapper($source, $report, $this->settings);
                $thirdParties = new ImportThirdParties($map);
                $thirdParties->run();
                (new ImportCatalog($map, $thirdParties))->run();
                (new ImportSales($map, $thirdParties))->run();
                (new ImportPurchases($map, $thirdParties))->run();
                (new ImportInterventions($map, $thirdParties))->run();

                if ($simulate || $report->hasErrors()) {
                    DB::rollBack();
                } else {
                    DB::commit();
                }
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            return $report->toArray();
        } finally {
            DB::purge(DolibarrSource::CONNECTION);
            @unlink($stagingPath);
        }
    }

    /**
     * @param  array<string, int>  $other  Rows of the tables that are not read.
     */
    private function notImported(ImportReport $report, array $other): void
    {
        foreach (self::NOT_IMPORTED as $table => $label) {
            if (($other[$table] ?? 0) > 0) {
                $report->warn(__('Dati di Dolibarr che Prugna non gestisce: non importati.'), __(':label (:count)', ['label' => $label, 'count' => $other[$table]]));
            }
        }
    }
}
