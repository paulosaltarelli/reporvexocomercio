<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('supermarket_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('terminal_number');
            // NULL releases the unique slots while preserving closed shifts.
            $table->boolean('open_slot')->nullable();
            $table->unique(['user_id', 'open_slot']);
            $table->unique(['terminal_number', 'open_slot']);
            $table->uuid('open_request_key')->unique();
            $table->uuid('close_request_key')->nullable()->unique();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('closing_reason', 16)->nullable();
            $table->decimal('opening_balance', 14, 2);
            $table->decimal('expected_balance', 19, 2)->nullable();
            $table->decimal('counted_balance', 19, 2)->nullable();
            $table->decimal('difference', 19, 2)->nullable();
            $table->unsignedInteger('sale_count')->default(0);
            $table->json('received_by_method');
        });
        Schema::connection('tenant')->create('supermarket_shift_carts', function (Blueprint $table) {
            $table->foreignId('cart_id')->primary()->constrained('supermarket_carts');
            $table->foreignId('shift_id')->constrained('supermarket_shifts');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('supermarket_shift_carts');
        Schema::connection('tenant')->dropIfExists('supermarket_shifts');
    }
};
