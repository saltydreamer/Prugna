<?php

namespace App\Http\Requests\Interventions;

use App\Enums\InterventionMode;
use App\Enums\InterventionStatus;
use App\Enums\Permission;
use App\Models\Intervention;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filters of the interventions list, shared by the list and by the summary PDF of "all the filtered ones".
 */
class InterventionFilterRequest extends FormRequest
{
    public const SORTABLE = ['started_at', 'duration_minutes', 'number'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Intervention::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'technician' => ['nullable', 'integer'],
            'customer' => ['nullable', 'integer'],
            'order' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'mode' => ['nullable', Rule::enum(InterventionMode::class)],
            'status' => ['nullable', Rule::enum(InterventionStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ];
    }

    public function isSupervisor(): bool
    {
        return $this->user()->can(Permission::InterventionsSupervise->value);
    }

    /**
     * "mine" or "all": technicians always see their own.
     */
    public function scope(): string
    {
        return $this->isSupervisor() ? ($this->validated('scope') ?? 'all') : 'mine';
    }

    /**
     * The interventions matching the filters, whatever their status.
     *
     * @return Builder<Intervention>
     */
    public function baseQuery(): Builder
    {
        $user = $this->user();
        $filters = $this->validated();

        return Intervention::query()
            ->visibleTo($user)
            ->when($this->scope() === 'mine', fn (Builder $query) => $query->where('technician_id', $user->id))
            ->when($this->isSupervisor() && ($filters['technician'] ?? null), fn (Builder $query) => $query->where('technician_id', $filters['technician']))
            ->when($filters['customer'] ?? null, fn (Builder $query, int $id) => $query->where('organization_id', $id))
            ->when($filters['order'] ?? null, fn (Builder $query, int $id) => $query->where('order_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->when($filters['mode'] ?? null, fn (Builder $query, string $mode) => $query->where('mode', $mode))
            // Dates are local days, times are stored in UTC.
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('started_at', '>=', Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->where('started_at', '<=', Carbon::parse($date, config('app.display_timezone'))->endOfDay()->utc()))
            ->search($filters['search'] ?? null);
    }

    /**
     * The interventions of the list: the filters and the status tab.
     *
     * @return Builder<Intervention>
     */
    public function listedQuery(): Builder
    {
        return $this->baseQuery()->withStatus($this->validated('status'));
    }
}
