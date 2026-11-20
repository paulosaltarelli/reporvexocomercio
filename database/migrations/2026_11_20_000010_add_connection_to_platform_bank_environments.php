<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('platform_bank_environments', function (Blueprint $table) {
            $table->json('connection_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('platform_bank_environments', function (Blueprint $table) {
            $table->dropColumn('connection_settings');
        });
    }
};
