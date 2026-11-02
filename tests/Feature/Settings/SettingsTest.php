<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Mail\TestMail;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Setting;
use App\Models\User;
use App\Support\MailSettings;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_require_permission(): void
    {
        $this->actingAs(User::factory()->withRole(Role::User)->create())->get('/settings')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Index')
                ->where('quotes.validity_days', 30)
                ->has('paymentTerms', PaymentTerm::count()));
    }

    public function test_company_data_is_validated_and_saved(): void
    {
        $admin = User::factory()->admin()->create();
        $data = ['name' => 'Reimg S.r.l.', 'country' => 'IT', 'vat_number' => 'IT01114601006', 'iban' => 'IT60 X054 2811 1010 0000 0123 456'];

        $this->actingAs($admin)->put('/settings/company', [...$data, 'iban' => 'IT60X0542811101000000123457'])->assertSessionHasErrors('iban');
        $this->actingAs($admin)->put('/settings/company', $data)->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame('Reimg S.r.l.', $settings->get('company.name'));
        $this->assertSame('01114601006', $settings->get('company.vat_number'));
        $this->assertSame('IT60X0542811101000000123456', $settings->get('company.iban'));
    }

    public function test_logo_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/company/logo', ['logo' => UploadedFile::fake()->image('logo.png', 400, 120)])->assertSessionHasNoErrors();
        $path = app(Settings::class)->get('company.logo_path');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->delete('/settings/company/logo');
        Storage::disk('public')->assertMissing($path);
        $this->assertNull(app(Settings::class)->get('company.logo_path'));
    }

    public function test_smtp_password_is_encrypted_kept_when_empty_and_never_exposed(): void
    {
        $admin = User::factory()->admin()->create();
        $smtp = ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'mailer', 'from_address' => 'noreply@example.com'];

        $this->actingAs($admin)->put('/settings/mail', [...$smtp, 'password' => 'super-secret'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('super-secret', Setting::where('key', 'mail.password')->value('value'));

        $this->actingAs($admin)->put('/settings/mail', [...$smtp, 'password' => ''])->assertSessionHasNoErrors();
        $this->assertSame('super-secret', app(Settings::class)->get('mail.password'));

        $this->actingAs($admin)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->missing('mail.password')->where('mail.has_password', true));
    }

    public function test_enabled_smtp_requires_host_and_sender(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/mail', ['enabled' => true, 'encryption' => 'tls'])
            ->assertSessionHasErrors(['host', 'port', 'from_address']);
    }

    public function test_smtp_settings_are_applied_to_the_mailer(): void
    {
        app(Settings::class)->set([
            'mail.enabled' => true, 'mail.host' => 'smtps.aruba.it', 'mail.port' => 465, 'mail.encryption' => 'ssl',
            'mail.username' => 'info@reimg.it', 'mail.password' => 'secret', 'mail.from_address' => 'info@reimg.it', 'company.name' => 'Reimg',
        ]);

        app(MailSettings::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps.aruba.it', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('secret', config('mail.mailers.smtp.password'));
        $this->assertSame(['address' => 'info@reimg.it', 'name' => 'Reimg'], config('mail.from'));
    }

    public function test_a_test_email_is_sent_to_the_current_user(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/mail/test')->assertRedirect();

        Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo($admin->email));
    }

    public function test_payment_terms_are_managed_with_a_single_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/payment-terms', [
            'name' => 'Bonifico 90 gg d.f.f.m.', 'method' => 'MP05', 'days' => 90, 'end_of_month' => true, 'is_default' => true,
        ])->assertSessionHasNoErrors();

        $term = PaymentTerm::where('days', 90)->firstOrFail();
        $this->assertTrue(PaymentTerm::default()->is($term));
        $this->assertSame(1, PaymentTerm::where('is_default', true)->count());

        $this->actingAs($admin)->delete("/settings/payment-terms/{$term->id}");
        $this->assertModelExists($term);

        $other = PaymentTerm::where('days', 60)->firstOrFail();
        $organization = Organization::factory()->create(['payment_term_id' => $other->id]);
        $this->actingAs($admin)->delete("/settings/payment-terms/{$other->id}");
        $this->assertModelMissing($other);
        $this->assertNull($organization->fresh()->payment_term_id);
    }

    public function test_due_dates_follow_the_term(): void
    {
        $issued = CarbonImmutable::parse('2026-01-15');

        $this->assertSame('2026-02-14', (new PaymentTerm(['days' => 30]))->dueDate($issued)->toDateString());
        $this->assertSame('2026-02-28', (new PaymentTerm(['days' => 30, 'end_of_month' => true]))->dueDate($issued)->toDateString());
    }

    public function test_organizations_store_their_default_payment_term(): void
    {
        $term = PaymentTerm::where('method', 'MP12')->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())->post('/registry/organizations', [
            'name' => 'Cliente Ri.Ba.', 'type' => 'company', 'payment_term_id' => $term->id,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Organization::firstOrFail()->paymentTerm->is($term));
    }
}
