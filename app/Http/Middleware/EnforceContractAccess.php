<?php

namespace App\Http\Middleware;

use App\Platform\ContractAccess;
use Closure;
use Illuminate\Http\Request;

final class EnforceContractAccess
{
    public function __construct(private ContractAccess $access) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('license.*', 'account.password*', 'logout', 'business.logo')) {
            return $next($request);
        }

        $restricted = $this->access->restricted($request->attributes->get('tenant'));
        $request->attributes->set('restrictedModules', $restricted);
        $blocked = array_intersect_key($restricted, array_flip($this->access->modulesFor($request)));
        if (!$blocked) return $next($request);

        $message = 'Há uma parcela vencida da licença. O contrato restringe o acesso a '.implode(', ', $blocked).'.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'modules' => array_keys($blocked)], 402);
        }
        return response()->view('license.restricted', ['message' => $message], 402);
    }
}
