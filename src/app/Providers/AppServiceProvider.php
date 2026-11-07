<?php

namespace App\Providers;

use App\Services\GoogleSheets\GoogleApiHttpClient;
use App\Services\GoogleSheets\GoogleServiceAccountTokenProvider;
use App\Services\GoogleSheets\GoogleSheetsClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleApiHttpClient::class, fn () => new GoogleApiHttpClient(
            maxAttempts: (int) config('google-sheets.retry.max_attempts', 4),
            initialDelayMs: (int) config('google-sheets.retry.initial_delay_ms', 1000),
            maxDelayMs: (int) config('google-sheets.retry.max_delay_ms', 32000),
            jitterMs: (int) config('google-sheets.retry.jitter_ms', 1000),
            timeoutSeconds: (int) config('google-sheets.timeout_seconds', 30),
            connectTimeoutSeconds: (int) config('google-sheets.connect_timeout_seconds', 10),
        ));
        $this->app->singleton(GoogleServiceAccountTokenProvider::class, function (Application $app): GoogleServiceAccountTokenProvider {
            $path = config('google-sheets.credentials_path');

            return new GoogleServiceAccountTokenProvider(
                $app->make(GoogleApiHttpClient::class),
                is_string($path) ? $path : null,
            );
        });
        $this->app->singleton(GoogleSheetsClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
