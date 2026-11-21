<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('supermarket_carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(0);
            $table->json('items');
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('sale_id')->nullable()->unique()->constrained('sales');
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('supermarket_carts');
    }
};
