<?php

namespace App\Models;

use App\Fiscal\ProductInvoiceGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FiscalDocument extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'signed_xml'];

    /** Historical documents outside the pilot must stay private and their outbox stays untouched. */
    public function scopeAccessible(Builder $query): Builder
    {
        if (! ProductInvoiceGate::enabled(65)) {
            return $query->whereRaw('1 = 0');
        }
        if (ProductInvoiceGate::globallyEnabled()) {
            return $query;
        }
        return $query->where('model', 65)
            ->where('snapshot->tenant_id', (string) config('tenancy.resolved_tenant_id'))
            ->whereIn('issuer_id', FiscalIssuer::select('id')->where('environment', 'homologation')->where('provider', 'sefaz_direct'));
    }

    public function sale() { return $this->belongsTo(Sale::class); }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'signed_xml' => 'encrypted', 'authorized_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
