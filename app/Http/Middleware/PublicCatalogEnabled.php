<?php

namespace App\Http\Middleware;

use App\Platform\ContractAccess;
use App\Services\CompanyOperations;
use Closure;
use Illuminate\Http\Request;

final class PublicCatalogEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $settings = app(CompanyOperations::class)->current();
        abort_unless(($settings['public_catalog_published'] ?? false) === true && $settings['catalog_mode'] !== 'services', 404);
        $restricted = app(ContractAccess::class)->restricted($request->attributes->get('tenant'));
        abort_if(isset($restricted['catalog']), 404);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
