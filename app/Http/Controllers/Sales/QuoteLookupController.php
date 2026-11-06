<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Catalog\ResolvePrice;
use App\Actions\Invoices\SaveInvoice;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints used by the document editors (quotes, orders, invoices).
 */
class QuoteLookupController extends Controller
{
    /**
     * Contacts, locations and default payment term of a customer.
     */
    public function organization(Organization $organization, SaveInvoice $saveInvoice): JsonResponse
    {
        $this->authorizeEditor();

        return response()->json([
            'payment_term_id' => $organization->payment_term_id,
            'invoice_defaults' => $saveInvoice->defaultsFor($organization),
            'contacts' => $organization->contacts()->orderByDesc('is_primary')->orderBy('last_name')->get()
                ->map(fn ($contact) => [
                    'value' => $contact->id,
                    'title' => $contact->full_name,
                    'subtitle' => $contact->email,
                    'is_primary' => $contact->is_primary,
                ]),
            'locations' => $organization->locations()->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name', 'city']),
        ]);
    }

    /**
     * Active products with the price that applies to the customer.
     */
    public function products(Request $request, ResolvePrice $resolvePrice): JsonResponse
    {
        $this->authorizeEditor();

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'organization_id' => ['nullable', 'integer'],
            'at_cost' => ['boolean'],
        ]);

        $atCost = $request->boolean('at_cost');

        $organization = $request->integer('organization_id') ? Organization::find($request->integer('organization_id')) : null;

        $products = Product::query()
            ->active()
            ->with('vatRate')
            ->search($request->input('search'))
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->map(function (Product $product) use ($organization, $resolvePrice, $atCost) {
                // Supplier orders are priced at the purchase cost.
                $price = $atCost
                    ? ['price' => (float) ($product->cost ?? 0), 'list_price' => (float) ($product->cost ?? 0), 'source' => 'cost']
                    : $resolvePrice->handle($product, $organization);

                return [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'description' => $product->description,
                    'type' => $product->type->value,
                    'unit' => $product->unit->value,
                    'billing_period_per' => $product->billing_period?->perLabel(),
                    'hour_package' => $product->hour_package,
                    'package_months' => $product->package_months,
                    'price' => $price['price'],
                    'list_price' => $price['list_price'],
                    'price_source' => $price['source'],
                    'vat_rate_id' => $product->vat_rate_id,
                ];
            });

        return response()->json(['data' => $products]);
    }

    private function authorizeEditor(): void
    {
        $user = request()->user();

        abort_unless(
            $user->can('create', Quote::class)
            || $user->can(Permission::OrdersManage->value)
            || $user->can(Permission::InvoicesManage->value)
            || $user->can(Permission::SubscriptionsManage->value)
            || $user->can(Permission::PurchasesManage->value)
            || $user->can(Permission::InterventionsManage->value),
            403,
        );
    }
}
