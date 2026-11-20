<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ProductInvoicesEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('fiscal.product_invoices_enabled', false), 404);

        return $next($request);
    }
}
