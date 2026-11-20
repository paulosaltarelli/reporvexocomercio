<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_installer_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('issued_by')->constrained('platform_admins');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 180);
            $table->string('email', 180);
            $table->string('status', 16)->default('pending');
            $table->string('delivery_status', 16)->default('sending');
            $table->char('token_digest', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id','email','status'], 'installer_invitation_email');
            $table->index(['tenant_id','user_id','status'], 'installer_invitation_user');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve o histórico dos acessos de implantação.');
    }
};
