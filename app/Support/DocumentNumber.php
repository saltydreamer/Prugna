<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Yearly progressive numbers: ORD-2026-0001, FT-2026-0001...
 * Call inside a transaction: the row lock and the unique index guard against duplicates.
 */
class DocumentNumber
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $scope  Extra conditions identifying the sequence (e.g. ['series' => 'FT']).
     * @return array{year: int, sequence: int, number: string}
     */
    public static function next(string $model, string $prefix, int $year, array $scope = []): array
    {
        $sequence = (int) $model::query()
            ->where('year', $year)
            ->where($scope)
            ->whereNotNull('sequence')
            ->lockForUpdate()
            ->max('sequence') + 1;

        return [
            'year' => $year,
            'sequence' => $sequence,
            'number' => sprintf('%s-%d-%04d', $prefix, $year, $sequence),
        ];
    }
}
