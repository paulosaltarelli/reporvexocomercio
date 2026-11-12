<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_tax_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120)->unique();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->json('parameters');
            $t->string('accountant_reference', 180);
            $t->foreignId('validated_by')->constrained('users');
            $t->timestamp('validated_at');
            $t->timestamps();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->foreignId('fiscal_tax_profile_id')->nullable()->constrained('fiscal_tax_profiles');
            $t->string('fiscal_gtin', 14)->nullable();
            $t->string('fiscal_cest', 7)->nullable();
        });
        Schema::table('product_groups', fn (Blueprint $t) => $t->foreignId('fiscal_tax_profile_id')->nullable()->constrained('fiscal_tax_profiles'));
        Schema::create('fiscal_tax_profile_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users');
            $t->foreignId('profile_id')->nullable()->constrained('fiscal_tax_profiles');
            $t->string('operation', 30);
            $t->uuid('request_key')->nullable()->unique();
            $t->char('payload_sha256', 64)->nullable();
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->json('summary')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os vínculos e a auditoria tributária; use migração corretiva.');
    }
};
