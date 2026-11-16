<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        Schema::connection('tenant')->create('company_operations', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedInteger('version')->default(0);
            $t->json('configuration');
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::connection('tenant')->create('company_operation_audits', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('version'); $t->unsignedBigInteger('user_id');
            $t->string('action', 30); $t->json('before'); $t->json('after'); $t->timestamp('created_at');
        });
        Schema::connection('tenant')->table('sales', fn (Blueprint $t) => $t->string('commission_basis', 12)->default('cash'));
    }
    public function down(): void
    {
        Schema::connection('tenant')->table('sales', fn (Blueprint $t) => $t->dropColumn('commission_basis'));
        Schema::connection('tenant')->dropIfExists('company_operation_audits');
        Schema::connection('tenant')->dropIfExists('company_operations');
    }
};
