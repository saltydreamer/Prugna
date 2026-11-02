<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CompanySettingsRequest;
use App\Http\Requests\Settings\MailSettingsRequest;
use App\Mail\TestMail;
use App\Models\PaymentTerm;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Impostazioni: company data, outgoing email (SMTP), quotes defaults, payment terms.
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        $company = $this->settings->group('company');
        $mail = $this->settings->group('mail');
        unset($mail['password']);

        return Inertia::render('settings/Index', [
            'tab' => $request->query('tab', 'company'),
            'company' => [
                ...$company,
                'logo_url' => ! empty($company['logo_path']) ? Storage::disk('public')->url($company['logo_path']) : null,
            ],
            'mail' => [
                ...$mail,
                'has_password' => $this->settings->has('mail.password'),
            ],
            'quotes' => $this->settings->group('quotes'),
            'paymentTerms' => PaymentTerm::query()->withCount('organizations')->ordered()->get(),
            'paymentMethods' => collect(PaymentTerm::METHODS)->map(fn (string $title, string $value) => ['value' => $value, 'title' => "{$value} – {$title}"])->values(),
        ]);
    }

    public function updateCompany(CompanySettingsRequest $request): RedirectResponse
    {
        $this->settings->set(collect($request->validated())->mapWithKeys(fn ($value, $key) => ["company.{$key}" => $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dati azienda salvati.')]);

        return back();
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ]);

        $previous = $this->settings->get('company.logo_path');
        $this->settings->set(['company.logo_path' => $request->file('logo')->store('company', 'public')]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo aggiornato.')]);

        return back();
    }

    public function destroyLogo(): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        if ($path = $this->settings->get('company.logo_path')) {
            Storage::disk('public')->delete($path);
        }

        $this->settings->set(['company.logo_path' => null]);

        return back();
    }

    public function updateMail(MailSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["mail.{$key}" => $value])->all());

        // Queue workers keep the mailer in memory: restart them to use the new server.
        Artisan::call('queue:restart');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni email salvate. Invia un\'email di prova per verificarle.')]);

        return back();
    }

    /**
     * Sends a test email to the chosen address, synchronously, reporting the SMTP error if any.
     */
    public function testMail(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate(['to' => ['required', 'email', 'max:255']], [], ['to' => 'destinatario']);

        try {
            Mail::purge();
            Mail::to($data['to'])->send(new TestMail);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $exception->getMessage()])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email di prova inviata a :email: controlla la casella (anche lo spam).', ['email' => $data['to']])]);

        return back();
    }

    public function updateQuotes(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'validity_days' => ['required', 'integer', 'between:1,365'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'email_subject' => ['required', 'string', 'max:255'],
            'email_body' => ['required', 'string', 'max:5000'],
        ], [], [
            'validity_days' => 'validità',
            'terms' => 'condizioni',
            'email_subject' => 'oggetto',
            'email_body' => 'testo',
        ]);

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["quotes.{$key}" => $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni dei preventivi salvate.')]);

        return back();
    }
}
