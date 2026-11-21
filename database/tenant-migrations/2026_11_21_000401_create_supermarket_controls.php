<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('supermarket_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('cart_id')->constrained('supermarket_carts');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->unsignedInteger('cart_version');
            $table->string('action', 16);
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('reason', 1000);
            $table->string('decision_reason', 1000)->nullable();
            $table->string('status', 16)->default('pending');
            $table->dateTime('expires_at');
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['cart_id', 'requested_by']);
        });
        Schema::connection('tenant')->create('supermarket_draft_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('cart_id')->constrained('supermarket_carts');
            $table->foreignId('user_id')->constrained('users');
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->uuid('line_key');
            $table->unsignedInteger('cart_version');
            $table->timestamp('created_at');
            $table->index(['cart_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('supermarket_draft_imports');
        Schema::connection('tenant')->dropIfExists('supermarket_approvals');
    }
};
