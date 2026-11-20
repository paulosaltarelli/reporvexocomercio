<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_contract_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('installment_id');
            $table->string('txid', 35)->collation('ascii_bin')->charset('ascii')->unique();
            $table->string('e2e', 32)->collation('ascii_bin')->charset('ascii')->unique();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->foreign(['tenant_id', 'installment_id'], 'payment_scoped_installment')
                ->references(['tenant_id', 'id'])->on('platform_contract_installments');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve a trilha de recebimentos centrais.');
    }
};
