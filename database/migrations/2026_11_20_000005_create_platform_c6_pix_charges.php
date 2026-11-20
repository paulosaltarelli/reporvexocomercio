<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_c6_pix_charges', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('installment_id');
            $t->foreign(['tenant_id', 'installment_id'], 'c6_scoped_installment')
                ->references(['tenant_id', 'id'])->on('platform_contract_installments');
            $t->index(['tenant_id', 'installment_id', 'id'], 'c6_installment_attempts');
            $t->char('txid', 32)->unique();
            $t->char('account', 64);
            $t->string('environment', 12);
            $t->decimal('amount', 14, 2);
            $t->string('status', 12);
            $t->text('code')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->string('end_to_end_id', 32)->nullable()->index();
            $t->dateTime('bank_paid_at', 6)->nullable();
            $t->char('receipt_hash', 64)->nullable();
            $t->dateTime('worker_next_check_at')->nullable();
            $t->index(['status','worker_next_check_at','id'], 'c6_reconciliation_queue');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve o histórico bancário das parcelas da plataforma.');
    }
};
