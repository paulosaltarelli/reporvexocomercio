<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('monthly_amount', 14, 2);
            $table->unsignedTinyInteger('due_day');
            $table->json('restricted_modules')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'contract_tenant_identity');
            $table->index(['tenant_id', 'start_date', 'end_date'], 'contract_dates');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os contratos e a história financeira central.');
    }
};
