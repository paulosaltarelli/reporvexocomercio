<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_model_configurations', function (Blueprint $t) {
            $t->id(); $t->foreignId('issuer_id')->constrained('fiscal_issuers');
            $t->unsignedSmallInteger('model'); $t->boolean('enabled')->default(false);
            $t->json('configuration'); $t->char('validated_hash', 64)->nullable(); $t->timestamps();
            $t->unique(['issuer_id', 'model']);
        });
        Schema::table('customers', function (Blueprint $t) {
            foreach (['street', 'number', 'complement', 'district', 'city'] as $field) { $t->string('fiscal_'.$field, 60)->nullable(); }
            $t->string('fiscal_state', 2)->nullable(); $t->string('fiscal_postal_code', 8)->nullable();
            $t->string('fiscal_municipality_code', 7)->nullable(); $t->string('fiscal_ie_indicator', 1)->nullable();
            $t->string('fiscal_state_registration', 14)->nullable();
        });
    }
    public function down(): void { throw new RuntimeException('Preserve os dados e documentos fiscais; use migração corretiva.'); }
};
