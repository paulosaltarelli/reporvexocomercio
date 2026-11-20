<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_mail_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('company_name');
            $table->string('recipient');
            $table->string('subject');
            $table->string('type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['type', 'source_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve o histórico de envios da Central.');
    }
};
