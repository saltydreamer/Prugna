<?php

namespace App\Actions\Expenses;

use App\Actions\Accounting\SyncExpensePayment;
use App\Actions\Documents\ExpenseDraft;
use App\Enums\LocationType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AiExtraction;
use App\Models\Expense;
use App\Models\ExpenseImport;
use App\Models\ExpenseImportItem;
use App\Models\Organization;
use App\Models\ReceivedInvoice;
use App\Models\User;
use App\Notifications\ExpenseImportNotification;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use App\Support\Ai\DocumentReader;
use App\Support\Settings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Records one document of an expense import without asking: reads it (XML directly, the rest with
 * the AI), takes the supplier from the registry, creates it from the document when it is new, or
 * uses the generic supplier when it can't be read, and saves the expense. A document already
 * recorded is skipped; one without amounts fails and can be completed by hand from its reading.
 */
class ImportExpenseDocument
{
    /** Setting holding the id of the «Fornitore generico». */
    public const GENERIC_SUPPLIER = 'expenses.generic_supplier_id';

    public function __construct(
        private DocumentReader $reader,
        private ExpenseDraft $draft,
        private SaveExpense $save,
        private SyncExpensePayment $syncPayment,
        private Settings $settings,
    ) {}

    public function handle(ExpenseImportItem $item): void
    {
        $item->forceFill(['status' => ExpenseImportItem::PROCESSING, 'error' => null])->save();
        $user = $item->import->user;

        $extraction = $item->extraction?->succeeded()
            ? $item->extraction
            : $this->reader->readStored(['path' => $item->file_path, 'name' => $item->original_name, 'mime_type' => (string) $item->mime_type], AiExtraction::KIND_EXPENSE, $user);
        $item->forceFill(['ai_extraction_id' => $extraction->id])->save();

        if (! $extraction->succeeded()) {
            $this->fail($item, (string) $extraction->error);

            return;
        }

        ['defaults' => $defaults, 'review' => $review] = $this->draft->fromExtraction($extraction);
        $warnings = $review['warnings'];

        if ($review['duplicate']) {
            $item->forceFill(['status' => ExpenseImportItem::DUPLICATE, 'expense_id' => $review['duplicate']['id'], 'supplier_id' => $defaults['supplier_id']])->save();

            return;
        }

        if ($defaults['lines'] === [] || collect($defaults['lines'])->contains(fn (array $line) => blank($line['vat_rate_id']))) {
            $this->fail($item, __('Importi o aliquote IVA non leggibili: completa la spesa a mano.'));

            return;
        }

        if (blank($extraction->result['date'] ?? null)) {
            $warnings[] = __('Data del documento non letta: è stata usata quella di oggi.');
        }

        [$supplier, $source] = $this->supplier($defaults['supplier_id'], $review['supplier_suggestion'], $user, $warnings);

        $expense = DB::transaction(function () use ($defaults, $supplier, $user): Expense {
            $expense = $this->save->handle(new Expense, [...$defaults, 'supplier_id' => $supplier->id, 'owner_id' => $user?->id]);
            $this->syncPayment->handle($expense, null);

            // An e-invoice of the "Fatture ricevute" inbox is now recorded.
            ReceivedInvoice::query()->where('ai_extraction_id', $expense->ai_extraction_id)->whereNull('expense_id')
                ->update(['expense_id' => $expense->id, 'status' => ReceivedInvoice::STATUS_RECORDED]);

            return $expense;
        });

        $item->forceFill([
            'status' => ExpenseImportItem::RECORDED,
            'expense_id' => $expense->id,
            'supplier_id' => $supplier->id,
            'supplier_source' => $source,
            'warnings' => array_values(array_unique($warnings)) ?: null,
        ])->save();
    }

    public function fail(ExpenseImportItem $item, string $error): void
    {
        $item->forceFill(['status' => ExpenseImportItem::FAILED, 'error' => mb_substr($error, 0, 2000)])->save();
    }

    /**
     * Done when every document has been handled: the user who uploaded them is told once.
     */
    public function completeIfDone(ExpenseImport $import): void
    {
        if (! $import->isStarted() || $import->items()->whereIn('status', [ExpenseImportItem::PENDING, ExpenseImportItem::PROCESSING])->exists()) {
            return;
        }

        $completed = ExpenseImport::query()->whereKey($import->id)->whereNull('completed_at')->update(['completed_at' => now()]);

        if ($completed && $import->user) {
            $import->user->notify(new ExpenseImportNotification($import->fresh()));
        }
    }

