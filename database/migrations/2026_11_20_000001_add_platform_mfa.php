<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('platform_admins', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable();
            $table->unsignedBigInteger('mfa_last_step')->nullable();
            $table->text('mfa_recovery')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve os fatores cadastrados. Use uma migração corretiva.');
    }
};
