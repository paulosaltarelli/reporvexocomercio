<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantConnection;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = Tenant::where('slug', $request->route('company'))->where('active', true)->firstOrFail();
        $connection = app(TenantConnection::class);
        try {
            $connection->connect($tenant);
            $request->attributes->set('tenant', $tenant);
            if ($request->session()->has('tenant_id') && (int) $request->session()->get('tenant_id') !== $tenant->id) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            view()->share('tenant', $tenant);
            view()->share('currentUser', null);

            return $next($request);
        } finally {
            $connection->disconnect();
        }
    }
}
