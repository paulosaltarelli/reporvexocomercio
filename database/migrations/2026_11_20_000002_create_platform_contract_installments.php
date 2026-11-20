<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_contract_installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('contract_id');
            $table->date('period');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('payment_locked_at')->nullable();
            $table->timestamp('pix_issue_retry_at')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'period'], 'contract_month');
            $table->unique(['tenant_id', 'id'], 'installment_tenant_identity');
            $table->foreign(['tenant_id', 'contract_id'], 'installment_scoped_contract')
                ->references(['tenant_id', 'id'])->on('platform_contracts');
            $table->index(['tenant_id', 'cancelled_at', 'due_date'], 'installment_tenant_due');
            $table->index(['due_date', 'cancelled_at'], 'installment_global_due');
            $table->index(['cancelled_at', 'due_date', 'pix_issue_retry_at'], 'installment_pix_queue');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve as parcelas financeiras centrais.');
    }
};
