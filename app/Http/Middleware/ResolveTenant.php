<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = null;

        $header = $request->header('X-Tenant-ID');
        if ($header) {
            $tenantId = $header;
        }

        $subdomain = $this->resolveSubdomain($request);
        if ($subdomain && $subdomain !== 'www') {
            $tenant = Tenant::query()
                ->where('slug', $subdomain)
                ->orWhere('domain', $subdomain)
                ->first();

            if ($tenant) {
                $tenantId = $tenant->id;
            }
        }

        if (! $tenantId) {
            $domain = $request->getHost();
            $tenant = Tenant::where('domain', $domain)->first();
            $tenantId = $tenant?->id;
        }

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if ($tenant) {
                app()->instance('currentTenant', $tenant);
                $request->attributes->set('tenant', $tenant);
            }
        }

        return $next($request);
    }

    private function resolveSubdomain(Request $request): ?string
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        return count($parts) >= 3 ? $parts[0] : null;
    }
}
