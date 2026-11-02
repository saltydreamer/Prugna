<?php

namespace Tests\Feature\Sales;

use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Mail\QuoteMail;
use App\Models\Contact;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteActivityNotification;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuoteDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pdf_is_generated(): void
    {
        $owner = User::factory()->withRole(Role::User)->create();
        $quote = Quote::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->get("/sales/quotes/{$quote->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_email_is_prefilled_from_the_template(): void
    {
        app(Settings::class)->set(['company.name' => 'Reimg']);
        $owner = User::factory()->withRole(Role::User)->create(['name' => 'Mario Rossi']);
        $quote = Quote::factory()->create(['owner_id' => $owner->id]);
        $contact = Contact::factory()->for($quote->organization)->create(['first_name' => 'Giulia', 'last_name' => 'Verdi', 'email' => 'giulia@acme.test']);
        $quote->update(['contact_id' => $contact->id]);

        $this->actingAs($owner)->getJson("/sales/quotes/{$quote->id}/compose")
            ->assertOk()
            ->assertJsonPath('to', ['giulia@acme.test'])
            ->assertJsonPath('subject', "Preventivo {$quote->number} – Reimg")
            ->assertJson(fn ($json) => $json->where('body', fn (string $body) => str_contains($body, 'Gentile Giulia Verdi') && str_contains($body, $quote->publicUrl()) && str_contains($body, 'Mario Rossi'))->etc());
    }

    public function test_quotes_are_sent_by_email_with_the_pdf(): void
    {
        Mail::fake();
        $owner = User::factory()->withRole(Role::User)->create();
        $quote = Quote::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->post("/sales/quotes/{$quote->id}/send", [
            'to' => ['cliente@acme.test'],
            'cc' => ['amministrazione@acme.test'],
            'subject' => 'Il vostro preventivo',
            'body' => 'Buongiorno',
            'attach_pdf' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        Mail::assertSent(QuoteMail::class, function (QuoteMail $mail) use ($quote, $owner) {
            $pdf = collect($mail->attachments())->first();

            return $mail->hasTo('cliente@acme.test')
                && $mail->hasCc('amministrazione@acme.test')
                && $mail->hasReplyTo($owner->email)
                && $pdf?->as === "Preventivo-{$quote->number}.pdf";
        });

        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
    }

    public function test_sending_is_blocked_when_emails_would_only_be_logged(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $owner = User::factory()->withRole(Role::User)->create();
        $quote = Quote::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->post("/sales/quotes/{$quote->id}/send", ['to' => ['a@b.test'], 'subject' => 'x', 'body' => 'y']);

        Mail::assertNothingSent();
        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
    }

    public function test_drafts_are_not_public(): void
    {
        $quote = Quote::factory()->create();

        $this->get("/q/{$quote->public_token}")->assertNotFound();
        $this->get('/q/not-a-token')->assertNotFound();
    }

    public function test_the_first_view_by_the_customer_is_recorded_and_notified(): void
    {
        Notification::fake();
        $quote = Quote::factory()->sent()->create();

        $this->get("/q/{$quote->public_token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('public/Quote')->where('quote.is_open', true)->has('quote.lines', 4));
        $this->get("/q/{$quote->public_token}");

        $this->assertNotNull($quote->fresh()->viewed_at);
        Notification::assertSentToTimes($quote->owner, QuoteActivityNotification::class, 1);
    }

    public function test_the_customer_accepts_choosing_optional_lines(): void
    {
        Notification::fake();
        $quote = Quote::factory()->sent()->create();
        $optional = $quote->lines()->where('is_optional', true)->firstOrFail();

        $this->post("/q/{$quote->public_token}/accept", ['name' => 'Giulia Verdi', 'optional_lines' => [$optional->id]])
            ->assertSessionHasErrors('agree');

        $this->post("/q/{$quote->public_token}/accept", [
            'name' => 'Giulia Verdi', 'note' => 'Procedete pure', 'optional_lines' => [$optional->id], 'agree' => true,
        ])->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertSame('Giulia Verdi', $quote->decision_name);
        $this->assertNotNull($quote->decision_ip);
        // 980 + optional training 240 = 1220 + 22% VAT.
        $this->assertSame('1220.00', $quote->subtotal);
        $this->assertSame('1488.40', $quote->total);

        Notification::assertSentTo($quote->owner, QuoteActivityNotification::class, fn ($notification) => $notification->event === QuoteActivityNotification::ACCEPTED);

        // No second answer.
        $this->post("/q/{$quote->public_token}/reject", [])->assertStatus(409);
    }

    public function test_the_customer_can_reject(): void
    {
        Notification::fake();
        $quote = Quote::factory()->sent()->create();

        $this->post("/q/{$quote->public_token}/reject", ['note' => 'Budget insufficiente'])->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::Rejected, $quote->fresh()->status);
        Notification::assertSentTo($quote->owner, QuoteActivityNotification::class, fn ($notification) => $notification->event === QuoteActivityNotification::REJECTED);
    }

    public function test_expired_quotes_can_not_be_accepted(): void
    {
        $quote = Quote::factory()->expired()->create();

        $this->get("/q/{$quote->public_token}")->assertInertia(fn (Assert $page) => $page->where('quote.is_open', false));
        $this->post("/q/{$quote->public_token}/accept", ['name' => 'X', 'agree' => true])->assertStatus(409);
    }

    public function test_the_daily_check_expires_quotes_and_reminds_owners_once(): void
    {
        Notification::fake();
        $expired = Quote::factory()->expired()->create();
        $expiring = Quote::factory()->sent()->create(['valid_until' => today()->addDays(2)]);
        $later = Quote::factory()->sent()->create(['valid_until' => today()->addDays(20)]);

        $this->artisan('quotes:check-expiry')->assertSuccessful();
        $this->artisan('quotes:check-expiry')->assertSuccessful();

        $this->assertSame(QuoteStatus::Expired, $expired->fresh()->status);
        $this->assertSame(QuoteStatus::Sent, $expiring->fresh()->status);
        Notification::assertSentToTimes($expired->owner, QuoteActivityNotification::class, 1);
        Notification::assertSentToTimes($expiring->owner, QuoteActivityNotification::class, 1);
        Notification::assertNotSentTo($later->owner, QuoteActivityNotification::class);
    }
}
