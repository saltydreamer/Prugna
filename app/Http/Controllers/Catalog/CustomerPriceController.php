<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CustomerPriceRequest;
use App\Models\CustomerPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;

/**
 * Prices agreed with customers, managed from both the product and the organization page.
 */
class CustomerPriceController extends Controller
{
    public function store(CustomerPriceRequest $request): RedirectResponse
    {
        CustomerPrice::create(Arr::except($request->validated(), 'mode'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prezzo concordato salvato.')]);

        return back();
    }

    public function update(CustomerPriceRequest $request, CustomerPrice $customerPrice): RedirectResponse
    {
        $customerPrice->update(Arr::only($request->validated(), ['price', 'discount_percent', 'valid_until', 'notes']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prezzo concordato aggiornato.')]);

        return back();
    }

    public function destroy(CustomerPrice $customerPrice): RedirectResponse
    {
        $this->authorize('update', $customerPrice->product);

        $customerPrice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prezzo concordato eliminato.')]);

        return back();
    }
}
