<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');

        return DB::transaction(function () use ($next, $request, $tenant) {
            if ($tenant) {
                DB::statement("SET LOCAL app.tenant_id = '{$tenant->id}'");
            }

            return $next($request);
        });
    }
}
