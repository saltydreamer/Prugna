<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\RolesChangedNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const SORTABLE = ['name', 'email', 'created_at', 'last_login_at'];

    private const PER_PAGE = [10, 25, 50, 100];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ]);

        $users = User::query()
            ->with('roles')
            ->search($filters['search'] ?? null)
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => UserResource::collection($users),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'role' => $filters['role'] ?? null,
                'status' => $filters['status'] ?? null,
                'sort_by' => $filters['sort_by'] ?? 'created_at',
                'sort_dir' => $filters['sort_dir'] ?? 'desc',
                'per_page' => (int) ($filters['per_page'] ?? 10),
            ],
            'roles' => $this->roleOptions($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('admin/users/Create', [
            'roles' => $this->roleOptions($request),
            'defaultRoles' => [RoleEnum::User->value],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $sendResetLink = $request->boolean('send_reset_link');

        $user = DB::transaction(function () use ($data, $sendResetLink): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $sendResetLink ? Str::password(32) : $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            // The administrator vouches for the address.
            $user->markEmailAsVerified();
            $user->syncRoles($data['roles'] ?? []);

            return $user;
        });

        $user->notify(new WelcomeNotification);

        if ($sendResetLink) {
            Password::sendResetLink(['email' => $user->email]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Utente creato.')]);

        return to_route('admin.users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('admin/users/Edit', [
            'user' => UserResource::make($user->load('roles'))->resolve(),
            'roles' => $this->roleOptions($request),
            'isSelf' => $request->user()->is($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $previousRoles = $user->getRoleNames()->sort()->values()->all();

        DB::transaction(function () use ($user, $data): void {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();
            $user->syncRoles($data['roles'] ?? []);
        });

        $currentRoles = $user->getRoleNames()->sort()->values()->all();

        if ($previousRoles !== $currentRoles && ! $request->user()->is($user)) {
            $user->notify(new RolesChangedNotification($this->roleLabels($currentRoles)));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Utente aggiornato.')]);

        return to_route('admin.users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Utente eliminato.')]);

        return to_route('admin.users.index');
    }

    /**
     * Roles the current user is allowed to assign.
     *
     * @return list<array{value: string, title: string}>
     */
    private function roleOptions(Request $request): array
    {
        return Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->reject(fn (string $name) => $name === RoleEnum::SuperAdmin->value && ! $request->user()->isSuperAdmin())
            ->map(fn (string $name) => ['value' => $name, 'title' => RoleEnum::labelFor($name)])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function roleLabels(array $roles): array
    {
        return array_map(fn (string $name) => RoleEnum::labelFor($name), $roles);
    }
}
