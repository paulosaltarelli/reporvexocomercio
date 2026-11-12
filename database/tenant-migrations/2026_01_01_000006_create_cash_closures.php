<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared by payments and exclusive for close: serializes calendar boundaries,
        // while allowing different open days to receive payments concurrently.
        Schema::create('cash_closure_locks', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
        });
        DB::table('cash_closure_locks')->insert(['id' => 1]);

        Schema::create('cash_days', function (Blueprint $t) {
            $t->date('date')->primary();
            $t->timestamp('closed_at')->nullable();
        });

        Schema::create('cash_closures', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->date('date')->unique();
            $t->foreignId('user_id')->constrained();
            $t->decimal('opening_balance', 14, 2);
            $t->decimal('cash_in', 14, 2);
            $t->decimal('cash_out', 14, 2);
            $t->decimal('expected_balance', 14, 2);
            $t->decimal('counted_balance', 14, 2);
            $t->decimal('difference', 14, 2);
            $t->json('snapshot');
            $t->text('notes')->nullable();
            $t->timestamp('closed_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closures');
        Schema::dropIfExists('cash_days');
        Schema::dropIfExists('cash_closure_locks');
    }
};
