<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unsignedBigInteger('user_id');
            $table->string('email');
            $table->unsignedBigInteger('auth_version');
            $table->char('token_digest', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os registros de recuperação de senha.');
    }
};
