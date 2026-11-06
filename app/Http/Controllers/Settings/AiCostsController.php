<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AiCall;
use App\Support\Ai\AiException;
use App\Support\Ai\OpenRouterClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Costi IA»: what the OpenRouter calls cost (USD), by month, feature, model and user.
 */
class AiCostsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $calls = AiCall::query()->with('user:id,name')->whereBetween('created_at', [$from, $to])->get();
        $first = AiCall::query()->min('created_at');

        return Inertia::render('settings/AiCosts', [
            'month' => $month,
            'firstMonth' => $first ? Carbon::parse($first)->format('Y-m') : $month,
            'summary' => [
                'month_total' => $this->total($calls),
                'month_calls' => $calls->count(),
                'month_failed' => $calls->where('success', false)->count(),
                'average' => $calls->where('success', true)->count() ? round($this->total($calls) / $calls->where('success', true)->count(), 4) : 0,
                'previous_total' => round((float) AiCall::query()->whereBetween('created_at', [$from->copy()->subMonthNoOverflow()->startOfMonth(), $from->copy()->subMonthNoOverflow()->endOfMonth()])->sum('cost'), 4),
                'year_total' => round((float) AiCall::query()->whereBetween('created_at', [$from->copy()->startOfYear(), $from->copy()->endOfYear()])->sum('cost'), 4),
            ],
            'months' => $this->lastMonths($from),
            'byFeature' => $calls->groupBy('feature')->map(fn (Collection $group, string $feature) => [
                'feature' => $feature,
                'label' => AiCall::featureLabel($feature),
                'calls' => $group->count(),
                'total' => $this->total($group),
            ])->sortByDesc('total')->values(),
            'byModel' => $calls->groupBy(fn (AiCall $call) => (string) $call->model)->map(fn (Collection $group, string $model) => [
                'model' => $model ?: '—',
                'calls' => $group->count(),
                'tokens' => (int) $group->sum(fn (AiCall $call) => $call->prompt_tokens + $call->completion_tokens),
                'total' => $this->total($group),
            ])->sortByDesc('total')->values(),
            'byUser' => $calls->groupBy(fn (AiCall $call) => (string) $call->user?->name)->map(fn (Collection $group, string $name) => [
                'name' => $name ?: __('Sistema'),
                'calls' => $group->count(),
                'total' => $this->total($group),
            ])->sortByDesc('total')->values(),
            'recent' => AiCall::query()->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get()->map(fn (AiCall $call) => [
                'id' => $call->id,
                'created_at' => $call->created_at?->toIso8601String(),
                'feature' => AiCall::featureLabel($call->feature),
                'model' => $call->model,
                'user' => $call->user?->name,
                'success' => $call->success,
                'error' => $call->error,
                'tokens' => $call->prompt_tokens + $call->completion_tokens,
                'cost' => $call->cost !== null ? (float) $call->cost : null,
                'duration_ms' => $call->duration_ms,
            ]),
        ]);
    }

    /**
     * The API key usage according to OpenRouter, to compare with what Prugna logged.
     */
    public function key(OpenRouterClient $client): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        try {
            return response()->json($client->keyInfo());
        } catch (AiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Cost of the twelve months up to the chosen one.
     *
     * @return list<array{month: string, total: float, calls: int}>
     */
    private function lastMonths(Carbon $month): array
    {
        $start = $month->copy()->subMonthsNoOverflow(11)->startOfMonth();
        $calls = AiCall::query()->whereBetween('created_at', [$start, $month->copy()->endOfMonth()])->get(['created_at', 'cost']);
        $byMonth = $calls->groupBy(fn (AiCall $call) => $call->created_at->format('Y-m'));

        return collect(range(0, 11))->map(function (int $offset) use ($start, $byMonth): array {
            $key = $start->copy()->addMonthsNoOverflow($offset)->format('Y-m');

            return ['month' => $key, 'total' => $this->total($byMonth->get($key, collect())), 'calls' => $byMonth->get($key, collect())->count()];
        })->all();
    }

    /**
     * @param  Collection<int, AiCall>  $calls
     */
    private function total(Collection $calls): float
    {
        return round((float) $calls->sum(fn (AiCall $call) => (float) $call->cost), 4);
    }
}
