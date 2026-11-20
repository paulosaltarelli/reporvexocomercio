<?php

namespace Tests\Unit;

use App\Fiscal\ProcessFiscalOutbox;
use App\Fiscal\QueueNfe;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ProductInvoiceWorkerTest extends TestCase
{
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
