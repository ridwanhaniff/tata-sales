<?php

namespace App\Providers;

use App\Services\Crm\Contracts\CrmConnector;
use App\Services\Crm\Providers\EchoCrmConnector;
use App\Services\Crm\Providers\HttpCrmConnector;
use App\Services\Crm\Providers\HubSpotCrmConnector;
use Illuminate\Support\ServiceProvider;

/**
 * Bind CrmConnector ke driver aktif (CRM_DRIVER) — §78 Sprint 13.
 * Penentu endpoint per tenant ada di driver masing-masing.
 */
class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CrmConnector::class, function () {
            return match ((string) config('crm.driver', 'echo')) {
                'http' => new HttpCrmConnector,
                'hubspot' => new HubSpotCrmConnector,
                default => new EchoCrmConnector,
            };
        });
    }
}
