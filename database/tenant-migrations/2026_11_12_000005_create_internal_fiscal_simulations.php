<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_simulations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('sale_id')->constrained('sales');
            $t->foreignId('requested_by')->constrained('users');
            $t->uuid('reference')->unique();
            $t->uuid('request_key')->unique();
            $t->string('scenario', 20);
            $t->string('state', 20);
            $t->json('snapshot');
            $t->char('snapshot_sha256', 64);
            $t->timestamp('consulted_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason', 255)->nullable();
            $t->timestamps();
        });
        Schema::create('fiscal_simulation_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('simulation_id')->constrained('fiscal_simulations');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key')->unique();
            $t->string('operation', 20);
            $t->string('scenario', 20);
            $t->string('state_before', 20)->nullable();
            $t->string('state_after', 20);
            $t->string('reason', 255)->nullable();
            $t->timestamps();
        });

    }

    public function down(): void
    {
        throw new RuntimeException('Preserve o histórico de simulações; utilize uma migração corretiva.');
    }
};
