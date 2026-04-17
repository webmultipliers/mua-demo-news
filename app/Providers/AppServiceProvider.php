<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Bifrost's `native.php` runs `Kernel->bootstrap()` before a
        // `request` binding exists — URL::forceHttps() would resolve a
        // UrlGenerator with $request=null and throw a TypeError. The
        // in-shell runtime also serves from http://127.0.0.1, so forcing
        // HTTPS would rewrite every asset URL to something unreachable.
        if (! $this->app->bound('request') || $this->app->runningInConsole()) {
            return;
        }

        if ($this->app->environment('production')) {
            URL::forceHttps();
        }
    }
}
