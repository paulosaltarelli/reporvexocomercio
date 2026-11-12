<?php

namespace App\Http\Middleware;

use App\Models\DemoRun;
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
            view()->share('companyProfile',\App\Models\BusinessProfile::find(1));
            view()->share('demoMode', DemoRun::where('code', 'computer-store-v1')->whereNotNull('completed_at')->exists());
            view()->share('currentUser', null);

            return $next($request);
        } finally {
            $connection->disconnect();
        }
    }
}
