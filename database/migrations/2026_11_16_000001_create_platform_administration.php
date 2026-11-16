<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('platform_admins', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->string('email', 180)->unique();
            $t->string('password');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('auth_version')->default(1);
            $t->timestamps();
        });
        Schema::connection('central')->create('platform_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_admin_id')->nullable()->constrained('platform_admins');
            $t->foreignId('tenant_id')->nullable()->constrained('tenants');
            $t->string('action', 80);
            $t->text('reason')->nullable();
            $t->json('changes')->nullable();
            $t->string('actor_name', 180);
            $t->timestamps();
            $t->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os administradores e a auditoria central; use migração corretiva.');
    }
};
