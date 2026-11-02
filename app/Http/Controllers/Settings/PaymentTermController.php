<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PaymentTermRequest;
use App\Models\PaymentTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PaymentTermController extends Controller
{
    public function store(PaymentTermRequest $request): RedirectResponse
    {
        DB::transaction(fn () => $this->save(new PaymentTerm(['sort' => (PaymentTerm::max('sort') ?? 0) + 10]), $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Condizione di pagamento creata.')]);

        return back();
    }

    public function update(PaymentTermRequest $request, PaymentTerm $paymentTerm): RedirectResponse
    {
        DB::transaction(fn () => $this->save($paymentTerm, $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Condizione di pagamento aggiornata.')]);

        return back();
    }

    /**
     * Customers using it go back to the default term.
     */
    public function destroy(PaymentTerm $paymentTerm): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        if ($paymentTerm->is_default) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('La condizione predefinita non può essere eliminata.')]);

            return back();
        }

        $paymentTerm->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Condizione di pagamento eliminata.')]);

        return back();
    }

    /**
     * Only one default term, which is always active.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(PaymentTerm $paymentTerm, array $data): void
    {
        $paymentTerm->fill($data);

        if ($paymentTerm->is_default) {
            $paymentTerm->is_active = true;
        }

        $paymentTerm->save();

        if ($paymentTerm->is_default) {
            PaymentTerm::query()->whereKeyNot($paymentTerm->id)->update(['is_default' => false]);
        }
    }
}
