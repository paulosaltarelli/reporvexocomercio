<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_settings', function (Blueprint $t) {
            $t->id(); // Singleton id=1, resolved only in the tenant database.
            $t->string('provider', 40)->default('focus');
            $t->string('environment', 20)->default('homologation');
            $t->boolean('nfce_accredited')->default(false);
            $t->string('operation', 20)->nullable();
            $t->string('accountant_name', 180)->nullable();
            $t->string('accountant_email', 180)->nullable();
            $t->foreignId('updated_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('fiscal_preparations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('sale_id')->constrained('sales');
            $t->foreignId('requested_by')->constrained('users');
            $t->uuid('request_key')->unique();
            $t->string('state', 30)->default('review_required');
            $t->json('snapshot');
            $t->char('snapshot_sha256', 64);
            $t->json('blockers');
            $t->timestamps();
            $t->index(['sale_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_preparations');
        Schema::dropIfExists('fiscal_settings');
    }
};
