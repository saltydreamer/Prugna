<?php

namespace App\Actions\Orders;

use App\Actions\Interventions\ApplyContract;
use App\Models\Intervention;
use App\Models\Order;
use App\Models\ServiceContract;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hour package sold with an order (also an order invoiced before Prugna): a contract a monte ore of
 * the customer, invoiced with the order and never on its own. Optionally the interventions of the
 * customer still to invoice from the start date are used up from it straight away.
 */
class AddHourPackage
{
    public function __construct(private ApplyContract $applyContract) {}

    /**
     * @param  array{title: string, hours: float|int|string, starts_on: string, ends_on?: string|null, price?: float|int|string|null, product_id?: int|null, vat_rate_id?: int|null}  $data
     * @return array{contract: ServiceContract, covered: int}
     */
    public function handle(Order $order, array $data, int $managerId, bool $applyToPending = false): array
    {
        $contract = DB::transaction(function () use ($order, $data, $managerId) {
            $contract = new ServiceContract([
                'organization_id' => $order->organization_id,
                'title' => $data['title'],
                'hours' => $data['hours'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'mode' => null,
                'category_ids' => [],
                'price' => $data['price'] ?? 0,
                'product_id' => $data['product_id'] ?? null,
                'vat_rate_id' => $data['vat_rate_id'] ?? null,
                'manager_id' => $managerId,
                'is_active' => true,
                'notes' => __('Dall\'ordine :number: si fattura con l\'ordine.', ['number' => $order->number]),
            ]);

            $contract->forceFill([
                ...DocumentNumber::next(ServiceContract::class, 'MO', (int) $contract->starts_on->format('Y')),
                'order_id' => $order->id,
            ])->save();

            return $contract;
        });

        $covered = 0;

        if ($applyToPending) {
            $from = CarbonImmutable::parse($contract->starts_on->toDateString(), config('app.display_timezone'))->startOfDay()->utc();

            Intervention::query()
                ->where('organization_id', $order->organization_id)
                ->whereNull('contract_id')
                ->toInvoice()
                ->where('started_at', '>=', $from)
                ->orderBy('started_at')
                ->get()
                ->each(function (Intervention $intervention) use (&$covered, $contract) {
                    $this->applyContract->handle($intervention);
                    $covered += (int) ($intervention->contract_id === $contract->id);
                });
        }

        return ['contract' => $contract, 'covered' => $covered];
    }
}
