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
        if (! $user) {
            return redirect()->route('login', ['company' => $tenant->slug]);
        }
        $r->attributes->set('currentUser', $user);
        view()->share('currentUser', $user);

        return $next($r);
    }
}
