<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_provision_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('requested_by')->constrained('platform_admins');
            $t->string('status', 20)->default('pending');
            $t->string('name', 180);
            $t->string('slug', 63)->index();
            $t->string('active_slug', 63)->nullable()->unique();
            $t->boolean('is_demonstration')->default(false);
            $t->string('admin_name', 180);
            $t->string('admin_email', 180);
            $t->char('payload_sha256', 64);
            $t->foreignId('tenant_id')->nullable()->constrained('tenants');
            $t->string('failure_code', 80)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        Schema::connection('central')->table('platform_audits', function (Blueprint $t) {
            $t->foreignId('provision_request_id')->nullable()->constrained('platform_provision_requests');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve o histórico de implantação; use migração corretiva.');
    }
};
