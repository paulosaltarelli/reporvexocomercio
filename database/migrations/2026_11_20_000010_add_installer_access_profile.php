<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('platform_installer_invitations', function (Blueprint $table) {
            $table->string('access_profile', 16)->default('installer');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve a configuração de acesso de cada empresa.');
    }
};
