<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::connection('tenant')->create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables');
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 20)->default('open');
            $table->foreignId('sale_id')->nullable()->unique()->constrained();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['restaurant_table_id', 'status']);
        });
        Schema::connection('tenant')->create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_order_id')->constrained('restaurant_orders');
            $table->uuid('request_key')->unique();
            $table->foreignId('product_id')->constrained();
            $table->string('description', 255);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->json('commercial_terms');
            $table->string('station', 16);
            $table->string('status', 20)->default('draft');
            $table->string('notes', 1000)->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['restaurant_order_id', 'status']);
            $table->index(['station', 'status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('restaurant_order_items');
        Schema::connection('tenant')->dropIfExists('restaurant_orders');
        Schema::connection('tenant')->dropIfExists('restaurant_tables');
    }
};
