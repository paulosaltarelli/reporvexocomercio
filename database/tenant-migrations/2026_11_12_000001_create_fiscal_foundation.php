<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_issuers', function (Blueprint $t) {
            $t->id();
            $t->string('document', 14); // Preserve alphanumeric CNPJ; never cast to integer.
            $t->string('environment', 20)->default('homologation');
            $t->string('provider', 40);
            $t->boolean('enabled')->default(false);
            $t->json('validated_configuration')->nullable();
            $t->string('secret_reference')->nullable(); // Vault reference, NOT a credential.
            $t->timestamp('accountant_validated_at')->nullable();
            $t->timestamps();
            $t->unique(['document', 'environment']);
        });
        Schema::create('fiscal_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issuer_id')->constrained('fiscal_issuers');
            $t->unsignedSmallInteger('model');
            $t->unsignedSmallInteger('series');
            $t->unsignedInteger('next_number'); // Explicit onboarding reconciliation required.
            $t->unique(['issuer_id', 'model', 'series']);
        });
        Schema::create('fiscal_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issuer_id')->constrained('fiscal_issuers');
            $t->foreignId('sale_id')->constrained('sales');
            $t->uuid('reference')->unique();
            $t->unsignedSmallInteger('model');
            $t->unsignedSmallInteger('series');
            $t->unsignedInteger('number');
            $t->string('state', 30)->default('prepared')->index();
            $t->json('snapshot');
            $t->char('snapshot_sha256', 64);
            $t->string('access_key', 60)->nullable()->unique();
            $t->string('protocol', 60)->nullable();
            $t->string('official_code', 10)->nullable();
            $t->timestamp('authorized_at')->nullable();
            $t->timestamps();
            $t->unique(['issuer_id', 'model', 'series', 'number'], 'fiscal_number_unique');
            $t->unique(['issuer_id', 'sale_id', 'model'], 'fiscal_sale_unique');
        });
        Schema::create('fiscal_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('fiscal_documents');
            $t->unsignedBigInteger('tenant_id'); // Validated against central DB by worker.
            $t->uuid('operation_id')->unique();
            $t->string('operation', 30);
            $t->json('parameters')->nullable(); // No credentials; cancellation reason etc.
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->index();
            $t->timestamp('leased_until')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('fiscal_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('fiscal_documents');
            $t->string('event_reference', 120)->unique();
            $t->string('type', 40);
            $t->string('official_code', 10)->nullable();
            $t->string('protocol', 60)->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
        });
        Schema::create('fiscal_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('fiscal_documents');
            $t->string('kind', 30);
            $t->string('private_path');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->timestamps();
            $t->unique(['document_id', 'kind', 'sha256']);
        });
    }

    public function down(): void
    {
        foreach (['fiscal_files', 'fiscal_events', 'fiscal_outbox', 'fiscal_documents', 'fiscal_sequences', 'fiscal_issuers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
