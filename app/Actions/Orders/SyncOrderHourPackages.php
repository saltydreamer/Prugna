<?php

namespace App\Actions\Orders;

use App\Actions\Interventions\ApplyContract;
use App\Enums\OrderStatus;
use App\Models\Intervention;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ServiceContract;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every order line selling an hour package (an article "monte ore prepagato", or a line with its
 * package hours) has its contract a monte ore, invoiced with the order and never on its own.
 * Order lines are replaced on every save, so a contract follows its line by id and, when the ids
 * changed, by position. New contracts use up at once the customer's interventions still to
 * invoice from their start; a contract whose line is gone is deleted unless already used.
 */
class SyncOrderHourPackages
{
    public function __construct(private ApplyContract $applyContract) {}

    /**
     * @param  bool  $updateExisting  Bring the contracts in step with their lines (after an edit of the order).
     * @return int The contracts created now.
     */
    public function handle(Order $order, bool $updateExisting = true): int
    {
        $lines = $this->packageLines($order);
        $contracts = $order->hourPackages()->whereNotNull('order_line_id')->orderBy('id')->get();
        $byLine = $contracts->keyBy('order_line_id');
        $spare = $contracts->reject(fn (ServiceContract $contract) => $lines->contains('id', $contract->order_line_id))->values();

        $created = DB::transaction(function () use ($order, $lines, $byLine, $spare, $updateExisting): array {
            $created = [];

            foreach ($lines as $line) {
                $contract = $byLine->get($line->id) ?? $spare->shift();

                if ($contract === null) {
                    $created[] = $this->create($order, $line);

                    continue;
                }

                $contract->forceFill(['order_line_id' => $line->id]);

                if ($updateExisting) {
                    $this->fillFromLine($contract, $order, $line);
                }

                $contract->save();
            }

            foreach ($spare as $contract) {
                $contract->interventions()->exists() ? $contract->forceFill(['order_line_id' => null])->save() : $contract->delete();
            }

            return $created;
        });

        foreach ($created as $contract) {
            $this->usePendingInterventions($contract);
        }

        return count($created);
    }

    /**
     * Package lines of the order still without their contract.
     */
    public function missing(Order $order): int
    {
        $linked = $order->hourPackages()->whereNotNull('order_line_id')->pluck('order_line_id');

        return $this->packageLines($order)->reject(fn (OrderLine $line) => $linked->contains($line->id))->count();
    }

    /**
     * A cancelled order stops its packages; restoring it starts them again.
     */
    public function statusChanged(Order $order): void
    {
        $order->hourPackages()->whereNotNull('order_line_id')->update(['is_active' => $order->status !== OrderStatus::Cancelled]);
    }

    /**
     * Before deleting the order: its packages go, unless already used.
     */
    public function release(Order $order): void
    {
        $order->hourPackages()->whereNotNull('order_line_id')->whereDoesntHave('interventions')->delete();
    }

    /**
     * @return Collection<int, OrderLine>
     */
    private function packageLines(Order $order): Collection
    {
        return $order->lines()->with('product')->get()->filter(fn (OrderLine $line) => $line->packageHours() !== null)->values();
    }

    private function create(Order $order, OrderLine $line): ServiceContract
    {
        $start = CarbonImmutable::parse(($order->order_date ?? now(config('app.display_timezone')))->toDateString());

        $contract = new ServiceContract([
            'starts_on' => $start->toDateString(),
            'mode' => null,
            'category_ids' => [],
            'manager_id' => $order->owner_id,
            'is_active' => $order->status !== OrderStatus::Cancelled,
            'notes' => __('Dall\'ordine :number: si fattura con l\'ordine.', ['number' => $order->number]),
        ]);
        $this->fillFromLine($contract, $order, $line);

        $contract->forceFill([
            ...DocumentNumber::next(ServiceContract::class, 'MO', (int) $start->format('Y')),
            'order_id' => $order->id,
            'order_line_id' => $line->id,
        ])->save();

        return $contract;
    }

    private function fillFromLine(ServiceContract $contract, Order $order, OrderLine $line): void
    {
        $months = $line->packageMonths();

        $contract->fill([
            'organization_id' => $order->organization_id,
            'title' => Str::limit(trim((string) strtok($line->description, "\n")) ?: __('Monte ore'), 250),
            'hours' => $line->packageHours(),
            'ends_on' => $months ? $contract->starts_on->copy()->addMonthsNoOverflow($months)->subDay()->toDateString() : null,
            'price' => round((float) $line->total, 2),
            'product_id' => $line->product_id,
            'vat_rate_id' => $line->vat_rate_id,
        ]);

        // More hours: the alerts may fire again.
        if ($contract->exists && $contract->isDirty('hours')) {
            $contract->forceFill(['alerted_threshold_at' => null, 'alerted_exhausted_at' => null]);
        }
    }

    /**
     * The customer's interventions still to invoice from the start of the package use it up.
     */
    private function usePendingInterventions(ServiceContract $contract): void
    {
        if (! $contract->is_active) {
            return;
        }

        $from = CarbonImmutable::parse($contract->starts_on->toDateString(), config('app.display_timezone'))->startOfDay()->utc();

        Intervention::query()
            ->where('organization_id', $contract->organization_id)
            ->whereNull('contract_id')
            ->toInvoice()
            ->where('started_at', '>=', $from)
            ->orderBy('started_at')
            ->get()
            ->each(fn (Intervention $intervention) => $this->applyContract->handle($intervention));
    }
}
