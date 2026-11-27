<?php

namespace App\Http\Middleware;

use App\Fiscal\ProductInvoiceGate;
use Closure;
use Illuminate\Http\Request;

final class ProductInvoicesEnabled
{
    public function handle(Request $request, Closure $next, ?string $model = null)
    {
        ProductInvoiceGate::assertEnabled($model === null ? null : (int) $model);

        return $next($request);
    }
}
