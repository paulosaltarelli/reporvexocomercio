<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('trade_name', 180)->nullable();
            $table->string('legal_nature', 180)->nullable();
            $table->date('opened_on')->nullable();
            $table->string('registration_status', 60)->nullable();
            $table->string('primary_cnae', 7)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os dados dos clientes; use migração corretiva.');
    }
};
