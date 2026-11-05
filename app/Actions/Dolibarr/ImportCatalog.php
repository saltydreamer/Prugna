<?php

namespace App\Actions\Dolibarr;

use App\Enums\BillingPeriod;
use App\Enums\OrganizationRole;
use App\Enums\ProductType;
use App\Models\CustomerPrice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatRate;
use App\Support\Dolibarr\DolibarrMapper;
use App\Support\Dolibarr\DolibarrSource as Source;
use stdClass;

/**
 * Products and services (with their first product category) and customer prices.
 */
class ImportCatalog
{
    /** @var array<int, int> Dolibarr product → first product category. */
    private array $categories = [];

    public function __construct(private DolibarrMapper $map, private ImportThirdParties $thirdParties) {}

    public function run(): void
    {
        $this->map->report->section('products', __('Prodotti e servizi'));
        $this->map->report->section('customer_prices', __('Prezzi cliente'));

        $this->categories();

        foreach ($this->map->source->rows('product') as $product) {
            $this->product($product);
        }

        $this->customerPrices();
    }

    private function categories(): void
    {
        // Type 0: product categories.
        $names = $this->map->source->rows('categorie')->where('type', 0)->mapWithKeys(fn (stdClass $category) => [(int) $category->rowid => Source::line($category->label, 255)])->filter();

        foreach ($this->map->source->rows('categorie_product', 'fk_product') as $link) {
            $name = $names[(int) $link->fk_categorie] ?? null;

            if ($name !== null && ! isset($this->categories[(int) $link->fk_product])) {
                $category = ProductCategory::query()->where('name', $name)->first() ?? ProductCategory::query()->forceCreate(['name' => $name]);
                $this->categories[(int) $link->fk_product] = $category->id;
            }
        }
    }

    private function product(stdClass $row): void
    {
        $type = (int) $row->fk_product_type === 1 ? ProductType::Service : ProductType::Product;
        $name = Source::line($row->label, 255) ?? Source::line($row->ref, 255) ?? __('Prodotto :id', ['id' => $row->rowid]);
        $code = Source::line($row->ref, 64) ?? Product::nextCode($type);
        $nature = $this->map->nature($row->tva_tx ?? 0, $row->default_vat_code ?? null, $name);

        $attributes = [
            'type' => $type,
            'name' => $name,
            'description' => Source::text($row->description ?? null),
            'category_id' => $this->categories[(int) $row->rowid] ?? null,
            'unit' => $this->map->unit($row->fk_unit ?? null) ?? DolibarrMapper::defaultUnit($type, $name),
            'price' => Source::amount($row->price ?? 0, 4),
            'cost' => (float) ($row->cost_price ?? 0) > 0 ? Source::amount($row->cost_price, 4) : ((float) ($row->pmp ?? 0) > 0 ? Source::amount($row->pmp, 4) : null),
            'vat_rate_id' => $this->map->vatRate($row->tva_tx ?? 0, $nature) ?? VatRate::query()->where('is_default', true)->value('id') ?? VatRate::query()->value('id'),
            'billing_period' => $type === ProductType::Service ? $this->billingPeriod($row->duration ?? null) : null,
            'is_active' => (int) ($row->tosell ?? 1) === 1 || (int) ($row->tobuy ?? 0) === 1,
            'notes' => Source::join(Source::text($row->note_public ?? null), Source::text($row->note ?? null)),
        ];

        $product = $this->map->linked('product', (int) $row->rowid, Product::class);

        if ($product) {
            $product->forceFill($attributes)->save();
            $this->map->report->count('products', 'updated');
        } elseif ($product = Product::query()->where('code', $code)->first()) {
            // A product with the same code is already in Prugna: linked and aligned to Dolibarr.
            $product->forceFill($attributes)->save();
            $this->map->report->count('products', 'linked');
        } else {
            $product = Product::query()->forceCreate([...$attributes, 'code' => $code, 'created_at' => Source::date($row->datec ?? null) ?? now()]);
            $this->map->report->count('products', 'created');
        }

        $this->map->remember('product', (int) $row->rowid, $product, $row->ref);
    }

    private function billingPeriod(mixed $duration): ?BillingPeriod
    {
        // Dolibarr durations: "1m", "3m", "6m", "1y", "12m".
        return match (strtolower(trim((string) $duration))) {
            '1m' => BillingPeriod::Monthly,
            '3m' => BillingPeriod::Quarterly,
            '6m' => BillingPeriod::Semiannual,
            '1y', '12m' => BillingPeriod::Annual,
            default => null,
        };
    }

    private function customerPrices(): void
    {
        // Oldest first: when a customer has more prices for a product, the latest one wins.
        $rows = $this->map->source->rows('product_customer_price')
            ->sortBy([fn ($a, $b) => strcmp((string) $a->date_begin, (string) $b->date_begin) ?: (int) $a->rowid <=> (int) $b->rowid]);

        foreach ($rows as $row) {
            $productId = $this->map->linkedId('product', Source::int($row->fk_product));
            $organization = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Customer);

            if (! $productId || ! $organization) {
                $this->map->report->count('customer_prices', 'skipped');

                continue;
            }

            $price = (float) ($row->price ?? 0);
            $discount = (float) ($row->discount_percent ?? 0);

            $customerPrice = CustomerPrice::query()->firstOrNew(['organization_id' => $organization->id, 'product_id' => $productId]);
            $outcome = $customerPrice->exists ? 'updated' : 'created';
            $customerPrice->forceFill([
                'organization_id' => $organization->id,
                'product_id' => $productId,
                'price' => $price > 0 || $discount <= 0 ? Source::amount($price, 4) : null,
                'discount_percent' => $price > 0 || $discount <= 0 ? null : Source::amount(min(100, $discount)),
                'valid_until' => Source::date($row->date_end ?? null)?->toDateString(),
                'notes' => Source::line($row->ref_customer ?? null, 500) ? __('Codice cliente: :code', ['code' => Source::line($row->ref_customer, 400)]) : null,
            ])->save();

            $this->map->report->count('customer_prices', $outcome);
            $this->map->remember('product_customer_price', (int) $row->rowid, $customerPrice);
        }
    }
}
