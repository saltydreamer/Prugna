<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\RunDolibarrImport;
use App\Models\DolibarrImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importa da Dolibarr: upload of a Dolibarr backup, simulation, real import, history.
 */
class DolibarrImportController extends Controller
{
    /** A simulation or import not finished after this many minutes is considered dead. */
    private const STALE_MINUTES = 45;

    public function index(): Response
    {
        Gate::authorize(Permission::DolibarrImport->value);

        $imports = DolibarrImport::query()->with('user:id,name')->latest('id')->limit(10)->get();

        return Inertia::render('settings/DolibarrImport', [
            'imports' => $imports->map(fn (DolibarrImport $import) => [
                'id' => $import->id,
                'file_name' => $import->file_name,
                'file_size' => $import->file_size,
                'has_file' => $import->file_path !== null,
                'status' => $this->isStale($import) ? DolibarrImport::FAILED : $import->status,
                'running' => $import->isRunning() && ! $this->isStale($import),
                'can_import' => $import->canImport(),
                'can_simulate' => $import->canSimulateAgain() || $this->isStale($import),
                'report' => $import->report,
                'error' => $this->isStale($import) ? __('L\'elaborazione si è interrotta: ripeti la simulazione.') : $import->error,
                'user' => $import->user?->name,
                'created_at' => $import->created_at?->toIso8601String(),
                'finished_at' => $import->finished_at?->toIso8601String(),
                'imported_at' => $import->imported_at?->toIso8601String(),
            ]),
            'maxUploadMb' => (int) floor(min($this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size')) / 1048576),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::DolibarrImport->value);

        $request->validate(
            ['backup' => ['required', 'file', 'max:102400', 'extensions:sql,gz,zip']],
            ['backup.extensions' => __('Carica il backup del database di Dolibarr: un file .sql, .sql.gz o .zip.')],
            ['backup' => __('backup')],
        );

        abort_if(DolibarrImport::query()->whereIn('status', [DolibarrImport::SIMULATING, DolibarrImport::IMPORTING])->where('created_at', '>', now()->subMinutes(self::STALE_MINUTES))->exists(), 409, __('C\'è già un\'importazione in corso.'));

        $file = $request->file('backup');
        $path = $file->storeAs('dolibarr', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        $import = DolibarrImport::query()->forceCreate([
            'user_id' => $request->user()->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'status' => DolibarrImport::SIMULATING,
        ]);

        RunDolibarrImport::dispatch($import->id, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup caricato: simulazione in corso.')]);

        return to_route('settings.dolibarr.index');
    }

    public function simulate(DolibarrImport $dolibarrImport): RedirectResponse
    {
        Gate::authorize(Permission::DolibarrImport->value);
        abort_unless($dolibarrImport->file_path && (! $dolibarrImport->isRunning() || $this->isStale($dolibarrImport)), 409);

        $this->start($dolibarrImport, DolibarrImport::SIMULATING, true);

        return back();
    }

    public function import(DolibarrImport $dolibarrImport): RedirectResponse
    {
        Gate::authorize(Permission::DolibarrImport->value);
        abort_unless($dolibarrImport->canImport(), 409, __('Prima serve una simulazione senza errori.'));

        $this->start($dolibarrImport, DolibarrImport::IMPORTING, false);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importazione avviata.')]);

        return back();
    }

    public function destroy(DolibarrImport $dolibarrImport): RedirectResponse
    {
        Gate::authorize(Permission::DolibarrImport->value);
        abort_if($dolibarrImport->isRunning() && ! $this->isStale($dolibarrImport), 409, __('Attendi la fine dell\'elaborazione.'));

        if ($dolibarrImport->file_path) {
            Storage::disk('local')->delete($dolibarrImport->file_path);
        }

        $dolibarrImport->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup eliminato.')]);

        return back();
    }

    private function start(DolibarrImport $import, string $status, bool $simulate): void
    {
        $import->forceFill(['status' => $status, 'started_at' => null, 'finished_at' => null, 'error' => null])->save();

        RunDolibarrImport::dispatch($import->id, $simulate);
    }

    private function isStale(DolibarrImport $import): bool
    {
        return $import->isRunning() && $import->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
