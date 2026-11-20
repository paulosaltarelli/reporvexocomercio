<?php

namespace App\Http\Middleware;

use App\Platform\PlatformAdmin;
use App\Platform\PlatformMfa;
use Closure;
use Illuminate\Http\Request;

class PlatformAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        $admin = PlatformAdmin::where('active', true)->find((int) $request->session()->get('platform_admin_id', 0));
        if (! $admin || (PlatformMfa::required() && !$request->session()->get('platform_mfa_at')) || (int) $admin->auth_version !== (int) $request->session()->get('platform_auth_version', -1)) {
            $request->session()->forget(['platform_admin_id', 'platform_auth_version', 'platform_mfa_at']);
            return redirect()->route('platform.login');
        }
        $request->attributes->set('platformAdmin', $admin);
        view()->share('platformAdmin', $admin);
        return $next($request);
    }
}
