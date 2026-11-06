<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Orders\SyncOrderHourPackages;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ServiceContract;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Contracts a monte ore for the package lines of an order saved before they were made
 * automatically (or imported): the lines don't change, the order may be invoiced already.
 */
class OrderHourPackageController extends Controller
{
    public function store(Order $order, SyncOrderHourPackages $hourPackages): RedirectResponse
    {
        $this->authorize('view', $order);
        $this->authorize('create', ServiceContract::class);

        $created = $hourPackages->handle($order, updateExisting: false);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{0} Nessun monte ore da creare.|{1} Creato il contratto monte ore dell\'ordine :order.|[2,*] Creati :count contratti monte ore dell\'ordine :order.',
            $created,
            ['order' => $order->number, 'count' => $created],
        )]);

        return back();
    }
}
