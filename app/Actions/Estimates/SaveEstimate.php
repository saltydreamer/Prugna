<?php

namespace App\Actions\Estimates;

use App\Models\Estimate;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Creates (numbering PS-2026-0001) and updates an estimate with its items.
 */
class SaveEstimate
{
    /**
     * @param  array<string, mixed>  $data  Fields plus "items".
     */
    public function handle(Estimate $estimate, array $data): Estimate
    {
        abort_if($estimate->isAccepted(), 409, __('La proposta è già accettata: non si modifica.'));

        $items = $data['items'] ?? null;
        unset($data['items']);

        return DB::transaction(function () use ($estimate, $data, $items): Estimate {
            $estimate->fill($data);

            if (! $estimate->exists) {
                $estimate->forceFill(DocumentNumber::next(Estimate::class, 'PS', (int) now()->year));
            }

            $estimate->save();

            if ($items !== null) {
                $estimate->items()->delete();

                foreach (array_values($items) as $position => $item) {
                    $estimate->items()->create([
                        'position' => $position + 1,
                        'category' => filled($item['category'] ?? null) ? trim($item['category']) : null,
                        'description' => $item['description'],
                        'url' => $item['url'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'note' => $item['note'] ?? null,
                    ]);
                }
            }

            // Markups only for the categories still in use.
            $used = $estimate->items()->whereNotNull('category')->distinct()->pluck('category')->all();
            $estimate->forceFill(['category_markups' => array_intersect_key($estimate->category_markups ?? [], array_flip($used)) ?: null])->save();

            return $estimate->load('items');
        });
    }
}
