<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_settings', fn (Blueprint $table) => $table->json('configuration_draft')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve o histórico de configuração fiscal; use migração corretiva.');
    }
};
