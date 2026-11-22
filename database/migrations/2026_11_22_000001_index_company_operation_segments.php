<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->string('operation_segment', 20)->nullable();
            $table->unsignedBigInteger('operation_segment_version')->nullable();
            $table->index(['operation_segment', 'active', 'name'], 'tenants_segment_active_name');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_segment_active_name');
            $table->dropColumn(['operation_segment', 'operation_segment_version']);
        });
    }
};
