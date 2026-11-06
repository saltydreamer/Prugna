<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountingCategoryKind;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AccountingCategory;
use App\Models\BusinessLine;
use App\Support\Accounting\FlatRateTaxEstimate;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contabilità → Categorie e attività: the user's categories (with their business share), the
 * lines of business and the parameters of the flat-rate tax estimate.
 */
class AccountingSettingsController extends Controller
{
    public function index(FlatRateTaxEstimate $estimate, Settings $settings): Response
    {
        $this->authorize(Permission::AccountingManage->value);

        return Inertia::render('accounting/Settings', [
            'categories' => AccountingCategory::query()->ordered()->withCount(['expenses', 'movements'])->get()
                ->map(fn (AccountingCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'kind' => $category->kind->value,
                    'kind_label' => $category->kind->label(),
                    'business_share' => (float) $category->business_share,
                    'is_active' => $category->is_active,
                    'used' => $category->expenses_count + $category->movements_count,
                ]),
            'lines' => BusinessLine::query()->ordered()->withCount(['products', 'expenses'])->get()
                ->map(fn (BusinessLine $line) => [
                    'id' => $line->id,
                    'name' => $line->name,
                    'is_active' => $line->is_active,
                    'products' => $line->products_count,
                    'expenses' => $line->expenses_count,
                ]),
            'kinds' => AccountingCategoryKind::options(),
            'tax' => $estimate->parameters(),
            'inpsOptions' => FlatRateTaxEstimate::inpsOptions(),
            'flatRate' => $estimate->applies(),
            'regime' => $settings->get('fiscal.regime'),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        AccountingCategory::query()->create([...$this->validatedCategory($request), 'sort' => (int) AccountingCategory::query()->max('sort') + 1]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria creata.')]);

        return back();
    }

    public function updateCategory(Request $request, AccountingCategory $category): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $category->update($this->validatedCategory($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria salvata.')]);

        return back();
    }

    /**
     * Categories in use stay (they can be deactivated).
     */
    public function destroyCategory(AccountingCategory $category): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);
        abort_if($category->isUsed(), 409, __('La categoria è usata da spese o movimenti: disattivala invece di eliminarla.'));

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria eliminata.')]);

        return back();
    }

    public function storeLine(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        BusinessLine::query()->create([...$this->validatedLine($request), 'sort' => (int) BusinessLine::query()->max('sort') + 1]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linea di attività creata.')]);

        return back();
    }

    public function updateLine(Request $request, BusinessLine $line): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $line->update($this->validatedLine($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linea di attività salvata.')]);

        return back();
    }

    /**
     * Products, expenses and movements of the line become unassigned.
     */
    public function destroyLine(BusinessLine $line): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $line->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linea di attività eliminata.')]);

        return back();
    }

    public function updateTax(Request $request, Settings $settings): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $data = $request->validate([
            'coefficient' => ['required', 'numeric', 'between:1,100'],
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'inps_regime' => ['required', Rule::in(array_column(FlatRateTaxEstimate::inpsOptions(), 'value'))],
            'inps_rate' => ['required', 'numeric', 'between:0,100'],
            'inps_minimum_income' => ['required', 'numeric', 'between:0,1000000'],
            'inps_fixed' => ['required', 'numeric', 'between:0,1000000'],
            'inps_reduction' => ['boolean'],
        ], [], [
            'coefficient' => 'coefficiente di redditività',
            'tax_rate' => 'aliquota imposta sostitutiva',
            'inps_rate' => 'aliquota contributi',
            'inps_minimum_income' => 'reddito minimale',
            'inps_fixed' => 'contributi fissi',
        ]);

        $settings->set(collect($data)->mapWithKeys(fn ($value, string $key) => ["accounting.{$key}" => $key === 'inps_reduction' ? (bool) $value : $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Parametri salvati.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCategory(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(AccountingCategoryKind::class)],
            'business_share' => ['nullable', 'numeric', 'between:0,100'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nome', 'kind' => 'tipo', 'business_share' => 'quota aziendale']);

        return [...$data, 'business_share' => $data['business_share'] ?? 100, 'is_active' => $request->boolean('is_active', true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedLine(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nome']);

        return [...$data, 'is_active' => $request->boolean('is_active', true)];
    }
}
