<?php

namespace App\Providers;

use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\WebhookSignature;
use App\Pricing\GenerationPricer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            baseUrl: (string) config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            pricingCacheSeconds: (int) config('pixelforge.pricing_cache_seconds'),
        ));

        $this->app->singleton(WebhookSignature::class, fn (): WebhookSignature => new WebhookSignature(
            secret: config('pixelforge.webhook_secret'),
            toleranceSeconds: (int) config('pixelforge.webhook_tolerance_seconds'),
        ));

        $this->app->singleton(GenerationPricer::class, fn (): GenerationPricer => new GenerationPricer(
            markupPercent: (int) config('pixelforge.markup_percent'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('view-report', fn (User $user): bool => (bool) $user->is_admin);

        $this->configureRateLimiting();
        $this->configureTrustedProxies();
    }

    /**
     * Behind a host's load balancer, trust its forwarded headers so links, secure
     * cookies and rate limits see the real scheme and client address. Set
     * TRUSTED_PROXIES to "*" there, or to a comma-separated list of addresses.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('pixelforge.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : explode(',', $proxies));
        }
    }

    /**
     * Every limit in one place. Signed-in routes are limited per user, so one
     * person behind a shared address can't use up another's allowance.
     */
    protected function configureRateLimiting(): void
    {
        $perUser = fn (int $perMinute): Closure => fn (Request $request): Limit => Limit::perMinute($perMinute)
            ->by((string) ($request->user()->id ?? $request->ip()));

        RateLimiter::for('estimate', $perUser(60));
        RateLimiter::for('generate', $perUser(20));
        RateLimiter::for('credits', $perUser(10));
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(300)->by((string) $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // While developing and testing: lazy loading, silently dropped attributes and
        // reading attributes that were never loaded all throw instead of hiding bugs.
        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
