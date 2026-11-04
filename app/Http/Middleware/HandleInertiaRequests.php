<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Http\Controllers\Interventions\InterventionController;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'locale' => app()->getLocale(),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'initials' => $user->initials,
                    'email_verified' => $user->hasVerifiedEmail(),
                ] : null,
                'roles' => fn () => $user?->getRoleNames()->all() ?? [],
                'permissions' => fn () => $this->permissionsFor($user),
            ],
            'inbox' => fn () => $user ? [
                'unread_count' => $user->unreadNotifications()->count(),
                'latest' => NotificationResource::collection(
                    $user->notifications()->latest()->limit(6)->get()
                )->resolve(),
            ] : null,
            // Timer of the intervention in progress, shown in the top bar.
            'runningIntervention' => fn () => ($running = InterventionController::runningFor($user)) ? [
                'id' => $running->id,
                'number' => $running->number,
                'customer' => $running->organization?->name,
                'started_at' => $running->started_at->toIso8601String(),
            ] : null,
            // Fortify reports outcomes through the "status" session key.
            'status' => fn () => $request->session()->get('status'),
        ];
    }

    /**
     * @return list<string>
     */
    protected function permissionsFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return Permission::values();
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }
}
