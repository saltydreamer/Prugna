<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_listed_and_shared_with_every_page(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeNotification);
        $this->travel(1)->minute();
        $user->notify(new AnnouncementNotification('Manutenzione', 'Stasera alle 22', 'warning'));

        $this->actingAs($user)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notifications/Index')
                ->has('notifications.data', 2)
                ->where('notifications.data.0.title', 'Manutenzione')
                ->where('notifications.data.0.color', 'warning')
                ->where('notifications.data.0.type', 'AnnouncementNotification')
                ->where('counts.unread', 2)
                ->where('inbox.unread_count', 2)
                ->has('inbox.latest', 2));
    }

    public function test_notifications_can_be_filtered(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeNotification);
        $user->notifications()->first()->markAsRead();
        $user->notify(new WelcomeNotification);

        $this->actingAs($user)
            ->get('/notifications?filter=unread')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
    }

    public function test_a_notification_can_be_marked_as_read_and_unread(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeNotification);
        $notification = $user->notifications()->first();

        $this->actingAs($user)->patch("/notifications/{$notification->id}/read")->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($user)->patch("/notifications/{$notification->id}/unread")->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_all_notifications_can_be_marked_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeNotification);
        $user->notify(new WelcomeNotification);

        $this->actingAs($user)->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_notifications_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeNotification);
        $user->notify(new WelcomeNotification);
        $first = $user->notifications()->first();

        $this->actingAs($user)->delete("/notifications/{$first->id}")->assertRedirect();
        $this->assertSame(1, $user->notifications()->count());

        $user->unreadNotifications()->update(['read_at' => now()]);

        $this->actingAs($user)->delete('/notifications/read')->assertRedirect();
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_users_can_not_touch_notifications_of_others(): void
    {
        $owner = User::factory()->create();
        $owner->notify(new WelcomeNotification);
        $notification = $owner->notifications()->first();

        $this->actingAs(User::factory()->create())
            ->patch("/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->delete("/notifications/{$notification->id}")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_email_is_sent_only_to_users_who_opted_in(): void
    {
        $optedIn = User::factory()->create(['email_notifications' => true]);
        $optedOut = User::factory()->create(['email_notifications' => false]);

        $notification = new AnnouncementNotification('Titolo', 'Messaggio', sendMail: true);

        $this->assertSame(['database', 'mail'], $notification->via($optedIn));
        $this->assertSame(['database'], $notification->via($optedOut));
    }

    public function test_admins_can_send_announcements_by_role(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $member = User::factory()->withRole(Role::User)->create();
        $inactiveMember = User::factory()->inactive()->withRole(Role::User)->create();

        $this->actingAs($admin)
            ->post('/admin/announcements', [
                'title' => 'Novità',
                'message' => 'È disponibile una nuova funzione.',
                'level' => 'success',
                'audience' => 'roles',
                'roles' => [Role::User->value],
                'send_mail' => false,
            ])
            ->assertRedirect('/admin/announcements/create');

        Notification::assertSentTo($member, AnnouncementNotification::class, fn ($notification) => $notification->title() === 'Novità');
        Notification::assertNotSentTo($inactiveMember, AnnouncementNotification::class);
        Notification::assertNotSentTo($admin, AnnouncementNotification::class);
    }

    public function test_announcements_require_permission(): void
    {
        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->get('/admin/announcements/create')
            ->assertForbidden();

        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->post('/admin/announcements', ['title' => 'x', 'message' => 'y', 'level' => 'info', 'audience' => 'all'])
            ->assertForbidden();
    }
}
