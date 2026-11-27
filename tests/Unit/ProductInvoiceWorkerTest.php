<?php

namespace Tests\Unit;

use App\Fiscal\ProcessFiscalOutbox;
use App\Fiscal\ProductInvoiceGate;
use App\Fiscal\QueueNfe;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ProductInvoiceWorkerTest extends TestCase
{
    public function test_pilot_requires_a_resolved_connection_and_strict_central_ids(): void
    {
        config(['fiscal.product_invoices_enabled' => false, 'fiscal.nfce_homologation_tenant_ids' => '5, 17,5']);
        DB::shouldReceive('connection')->never();
        $this->assertSame([5, 17], ProductInvoiceGate::pilotTenantIds());
        $this->assertFalse(ProductInvoiceGate::enabled(65));
        $this->assertFalse(ProductInvoiceGate::enabled(55));
        app(ProcessFiscalOutbox::class)->run(6, 1);
        config(['tenancy.resolved_tenant_id' => 5, 'database.connections.tenant' => null]);
        $this->assertFalse(ProductInvoiceGate::enabled(65));
        foreach (['*', '5,invalid', '05', '+5', '5e1', '5,', '0', true] as $setting) {
            config(['fiscal.nfce_homologation_tenant_ids' => $setting]);
            $this->assertSame([], ProductInvoiceGate::pilotTenantIds());
        }
    }

    public function test_v1_preserves_pending_product_jobs_without_opening_a_database_connection(): void
    {
        config(['fiscal.product_invoices_enabled'=>false]);
        DB::shouldReceive('connection')->never();
        $this->assertSame(0, Artisan::call('vendra:fiscal-work'));
        app(ProcessFiscalOutbox::class)->run(1, 1);
        $this->expectException(NotFoundHttpException::class);
        app(QueueNfe::class)->resend(new Tenant(), 1, 1);
    }
}
