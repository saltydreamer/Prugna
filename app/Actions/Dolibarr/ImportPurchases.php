<?php

namespace App\Actions\Dolibarr;

use App\Enums\ExpenseType;
use App\Enums\OrganizationRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Support\CustomerSnapshot;
use App\Support\Dolibarr\DocumentLines;
use App\Support\Dolibarr\DolibarrMapper;
use App\Support\Dolibarr\DolibarrSource as Source;
use stdClass;

/**
 * Supplier orders → purchase orders, supplier invoices and credit notes → expenses (with the
 * payment date). Expenses already in Prugna (e.g. received through the SDI) are linked and kept.
 */
class ImportPurchases
{
    private DocumentLines $lines;

    public function __construct(private DolibarrMapper $map, private ImportThirdParties $thirdParties)
    {
        $this->lines = new DocumentLines($map);
    }

    public function run(): void
    {
        $this->purchaseOrders();
        $this->expenses();
    }

    private function purchaseOrders(): void
    {
        $this->map->report->section('purchase_orders', __('Ordini fornitori'));
        $customerOrders = $this->map->source->related('commande', 'order_supplier');

        foreach ($this->map->source->each('commande_fournisseur') as $row) {
            $supplier = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Supplier);

            if (! $supplier) {
                $this->missingThirdParty('purchase_orders', $row->ref);

                continue;
            }

            $purchaseOrder = $this->map->linked('commande_fournisseur', (int) $row->rowid, PurchaseOrder::class);
            $status = (int) $row->fk_statut;
            $orderDate = Source::date($row->date_commande ?? null) ?? Source::date($row->date_creation ?? null) ?? now()->toImmutable();
            $term = $this->map->paymentTerm($row->fk_cond_reglement ?? null, $row->fk_mode_reglement ?? null);
            $order = ($orderId = $this->map->linkedId('commande', $customerOrders[(int) $row->rowid][0] ?? null)) ? Order::query()->find($orderId) : null;

            $attributes = [
                'year' => $orderDate->year,
                'sequence' => $this->map->sequence(PurchaseOrder::class, $orderDate->year, $purchaseOrder),
                'number' => $this->map->number(PurchaseOrder::class, (string) $row->ref, $purchaseOrder),
                // 0 draft, 1 validated, 2 approved, 3 ordered, 4 partially received, 5 received, 6-7 cancelled, 9 refused.
                'status' => match (true) {
                    $status <= 2 => PurchaseOrderStatus::Draft,
                    $status === 3 => PurchaseOrderStatus::Sent,
                    $status === 4 => PurchaseOrderStatus::Confirmed,
                    $status === 5 => PurchaseOrderStatus::Received,
                    default => PurchaseOrderStatus::Cancelled,
                },
                'supplier_id' => $supplier->id,
                'owner_id' => $this->map->user($row->fk_user_author ?? null),
                'supplier' => CustomerSnapshot::from($supplier),
                'customer_id' => $order?->organization_id,
                'order_id' => $order?->id,
                'order_date' => $orderDate->toDateString(),
                'expected_date' => Source::date($row->date_livraison ?? null)?->toDateString(),
                'supplier_reference' => Source::line($row->ref_supplier ?? null, 64),
                'payment_term_id' => $term?->id,
                'payment_terms' => $term?->name,
                'notes' => Source::text($row->note_public ?? null),
                'internal_notes' => Source::join(Source::text($row->note_private ?? null), $status === 9 ? __('Rifiutato in Dolibarr.') : null),
                'sent_at' => $status >= 3 && $status <= 5 ? $orderDate : null,
                'subtotal' => Source::amount($row->total_ht ?? 0),
                'vat_total' => Source::amount($row->total_tva ?? 0),
                'total' => Source::amount($row->total_ttc ?? 0),
            ];

            if ($purchaseOrder) {
                $purchaseOrder->forceFill($attributes)->save();
                $purchaseOrder->lines()->delete();
                $this->map->report->count('purchase_orders', 'updated');
            } else {
                $purchaseOrder = PurchaseOrder::query()->forceCreate([...$attributes, 'created_at' => Source::date($row->date_creation ?? null) ?? $orderDate]);
                $this->map->report->count('purchase_orders', 'created');
            }

            $this->lines->save($purchaseOrder->lines(), $this->lines->build($this->map->source->children('commande_fournisseurdet', 'fk_commande', (int) $row->rowid), (string) $row->ref));
            $this->map->remember('commande_fournisseur', (int) $row->rowid, $purchaseOrder, $row->ref);
        }
    }

    private function expenses(): void
    {
        $this->map->report->section('expenses', __('Spese (fatture fornitori)'));

        foreach ($this->map->source->each('facture_fourn') as $row) {
            $supplier = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Supplier);

            if (! $supplier) {
                $this->missingThirdParty('expenses', $row->ref_supplier ?? $row->ref);

                continue;
            }

            $type = (int) $row->type === 2 ? ExpenseType::CreditNote : ExpenseType::Invoice;
            $sign = $type === ExpenseType::CreditNote && (float) $row->total_ht < 0 ? -1 : 1;
            $number = Source::line($row->ref_supplier ?? null, 64) ?? Source::line($row->ref, 64);
            $documentDate = Source::date($row->datef ?? null) ?? Source::date($row->datec ?? null) ?? now()->toImmutable();
            $expense = $this->map->linked('facture_fourn', (int) $row->rowid, Expense::class);

            if (! $expense && ($existing = $this->existing($supplier, $number, $documentDate->year))) {
                // Recorded in Prugna too (e.g. received through the SDI): linked, Prugna's version is kept.
                $this->map->remember('facture_fourn', (int) $row->rowid, $existing, $row->ref);
                $this->map->report->count('expenses', 'linked');

                continue;
            }

            if ($expense && $expense->source !== 'dolibarr') {
                $this->map->report->count('expenses', 'linked');

                continue;
            }

            $paidAt = null;

            if ((int) ($row->paye ?? 0) === 1) {
                $dates = $this->map->source->children('paiementfourn_facturefourn', 'fk_facturefourn', (int) $row->rowid)
                    ->map(fn (stdClass $payment) => Source::date($this->map->source->dictionary('paiementfourn', Source::int($payment->fk_paiementfourn))->datep ?? null))
                    ->filter();
                $paidAt = $dates->max() ?? Source::date($row->date_closing ?? null) ?? $documentDate;
            }

            $attributes = [
                'type' => $type,
                'supplier_id' => $supplier->id,
                'supplier' => ['name' => $supplier->name, 'vat_number' => $supplier->vat_number, 'tax_code' => $supplier->tax_code],
                'number' => $number,
                'document_date' => $documentDate->toDateString(),
                'title' => Source::line($row->libelle ?? null, 255),
                'payment_method' => $this->map->paymentMethod($row->fk_mode_reglement ?? null),
                'due_date' => Source::date($row->date_lim_reglement ?? null)?->toDateString(),
                'paid_at' => $paidAt?->toDateString(),
                'subtotal' => Source::amount($sign * (float) ($row->total_ht ?? 0)),
                'vat_total' => Source::amount($sign * (float) ($row->total_tva ?? 0)),
                'total' => Source::amount($sign * (float) ($row->total_ttc ?? 0)),
                'source' => 'dolibarr',
                'owner_id' => $this->map->user($row->fk_user_author ?? null),
                'notes' => Source::join(
                    Source::text($row->note_public ?? null),
                    Source::text($row->note_private ?? null),
                    (int) $row->fk_statut === 0 ? __('Bozza in Dolibarr.') : null,
                    (int) $row->fk_statut === 3 ? __('Chiusa in Dolibarr senza pagamento completo.') : null,
                ),
            ];

            if ($expense) {
                $expense->forceFill($attributes)->save();
                $expense->lines()->delete();
                $this->map->report->count('expenses', 'updated');
            } else {
                $expense = Expense::query()->forceCreate([...$attributes, 'created_at' => Source::date($row->datec ?? null) ?? $documentDate]);
                $this->map->report->count('expenses', 'created');
            }

            $this->lines->save($expense->lines(), $this->lines->build($this->map->source->children('facture_fourn_det', 'fk_facture_fourn', (int) $row->rowid), (string) ($number ?? $row->ref), 'pu_ht', sign: $sign));
            $this->map->remember('facture_fourn', (int) $row->rowid, $expense, $row->ref);
        }
    }

    private function existing(Organization $supplier, ?string $number, int $year): ?Expense
    {
        if ($number === null) {
            return null;
        }

        return Expense::query()
            ->where('supplier_id', $supplier->id)
            ->where('number', $number)
            ->whereYear('document_date', $year)
            ->where('source', '!=', 'dolibarr')
            ->whereNotIn('id', $this->map->linkedModelIds('facture_fourn'))
            ->first();
    }

    private function missingThirdParty(string $section, mixed $reference): void
    {
        $this->map->report->count($section, 'skipped');
        $this->map->report->warn(__('Documenti senza terza parte valida: non importati.'), (string) $reference);
    }
}
