<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('platform_contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('implantation_grace_days')->default(0);
            $table->unsignedSmallInteger('overdue_grace_days')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('platform_contracts', function (Blueprint $table) {
            $table->dropColumn(['implantation_grace_days', 'overdue_grace_days']);
        });
    }
};