    /**
     * The supplier read from the document: found in the registry, created from the document
     * (when the user may create suppliers), or the generic one.
     *
     * @param  array<string, mixed>|null  $suggestion
     * @param  list<string>  $warnings
     * @return array{0: Organization, 1: string}
     */
    private function supplier(?int $supplierId, ?array $suggestion, ?User $user, array &$warnings): array
    {
        if ($supplierId && ($supplier = Organization::withTrashed()->find($supplierId))) {
            return [$supplier, ExpenseImportItem::SUPPLIER_MATCHED];
        }

        if ($suggestion && $user?->can('create', [Organization::class, OrganizationRole::Supplier])) {
            // Two documents of the same new supplier must not create it twice.
            return Cache::lock('expense-import-supplier', 30)->block(30, fn () => $this->createSupplier($suggestion, $user, $warnings));
        }

        $warnings[] = $suggestion
            ? __('Fornitore «:name» non creato (serve il permesso di creare fornitori): la spesa è sul fornitore generico.', ['name' => $suggestion['name']])
            : __('La spesa è sul fornitore generico: assegna quello giusto.');

        return [$this->genericSupplier(), ExpenseImportItem::SUPPLIER_GENERIC];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @param  list<string>  $warnings
     * @return array{0: Organization, 1: string}
     */
    private function createSupplier(array $suggestion, User $user, array &$warnings): array
    {
        $vatNumber = $this->valid($suggestion['vat_number'] ?? null, new ItalianVatNumber, $warnings, __('La partita IVA letta (:value) non è valida: non è stata salvata nel fornitore.', ['value' => $suggestion['vat_number'] ?? '']));
        $taxCode = $this->valid($suggestion['tax_code'] ?? null, new ItalianTaxCode, $warnings, __('Il codice fiscale letto (:value) non è valido: non è stato salvato nel fornitore.', ['value' => $suggestion['tax_code'] ?? '']));

        // Created meanwhile by another document of the import.
        $existing = Organization::query()->where('role', OrganizationRole::Supplier->value)
            ->where(fn ($query) => $vatNumber || $taxCode
                ? $query->when($vatNumber, fn ($query) => $query->orWhere('vat_number', $vatNumber))->when($taxCode, fn ($query) => $query->orWhere('tax_code', $taxCode))
                : $query->where('name', $suggestion['name']))
            ->first();

        if ($existing) {
            return [$existing, ExpenseImportItem::SUPPLIER_MATCHED];
        }

        $supplier = new Organization([
            'name' => $suggestion['name'],
            'type' => OrganizationType::Company,
            'role' => OrganizationRole::Supplier,
            'vat_number' => $vatNumber,
            'tax_code' => $taxCode,
            'email' => $this->email($suggestion['email'] ?? null),
            'pec' => $this->email($suggestion['pec'] ?? null),
            'phone' => $suggestion['phone'] ?? null,
        ]);
        $supplier->created_by = $user->id;
        $supplier->save();

        $location = (array) ($suggestion['location'] ?? []);

        if (filled($location['address'] ?? null)) {
            $supplier->locations()->create([
                ...collect($location)->only(['address', 'postal_code', 'city', 'province', 'country'])->all(),
                'name' => __('Sede legale'),
                'type' => LocationType::Registered,
            ]);
        }

        $warnings[] = __('Nuovo fornitore «:name» creato dal documento: controllane i dati.', ['name' => $supplier->name]);

        return [$supplier, ExpenseImportItem::SUPPLIER_CREATED];
    }

    /**
     * The «Fornitore generico», created the first time it is needed.
     */
    private function genericSupplier(): Organization
    {
        return Cache::lock('expense-import-generic-supplier', 30)->block(30, function (): Organization {
            $id = $this->settings->get(self::GENERIC_SUPPLIER);
            $supplier = $id ? Organization::withTrashed()->where('role', OrganizationRole::Supplier->value)->find($id) : null;

            if ($supplier?->trashed()) {
                $supplier->restore();
            }

            if (! $supplier) {
                $supplier = Organization::create([
                    'name' => __('Fornitore generico'),
                    'type' => OrganizationType::Other,
                    'role' => OrganizationRole::Supplier,
                    'notes' => __('Usato per le spese caricate in blocco quando il fornitore non è leggibile: assegna poi alla spesa il fornitore corretto.'),
                ]);
                $this->settings->set([self::GENERIC_SUPPLIER => $supplier->id]);
            }

            return $supplier;
        });
    }

    /**
     * A VAT number or tax code read by the AI is kept only when its check digit is right.
     *
     * @param  list<string>  $warnings
     */
    private function valid(?string $value, ValidationRule $rule, array &$warnings, string $invalid): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (Validator::make(['value' => $value], ['value' => [$rule]])->fails()) {
            $warnings[] = $invalid;

            return null;
        }

        return $value;
    }

    private function email(mixed $value): ?string
    {
        return is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($value)) : null;
    }
}
