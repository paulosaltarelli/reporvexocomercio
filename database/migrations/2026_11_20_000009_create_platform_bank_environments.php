<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('platform_bank_environments', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 32);
            $table->string('environment', 16);
            $table->boolean('enabled');
            $table->boolean('issue');
            $table->boolean('reconcile');
            $table->boolean('auto_issue');
            $table->timestamps();
            $table->unique(['bank', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('platform_bank_environments');
    }
};
