<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('platform_provision_requests', function (Blueprint $table) {
            $table->string('activation_status', 16)->nullable()->index();
            $table->char('activation_digest', 64)->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('activation_retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('platform_provision_requests', function (Blueprint $table) {
            $table->dropIndex(['activation_status']);
            $table->dropColumn(['activation_status', 'activation_digest', 'activation_expires_at', 'activation_retry_at']);
        });
    }
};
