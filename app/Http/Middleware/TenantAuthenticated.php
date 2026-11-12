<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class TenantAuthenticated
{
    public function handle(Request $r, Closure $next)
    {
        $tenant = $r->attributes->get('tenant');
        $user = (int) $r->session()->get('tenant_id') === $tenant->id ? User::where('active', true)->find($r->session()->get('user_id')) : null;
        if (! $user || (int) $r->session()->get('auth_version', 0) !== (int) $user->auth_version) {
            return redirect()->route('login', ['company' => $tenant->slug]);
        }
        if ($user->must_change_password && ! $r->routeIs('account.password', 'account.password.save', 'logout')) {
            return redirect()->route('account.password', ['company' => $tenant->slug]);
        }
        $r->attributes->set('currentUser', $user);
        view()->share('currentUser', $user);

        return $next($r);
    }
}
