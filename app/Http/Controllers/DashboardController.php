<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canSeeUsers = $user->can(Permission::UsersView->value);

        return Inertia::render('Dashboard', [
            'stats' => [
                'unread_notifications' => $user->unreadNotifications()->count(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'roles' => $user->getRoleNames()->map(fn (string $name) => RoleEnum::labelFor($name))->all(),
            ],
            'adminStats' => $canSeeUsers ? Inertia::defer(fn () => [
                'users_total' => User::count(),
                'users_active' => User::query()->active()->count(),
                'users_new_this_month' => User::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                'roles_total' => Role::count(),
            ]) : null,
            'recentNotifications' => NotificationResource::collection(
                $user->notifications()->latest()->limit(5)->get()
            )->resolve(),
        ]);
    }
}
