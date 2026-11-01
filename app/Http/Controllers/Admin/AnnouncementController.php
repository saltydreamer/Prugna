<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AnnouncementController extends Controller
{
    public function create(): Response
    {
        Gate::authorize(Permission::NotificationsSend->value);

        return Inertia::render('admin/announcements/Create', [
            'roles' => Role::query()->orderBy('name')->pluck('name')
                ->map(fn (string $name) => ['value' => $name, 'title' => RoleEnum::labelFor($name)])
                ->values(),
            'users' => User::query()->active()->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $user) => ['value' => $user->id, 'title' => $user->name, 'subtitle' => $user->email])
                ->values(),
            'levels' => AnnouncementNotification::LEVELS,
        ]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $recipients = User::query()
            ->active()
            ->when($data['audience'] === 'roles', fn ($query) => $query->role($data['roles']))
            ->when($data['audience'] === 'users', fn ($query) => $query->whereKey($data['users']))
            ->get();

        Notification::send($recipients, new AnnouncementNotification(
            subject: $data['title'],
            body: $data['message'],
            level: $data['level'],
            url: $data['action_url'] ?? null,
            sendMail: $request->boolean('send_mail'),
            senderName: $request->user()->name,
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('Annuncio inviato a :count utente.|Annuncio inviato a :count utenti.', $recipients->count(), ['count' => $recipients->count()]),
        ]);

        return to_route('admin.announcements.create');
    }
}
