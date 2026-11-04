<?php

namespace App\Actions\Interventions;

use App\Enums\InterventionMode;
use App\Models\Intervention;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Creates (numbering INT-2026-0001) and updates interventions with their materials.
 * Remote interventions drop the on-site extras; the hours of the customer's contract are applied.
 */
class SaveIntervention
{
    public function __construct(private ApplyContract $applyContract) {}

    /**
     * @param  array<string, mixed>  $data  Fields plus optional "materials".
     */
    public function handle(Intervention $intervention, array $data): Intervention
    {
        abort_if($intervention->exists && ! $intervention->isEditable(), 409, __('Gli interventi fatturati non si modificano.'));

        $materials = $data['materials'] ?? null;
        unset($data['materials']);

        return DB::transaction(function () use ($intervention, $data, $materials): Intervention {
            $intervention->fill($data);

            if ($intervention->mode !== InterventionMode::Onsite) {
                $intervention->fill(['callout' => false, 'km' => 0, 'travel_minutes' => 0]);
                $materials = $intervention->exists || $materials !== null ? [] : null;
            }

            if (! $intervention->exists) {
                $number = DocumentNumber::next(Intervention::class, 'INT', (int) $intervention->started_at->format('Y'));
                $intervention->forceFill($number);
            }

            $intervention->save();

            if ($materials !== null) {
                $intervention->materials()->delete();

                foreach (array_values($materials) as $position => $material) {
                    $intervention->materials()->create([
                        'position' => $position,
                        'product_id' => $material['product_id'] ?? null,
                        'code' => $material['code'] ?? null,
                        'description' => $material['description'],
                        'unit' => $material['unit'] ?? null,
                        'quantity' => $material['quantity'],
                        'unit_price' => $material['unit_price'] ?? 0,
                        'vat_rate_id' => $material['vat_rate_id'] ?? null,
                    ]);
                }
            }

            $this->applyContract->handle($intervention);

            return $intervention;
        });
    }
}
