<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_configurations', function (Blueprint $t) {
            $t->id();
            $t->string('cnpj', 14)->unique();
            $t->string('environment', 20)->default('restricted');
            $t->boolean('enabled')->default(false);
            $t->boolean('national_issuer_confirmed')->default(false);
            $t->json('parameters');
            $t->longText('secrets')->nullable();
            $t->string('certificate_fingerprint', 64)->nullable();
            $t->timestamp('certificate_valid_until')->nullable();
            $t->foreignId('updated_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('nfse_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('configuration_id')->constrained('nfse_configurations');
            $t->unsignedInteger('series');
            $t->unsignedBigInteger('next_number');
            $t->unique(['configuration_id', 'series']);
        });
        Schema::create('nfse_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('configuration_id')->constrained('nfse_configurations');
            $t->foreignId('sale_id')->unique()->constrained('sales');
            $t->foreignId('created_by')->constrained('users');
            $t->uuid('request_key')->unique();
            $t->string('environment', 20)->default('restricted');
            $t->string('status', 30)->default('draft_validated');
            $t->string('dps_id', 45)->unique();
            $t->unsignedInteger('series');
            $t->unsignedBigInteger('number');
            $t->date('competence');
            $t->decimal('total', 14, 2);
            $t->json('snapshot');
            $t->char('snapshot_sha256', 64);
            $t->longText('signed_xml');
            $t->char('xml_sha256', 64);
            $t->text('blocking_reason');
            $t->timestamps();
            $t->unique(['configuration_id', 'series', 'number']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve a numeração e os XMLs de DPS; use migração corretiva.');
    }
};
