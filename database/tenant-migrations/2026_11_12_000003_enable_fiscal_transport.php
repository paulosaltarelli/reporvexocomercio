<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', fn (Blueprint $t) => $t->string('fiscal_kind', 20)->nullable());
        Schema::create('fiscal_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issuer_id')->unique()->constrained('fiscal_issuers');
            $t->longText('secrets');
            $t->char('certificate_fingerprint', 64);
            $t->string('certificate_document', 14);
            $t->timestamp('valid_from');
            $t->timestamp('valid_until');
            $t->string('provider_company_id', 30);
            $t->char('synced_hash', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('fiscal_item_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained('products');
            $t->json('parameters');
            $t->foreignId('validated_by')->constrained('users');
            $t->string('accountant_reference', 180);
            $t->timestamps();
        });
        Schema::table('fiscal_documents', function (Blueprint $t) {
            $t->text('operator_message')->nullable();
            $t->timestamp('cancelled_at')->nullable();
        });
        Schema::create('fiscal_voids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issuer_id')->constrained('fiscal_issuers');
            $t->uuid('operation_id')->unique();
            $t->unsignedSmallInteger('model');
            $t->unsignedSmallInteger('series');
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('first_number');
            $t->unsignedInteger('last_number');
            $t->string('state', 30)->default('pending');
            $t->string('reason', 255);
            $t->string('protocol', 60)->nullable();
            $t->string('official_code', 10)->nullable();
            $t->foreignId('requested_by')->constrained('users');
            $t->timestamps();
            $t->longText('encrypted_xml')->nullable();
            $t->char('xml_sha256', 64)->nullable();
        });
        Schema::table('fiscal_outbox', function (Blueprint $t) {
            $t->unsignedBigInteger('document_id')->nullable()->change();
            $t->foreignId('void_id')->nullable()->constrained('fiscal_voids');
            $t->string('last_error', 100)->nullable();
            $t->timestamp('resend_requested_at')->nullable();
            $t->foreignId('resend_requested_by')->nullable()->constrained('users');
        });
        Schema::table('fiscal_events', fn (Blueprint $t) => $t->foreignId('requested_by')->nullable()->constrained('users'));
    }

    public function down(): void
    {
        throw new RuntimeException('Reversão fiscal destrutiva recusada. Use migração corretiva ou recuperação revisada.');
    }
};
