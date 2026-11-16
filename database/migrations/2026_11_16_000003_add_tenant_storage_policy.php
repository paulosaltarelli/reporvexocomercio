<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $t) {
            $t->string('storage_mode',12)->default('isolated');
            $t->boolean('is_demonstration')->default(false);
            $t->unsignedBigInteger('platform_version')->default(0);
            $t->timestamp('provisioned_at')->nullable();
            $t->dropUnique('tenants_db_name_unique');
            $t->index(['storage_mode','db_name']);
        });
    }
    public function down(): void
    {
        if (DB::connection('central')->table('tenants')->where('storage_mode','shared')->exists()) {
            throw new RuntimeException('Não reverta a política de armazenamento enquanto houver clientes na base compartilhada.');
        }
        Schema::connection('central')->table('tenants', function (Blueprint $t) {
            $t->dropIndex(['storage_mode','db_name']);
            $t->unique('db_name');
            $t->dropColumn(['storage_mode','is_demonstration','platform_version','provisioned_at']);
        });
    }
};
