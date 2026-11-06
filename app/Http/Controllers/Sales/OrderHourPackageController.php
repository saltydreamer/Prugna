<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Orders\AddHourPackage;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ServiceContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Hour packages (contracts a monte ore) sold with an order, also one invoiced before Prugna.
 */
class OrderHourPackageController extends Controller
{
    public function store(Request $request, Order $order, AddHourPackage $addHourPackage): RedirectResponse
    {
        $this->authorize('view', $order);
        $this->authorize('create', ServiceContract::class);

        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['hours' => __('L\'ordine è annullato.')]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:100000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'vat_rate_id' => ['nullable', 'integer', 'exists:vat_rates,id'],
            'apply_to_pending' => ['boolean'],
        ], [], ['title' => 'descrizione', 'hours' => 'ore', 'starts_on' => 'inizio', 'ends_on' => 'scadenza', 'price' => 'prezzo']);

        ['contract' => $contract, 'covered' => $covered] = $addHourPackage->handle($order, $data, $request->user()->id, $request->boolean('apply_to_pending'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $covered > 0
            ? trans_choice('{1} Monte ore :number creato: scalato 1 intervento da fatturare.|[2,*] Monte ore :number creato: scalati :count interventi da fatturare.', $covered, ['number' => $contract->number, 'count' => $covered])
            : __('Monte ore :number creato: si fattura con l\'ordine.', ['number' => $contract->number])]);

        return back();
    }
}
