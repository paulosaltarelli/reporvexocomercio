<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            // Existing and new tenants are blocked until explicitly classified.
            $t->boolean('fiscal_demonstration')->default(true);
            $t->timestamp('fiscal_production_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['fiscal_demonstration', 'fiscal_production_approved_at']);
        });
    }
};
