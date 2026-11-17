<?php

namespace App\Providers;

use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\WebhookSignature;
use App\Pricing\GenerationPricer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
