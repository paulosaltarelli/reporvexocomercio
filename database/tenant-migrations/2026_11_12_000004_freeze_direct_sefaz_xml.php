<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $t) {
            $t->longText('signed_xml')->nullable(); // Application encrypted cast; never public storage.
            $t->char('signed_xml_sha256', 64)->nullable();
        });
        Schema::table('fiscal_credentials', function (Blueprint $t) {
            $t->string('provider_company_id', 30)->nullable()->change();
            $t->char('validated_hash', 64)->nullable();
        });
        Schema::table('fiscal_settings', function (Blueprint $t) {
            $t->string('provider', 40)->default('sefaz_direct')->change();
        });
        // Existing documents and remote-provider validations are deliberately not
        // promoted to direct-SEFAZ readiness or silently rebuilt under a new key.
    }

    public function down(): void
    {
        throw new RuntimeException('XML assinado e validações fiscais não admitem reversão destrutiva.');
    }
};
