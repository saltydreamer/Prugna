<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a single "is_primary" record per organization:
 * - the first record of an organization becomes primary automatically;
 * - marking a record as primary unmarks its siblings;
 * - deleting the primary record promotes the oldest remaining one.
 *
 * @mixin Model
 */
trait HasPrimaryFlag
{
    public static function bootHasPrimaryFlag(): void
    {
        static::creating(function (Model $model): void {
            $hasSiblings = static::query()->where('organization_id', $model->organization_id)->exists();

            if (! $hasSiblings) {
                $model->is_primary = true;
            }
        });

        static::saved(function (Model $model): void {
            if ($model->is_primary && ($model->wasRecentlyCreated || $model->wasChanged('is_primary'))) {
                static::query()
                    ->where('organization_id', $model->organization_id)
                    ->whereKeyNot($model->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });

        static::deleted(function (Model $model): void {
            if (! $model->is_primary) {
                return;
            }

            static::query()
                ->where('organization_id', $model->organization_id)
                ->oldest('id')
                ->first()
                ?->forceFill(['is_primary' => true])
                ->saveQuietly();
        });
    }
}
