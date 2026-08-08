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
    }
}
