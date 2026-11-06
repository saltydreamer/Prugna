<?php

namespace App\Http\Controllers\Interventions;

use App\Actions\Interventions\MarkInterventionsInvoiced;
use App\Http\Controllers\Controller;
use App\Models\Intervention;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Interventions invoiced outside Prugna (e.g. in Dolibarr): marked in bulk up to a day, unmarked one by one.
 */
class InterventionInvoicedOutsideController extends Controller
{
    /**
     * Interventions to invoice up to the day (preview of the dialog).
     */
    public function index(Request $request, MarkInterventionsInvoiced $mark): JsonResponse
    {
        $this->authorize('markInvoiced', Intervention::class);

        $data = $request->validate(['until' => ['required', 'date']]);

        return response()->json(['data' => $mark->candidates(CarbonImmutable::parse($data['until']))->map(fn (array $candidate) => [
            'id' => $candidate['intervention']->id,
            'number' => $candidate['intervention']->number,
            'started_at' => $candidate['intervention']->started_at->toIso8601String(),
            'organization' => $candidate['intervention']->organization?->name,
            'description' => str($candidate['intervention']->description)->squish()->limit(120)->toString(),
            'minutes' => $candidate['intervention']->invoiceableMinutes(),
            'later_jobs' => $candidate['later_jobs'],
        ])->values()]);
    }

    public function store(Request $request, MarkInterventionsInvoiced $mark): RedirectResponse
    {
        $this->authorize('markInvoiced', Intervention::class);

        $data = $request->validate([
            'until' => ['required', 'date'],
            'interventions' => ['required', 'array', 'min:1', 'max:5000'],
            'interventions.*' => ['integer'],
        ], [], ['interventions' => 'interventi']);

        $count = $mark->handle(CarbonImmutable::parse($data['until']), array_map('intval', $data['interventions']));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{0} Nessun intervento da segnare.|{1} Un intervento segnato come già fatturato.|[2,*] :count interventi segnati come già fatturati.',
            $count,
        )]);

        return back();
    }

    public function destroy(Intervention $intervention): RedirectResponse
    {
        $this->authorize('unmarkInvoiced', $intervention);

        $intervention->forceFill(['invoiced_outside_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('L\'intervento torna da fatturare.')]);

        return back();
    }
}
