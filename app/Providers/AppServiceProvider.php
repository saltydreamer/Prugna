<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\MailSettings;
use App\Support\Settings;
use App\Support\WebPushKeys;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between queued jobs, so workers see settings saved meanwhile.
        $this->app->scoped(Settings::class);
        $this->app->scoped(MailSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * Behind an external reverse proxy (TRUSTED_PROXIES): its X-Forwarded-* headers tell the
     * scheme, host and client address, so links, redirects and secure cookies stay on HTTPS.
     */
    public static function trustProxies(?string $proxies): void
    {
        if (blank($proxies)) {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }

    public function boot(): void
    {
        $this->configureAuthorization();
        $this->configureDefaults();
        self::trustProxies(config('app.trusted_proxies'));

        $this->app->afterResolving('mail.manager', fn () => $this->app->make(MailSettings::class)->apply());
        $this->app->beforeResolving(WebPushChannel::class, fn () => $this->app->make(WebPushKeys::class)->apply());

        $this->configureRateLimiting();
    }

    /**
     * Public quote pages (no login): separate counters for browsing and for answering.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('public-quote', fn (Request $request) => Limit::perMinute(60)->by('public-quote:'.$request->ip()));
        RateLimiter::for('public-quote-answer', fn (Request $request) => Limit::perMinute(10)->by('public-quote-answer:'.$request->ip()));
    }

    /**
     * The super admin owns every permission defined in App\Enums\Permission.
     *
     * Only permission abilities are short-circuited: policy abilities (update, delete, ...)
     * still run, so structural rules such as "you can't delete yourself" keep applying.
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        Gate::before(function (User $user, string $ability): ?bool {
            if (Permission::tryFrom($ability) !== null && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));
    }
}
