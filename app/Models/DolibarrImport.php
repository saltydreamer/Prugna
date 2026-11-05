<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded Dolibarr backup: simulated first, then imported (see App\Jobs\RunDolibarrImport).
 */
class DolibarrImport extends Model
{
    public const UPLOADED = 'uploaded';

    public const SIMULATING = 'simulating';

    public const SIMULATED = 'simulated';

    public const IMPORTING = 'importing';

    public const IMPORTED = 'imported';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::UPLOADED, self::SIMULATING, self::IMPORTING], true);
    }

    /**
     * The real import can start after a simulation without blocking errors.
     */
    public function canImport(): bool
    {
        return $this->status === self::SIMULATED && $this->file_path !== null && empty($this->report['errors']);
    }

    /**
     * The real import of the same file can be repeated (e.g. after fixing Dolibarr data).
     */
    public function canSimulateAgain(): bool
    {
        return $this->file_path !== null && ! $this->isRunning();
    }
}
