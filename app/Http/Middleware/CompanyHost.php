<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\CompanyHosts;
use Closure;
use Illuminate\Http\Request;

final class CompanyHost
{
    public function handle(Request $request, Closure $next)
    {
        $domain = CompanyHosts::domain();
        $host = strtolower($request->getHost());
        if ($host === CompanyHosts::demoHost()) {
            $slug = CompanyHosts::DEMO_SLUG;
        } else {
            if ($domain === '' || in_array($host, CompanyHosts::legacyHosts(), true)) return $next($request);
            $suffix = '.'.$domain;
            abort_unless(str_ends_with($host, $suffix), 404);
            $slug = substr($host, 0, -strlen($suffix));
            abort_unless(strlen($slug) <= 63 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) && !CompanyHosts::reserved($slug), 404);
        }
        if ($request->is('/') && $request->isMethod('GET')) {
            abort_unless(Tenant::where('slug', $slug)->where('active', true)->exists(), 404);
            return redirect(CompanyHosts::url($slug));
        }
        abort_unless($request->route('company') === $slug, 404);
        return $next($request);
    }
}
