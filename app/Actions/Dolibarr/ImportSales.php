<?php

namespace App\Actions\Dolibarr;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\QuoteLineType;
use App\Enums\QuoteStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Quote;
use App\Support\CustomerSnapshot;
use App\Support\Dolibarr\DocumentLines;
use App\Support\Dolibarr\DolibarrMapper;
use App\Support\Dolibarr\DolibarrSource as Source;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use stdClass;

/**
 * Proposals → quotes, customer orders → orders, invoices and credit notes → invoices.
 *
 * Issued invoices keep Dolibarr's number; their progressive (the digits at the end of the
 * number) becomes the sequence of the year, so Prugna's next invoice follows the last one.
 */
class ImportSales
{
    private DocumentLines $lines;

    /** @var array<int, true> Orders linked to imported invoices. */
    private array $invoicedOrders = [];

    public function __construct(private DolibarrMapper $map, private ImportThirdParties $thirdParties)
    {
        $this->lines = new DocumentLines($map);
    }

    public function run(): void
    {
        $this->quotes();
        $this->orders();
        $this->invoices();
    }

    private function quotes(): void
    {
        $report = $this->map->report;
        $report->section('quotes', __('Preventivi'));

        foreach ($this->map->source->each('propal') as $row) {
            $organization = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Customer);

            if (! $organization) {
                $this->missingThirdParty('quotes', $row->ref);

                continue;
            }

            $quote = $this->map->linked('propal', (int) $row->rowid, Quote::class);
            $issueDate = Source::date($row->datep ?? null) ?? Source::date($row->datec ?? null) ?? now()->toImmutable();
            $validUntil = Source::date($row->fin_validite ?? null) ?? $issueDate->addDays(30);
            $status = match ((int) $row->fk_statut) {
                0 => QuoteStatus::Draft,
                1 => $validUntil->isPast() ? QuoteStatus::Expired : QuoteStatus::Sent,
                2, 4 => QuoteStatus::Accepted,
                default => QuoteStatus::Rejected,
            };
            $term = $this->map->paymentTerm($row->fk_cond_reglement ?? null, $row->fk_mode_reglement ?? null);
            $decidedAt = in_array($status, [QuoteStatus::Accepted, QuoteStatus::Rejected], true)
                ? (Source::date($row->date_signature ?? null) ?? Source::date($row->date_cloture ?? null))
                : null;

            $attributes = [
                'number' => $this->map->number(Quote::class, (string) $row->ref, $quote),
                'status' => $status,
                'organization_id' => $organization->id,
                'owner_id' => $this->map->user($row->fk_user_author ?? null),
                'customer' => CustomerSnapshot::from($organization),
                'title' => ($reference = Source::line($row->ref_client ?? null, 200)) ? __('Rif. cliente: :reference', ['reference' => $reference]) : null,
                'issue_date' => $issueDate->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'payment_term_id' => $term?->id,
                'payment_terms' => $term?->name,
                'intro' => Source::text($row->note_public ?? null),
                'internal_notes' => Source::join(Source::text($row->note_private ?? null), (int) $row->fk_statut === -1 ? __('Annullato in Dolibarr.') : null),
                'subtotal' => Source::amount($row->total_ht ?? 0),
                'vat_total' => Source::amount($row->total_tva ?? 0),
                'total' => Source::amount($row->total_ttc ?? 0),
                'sent_at' => $status !== QuoteStatus::Draft ? (Source::date($row->date_valid ?? null) ?? $issueDate) : null,
                'decided_at' => $decidedAt,
                'decision_name' => Source::line($row->online_sign_name ?? null, 255),
            ];

            if ($quote) {
                $quote->forceFill($attributes)->save();
                $quote->lines()->delete();
                $this->map->report->count('quotes', 'updated');
            } else {
                $quote = Quote::query()->forceCreate([
                    ...$attributes,
                    'sequence' => (int) Quote::query()->max('sequence') + 1,
                    'created_at' => Source::date($row->datec ?? null) ?? $issueDate,
                ]);
                $this->map->report->count('quotes', 'created');
            }

            $this->lines->save($quote->lines(), $this->lines->build($this->map->source->children('propaldet', 'fk_propal', (int) $row->rowid), (string) $row->ref, sections: true));
            $this->map->remember('propal', (int) $row->rowid, $quote, $row->ref);
        }
    }

    private function orders(): void
    {
        $this->map->report->section('orders', __('Ordini clienti'));
        $quotes = $this->map->source->related('propal', 'commande');

        foreach ($this->map->source->each('commande') as $row) {
            $organization = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Customer);

            if (! $organization) {
                $this->missingThirdParty('orders', $row->ref);

                continue;
            }

            $order = $this->map->linked('commande', (int) $row->rowid, Order::class);
            $orderDate = Source::date($row->date_commande ?? null) ?? Source::date($row->date_creation ?? null) ?? now()->toImmutable();
            $term = $this->map->paymentTerm($row->fk_cond_reglement ?? null, $row->fk_mode_reglement ?? null);
            $quoteId = $this->map->linkedId('propal', $quotes[(int) $row->rowid][0] ?? null);

            if ($quoteId && Order::query()->where('quote_id', $quoteId)->when($order, fn ($query) => $query->whereKeyNot($order->id))->exists()) {
                $quoteId = null;
            }

            $attributes = [
                'year' => $orderDate->year,
                'sequence' => $this->map->sequence(Order::class, $orderDate->year, $order),
                'number' => $this->map->number(Order::class, (string) $row->ref, $order),
                'status' => match ((int) $row->fk_statut) {
                    -1 => OrderStatus::Cancelled,
                    2 => OrderStatus::InProgress,
                    3 => OrderStatus::Completed,
                    default => OrderStatus::Open,
                },
                'quote_id' => $quoteId,
                'organization_id' => $organization->id,
                'owner_id' => $this->map->user($row->fk_user_author ?? null),
                'customer' => CustomerSnapshot::from($organization),
                'order_date' => $orderDate->toDateString(),
                'delivery_date' => Source::date($row->date_livraison ?? null)?->toDateString(),
                'customer_reference' => Source::line($row->ref_client ?? null, 64),
                'payment_term_id' => $term?->id,
                'payment_terms' => $term?->name,
                'notes' => Source::text($row->note_public ?? null),
                'internal_notes' => Source::join(Source::text($row->note_private ?? null), (int) $row->fk_statut === 0 ? __('Bozza in Dolibarr.') : null),
                'subtotal' => Source::amount($row->total_ht ?? 0),
                'vat_total' => Source::amount($row->total_tva ?? 0),
                'total' => Source::amount($row->total_ttc ?? 0),
            ];

            if ($order) {
                $order->forceFill($attributes)->save();
                $order->lines()->delete();
                $this->map->report->count('orders', 'updated');
            } else {
                $order = Order::query()->forceCreate([...$attributes, 'created_at' => Source::date($row->date_creation ?? null) ?? $orderDate]);
                $this->map->report->count('orders', 'created');
            }

            $this->lines->save($order->lines(), $this->lines->build($this->map->source->children('commandedet', 'fk_commande', (int) $row->rowid), (string) $row->ref, sections: true));
            $this->map->remember('commande', (int) $row->rowid, $order, $row->ref);
        }
    }

    private function invoices(): void
    {
        $report = $this->map->report;
        $report->section('invoices', __('Fatture e note di credito'));
        $quotes = $this->map->source->related('propal', 'facture');
        $orders = $this->map->source->related('commande', 'facture');
        // Credit notes and replacements refer to an earlier invoice: invoices first.
        $rows = $this->map->source->each('facture', fn ($query) => $query->where(fn ($query) => $query->whereNull('fk_facture_source')->orWhere('fk_facture_source', '<=', 0)))
            ->concat($this->map->source->each('facture', fn ($query) => $query->where('fk_facture_source', '>', 0)));

        foreach ($rows as $row) {
            $organization = $this->thirdParties->organization($row->fk_soc, OrganizationRole::Customer);

            if (! $organization) {
                $this->missingThirdParty('invoices', $row->ref);

                continue;
            }

            $invoice = $this->map->linked('facture', (int) $row->rowid, Invoice::class);

            if ($invoice && ! $invoice->isDraft() && ($invoice->number !== mb_substr((string) $row->ref, 0, 32) || $invoice->hasReachedSdi())) {
                // Issued or sent to the SDI from Prugna since the last import: Prugna's invoice is kept.
                $report->count('invoices', 'linked');

                continue;
            }

            $type = match ((int) $row->type) {
                2 => InvoiceType::CreditNote,
                3 => InvoiceType::Deposit,
                default => InvoiceType::Invoice,
            };
            $draft = (int) $row->fk_statut === 0;
            $series = $type === InvoiceType::CreditNote ? 'NC' : 'FT';
            $issueDate = Source::date($row->datef ?? null) ?? Source::date($row->datec ?? null) ?? now()->toImmutable();
            $numbering = $draft ? ['year' => null, 'sequence' => null, 'number' => null] : $this->numbering($row, $series, $issueDate->year, $invoice);

            if ($numbering === null) {
                continue;
            }

            $term = $this->map->paymentTerm($row->fk_cond_reglement ?? null, $row->fk_mode_reglement ?? null);
            $orderIds = array_values(array_filter(array_map(fn (int $id) => $this->map->linkedId('commande', $id), $orders[(int) $row->rowid] ?? [])));
            $sign = $type === InvoiceType::CreditNote && (float) $row->total_ht < 0 ? -1 : 1;

            if ((float) ($row->localtax1 ?? 0) != 0 || (float) ($row->localtax2 ?? 0) != 0) {
                $report->warn(__('Fatture con tasse locali di Dolibarr (localtax): gli importi sono riportati, ma le tasse locali non hanno un equivalente in Prugna.'), $row->ref);
            }

            if (! in_array(strtoupper(trim((string) ($row->multicurrency_code ?? ''))), ['', 'EUR'], true)) {
                $report->warn(__('Fatture in valuta estera: importate con gli importi in euro.'), $row->ref);
            }

            $attributes = [
                ...$numbering,
                'type' => $type,
                'status' => $draft ? InvoiceStatus::Draft : InvoiceStatus::Issued,
                'series' => $series,
                'issue_date' => $issueDate->toDateString(),
                'issued_at' => $draft ? null : (Source::date($row->date_valid ?? null) ?? $issueDate),
                'organization_id' => $organization->id,
                'owner_id' => $this->map->user($row->fk_user_author ?? null),
                'customer' => CustomerSnapshot::from($organization),
                'quote_id' => $this->map->linkedId('propal', $quotes[(int) $row->rowid][0] ?? null),
                'order_id' => $orderIds[0] ?? null,
                'related_invoice_id' => $this->map->linkedId('facture', Source::int($row->fk_facture_source ?? null)),
                'customer_reference' => Source::line($row->ref_client ?? null, 64),
                'payment_term_id' => $term?->id,
                'payment_terms' => $term?->name,
                'payment_method' => $this->map->fatturaPaMethod($row->fk_mode_reglement ?? null) ?? $term?->method,
                'due_date' => Source::date($row->date_lim_reglement ?? null)?->toDateString(),
                'notes' => Source::text($row->note_public ?? null),
                'internal_notes' => Source::join(Source::text($row->note_private ?? null), $this->paymentNotes($row, $this->map->source->children('paiement_facture', 'fk_facture', (int) $row->rowid))),
                'regime' => $this->map->regime,
                'stamp_duty_amount' => Source::amount($row->revenuestamp ?? 0),
                'subtotal' => Source::amount($sign * (float) ($row->total_ht ?? 0)),
                'vat_total' => Source::amount($sign * (float) ($row->total_tva ?? 0)),
                'total' => Source::amount($sign * (float) ($row->total_ttc ?? 0)),
                'amount_due' => Source::amount($sign * (float) ($row->total_ttc ?? 0)),
            ];

            // Issued in Dolibarr: already transmitted there. A choice made in Prugna afterwards
            // ("da inviare allo SDI") is kept when importing again.
            if (! $invoice || $invoice->isDraft()) {
                $attributes['sdi_external'] = ! $draft;
            }

            if ($invoice) {
                $invoice->forceFill($attributes)->save();
                $invoice->lines()->delete();
                $report->count('invoices', 'updated');
            } else {
                $invoice = Invoice::query()->forceCreate([...$attributes, 'created_at' => Source::date($row->datec ?? null) ?? $issueDate]);
                $report->count('invoices', 'created');
            }

            $this->lines->save($invoice->lines(), $this->lines->build($this->map->source->children('facturedet', 'fk_facture', (int) $row->rowid), (string) $row->ref, sign: $sign));
            $this->map->remember('facture', (int) $row->rowid, $invoice, $row->ref);

            if ($orderIds && $type !== InvoiceType::CreditNote) {
                $this->linkOrderLines($invoice, $orderIds);
            }
        }

        $this->refreshOrders();
        $this->nextNumbers();
    }

    /**
     * Year, sequence and number of an issued invoice, or null when the number is already used
     * by an invoice made in Prugna (a blocking error: the import would break the numbering).
     *
     * @return array{year: int, sequence: ?int, number: string}|null
     */
    private function numbering(stdClass $row, string $series, int $year, ?Invoice $invoice): ?array
    {
        $reference = (string) $row->ref;
        $sequence = preg_match('/(\d+)\D*$/', $reference, $match) ? (int) $match[1] : null;
        $byNumber = Invoice::query()->where('number', mb_substr($reference, 0, 32))->when($invoice, fn ($query) => $query->whereKeyNot($invoice->id))->first();

        if ($byNumber && ! $this->map->isLinkedModel('facture', $byNumber->id)) {
            $this->map->report->error(__('Numeri di fattura già usati da fatture create in Prugna: elimina le fatture di prova o correggi la numerazione, poi ripeti la simulazione.'), $reference);

            return null;
        }

        if ($sequence !== null) {
            $taken = Invoice::query()->where('series', $series)->where('year', $year)->where('sequence', $sequence)
                ->when($invoice, fn ($query) => $query->whereKeyNot($invoice->id))->first();

            if ($taken && ! $this->map->isLinkedModel('facture', $taken->id)) {
                $this->map->report->error(__('Numeri di fattura già usati da fatture create in Prugna: elimina le fatture di prova o correggi la numerazione, poi ripeti la simulazione.'), "{$reference} = {$taken->number}");

                return null;
            }

            if ($taken) {
                $this->map->report->warn(__('Fatture Dolibarr con lo stesso progressivo nello stesso anno: importate senza progressivo, controlla la numerazione.'), $reference);
                $sequence = null;
            }
        } else {
            $this->map->report->warn(__('Fatture con un numero senza progressivo finale: importate senza progressivo, controlla la numerazione.'), $reference);
        }

        return ['year' => $year, 'sequence' => $sequence, 'number' => mb_substr($reference, 0, 32)];
    }

    /**
     * Payments and closing recorded in Dolibarr, kept in the internal notes (Prugna doesn't
     * track customer payments).
     *
     * @param  Collection<int, stdClass>  $payments
     */
    private function paymentNotes(stdClass $row, Collection $payments): ?string
    {
        $notes = [];

        foreach ($payments as $payment) {
            $header = $this->map->source->dictionary('paiement', Source::int($payment->fk_paiement));
            $mode = $this->map->source->dictionary('c_paiement', Source::int($header->fk_paiement ?? null), 'id');
            $notes[] = trim(implode(' · ', array_filter([
                Source::date($header->datep ?? null)?->format('d/m/Y'),
                Number::currency((float) $payment->amount, 'EUR', 'it'),
                Source::line($mode->libelle ?? null),
                Source::line($header->num_paiement ?? null),
            ])));
        }

        return Source::join(
            $notes ? __('Incassi registrati in Dolibarr:')."\n".implode("\n", $notes) : null,
            (int) ($row->paye ?? 0) === 1 ? __('Pagata (Dolibarr).') : null,
            (int) $row->fk_statut === 3 ? __('Chiusa in Dolibarr senza pagamento completo: :reason', ['reason' => Source::line($row->close_note ?? null) ?? Source::line($row->close_code ?? null) ?? '—']) : null,
        );
    }

    /**
     * Links invoice lines to the lines of the orders they bill (same product, or same
     * description), so that the orders show what is still to invoice.
     *
     * @param  list<int>  $orderIds
     */
    private function linkOrderLines(Invoice $invoice, array $orderIds): void
    {
        $orderLines = OrderLine::query()->whereIn('order_id', $orderIds)->where('type', QuoteLineType::Item->value)->orderBy('order_id')->orderBy('position')->get();
        $used = [];

        foreach ($invoice->lines()->where('type', QuoteLineType::Item->value)->orderBy('position')->get() as $line) {
            $match = $orderLines->first(fn (OrderLine $orderLine) => ! isset($used[$orderLine->id]) && ($line->product_id
                ? $orderLine->product_id === $line->product_id
                : trim($orderLine->description) === trim($line->description)));

            if ($match) {
                $used[$match->id] = true;
                $line->forceFill(['order_line_id' => $match->id])->saveQuietly();
            }
        }

        foreach ($orderIds as $orderId) {
            $this->invoicedOrders[$orderId] = true;
        }
    }

    /**
     * Invoiced quantities of the orders; orders billed in Dolibarr whose lines could not be
     * matched count as fully invoiced.
     */
    private function refreshOrders(): void
    {
        $billed = $this->map->source->each('commande', fn ($query) => $query->where('facture', 1))
            ->map(fn (stdClass $row) => $this->map->linkedId('commande', (int) $row->rowid))->filter()->values()->all();

        foreach (Order::query()->whereIn('id', array_keys($this->invoicedOrders))->get() as $order) {
            $order->refreshInvoicedQuantities();
        }

        foreach (Order::query()->whereIn('id', $billed)->get() as $order) {
            $order->lines()->where('type', QuoteLineType::Item->value)->whereColumn('quantity_invoiced', '<', 'quantity')->update(['quantity_invoiced' => DB::raw('quantity')]);
        }
    }

    private function nextNumbers(): void
    {
        $year = now()->year;

        foreach (['FT', 'NC'] as $series) {
            $this->map->report->nextNumber("{$series}-{$year}", (int) Invoice::query()->where('series', $series)->where('year', $year)->max('sequence') + 1);
        }
    }

    private function missingThirdParty(string $section, mixed $reference): void
    {
        $this->map->report->count($section, 'skipped');
        $this->map->report->warn(__('Documenti senza terza parte valida: non importati.'), (string) $reference);
    }
}
