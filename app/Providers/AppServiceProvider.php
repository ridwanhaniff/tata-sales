<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Rate limit submit lead per kombinasi IP + tenant + phone (§119)
        RateLimiter::for('leads', function (Request $request) {
            $phone = $request->string('customer.phone')->toString();

            return Limit::perMinute(10)->by(
                sha1($request->ip().'|'.$request->header('X-Tenant-ID', '').'|'.$phone)
            );
        });

        // Rate limit chat AI publik per IP + phone (§119)
        RateLimiter::for('chat', function (Request $request) {
            $phone = $request->string('customer_phone')->toString();

            return Limit::perMinute(20)->by(
                sha1($request->ip().'|'.$request->header('X-Tenant-ID', '').'|'.$phone)
            );
        });

        // Rate limit link publik quotation (view + respond) §99 Sprint 12
        RateLimiter::for('quotes', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
