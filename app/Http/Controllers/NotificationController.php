<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unread', 'read'])],
        ]);

        $filter = $filters['filter'] ?? 'all';

        $notifications = $request->user()->notifications()
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($filter === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('notifications/Index', [
            'notifications' => NotificationResource::collection($notifications),
            'filter' => $filter,
            'counts' => [
                'all' => $request->user()->notifications()->count(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->markAsRead();

        return back();
    }

    public function markAsUnread(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->markAsUnread();

        return back();
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tutte le notifiche sono state segnate come lette.')]);

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->delete();

        return back();
    }

    public function destroyRead(Request $request): RedirectResponse
    {
        $request->user()->readNotifications()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notifiche lette eliminate.')]);

        return back();
    }

    /**
     * Notifications are always scoped to the authenticated user.
     */
    private function find(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->findOrFail($id);
    }
}
