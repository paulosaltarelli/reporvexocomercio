<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('restaurant_order_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_order_id')->constrained('restaurant_orders');
            $table->uuid('division_key');
            $table->unsignedTinyInteger('share_index');
            $table->string('person_name', 100);
            $table->decimal('amount', 14, 2);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['division_key', 'share_index']);
        });
        Schema::connection('tenant')->create('restaurant_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_order_id')->constrained('restaurant_orders');
            $table->uuid('request_key')->unique();
            $table->foreignId('financial_entry_id')->unique()->constrained('financial_entries');
            $table->foreignId('payment_id')->unique()->constrained('payments');
            $table->foreignId('share_id')->nullable()->constrained('restaurant_order_shares');
            $table->foreignId('fee_entry_id')->nullable()->constrained('financial_entries');
            $table->decimal('base_amount', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2);
            $table->decimal('passed_fee', 14, 2);
            $table->json('payment_snapshot');
            $table->string('allocation_type', 16);
            $table->foreignId('user_id')->constrained();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refund_payment_id')->nullable()->unique()->constrained('payments');
            $table->uuid('refund_request_key')->nullable()->unique();
            $table->string('refund_reason', 1000)->nullable();
            $table->timestamps();
        });
        Schema::connection('tenant')->create('restaurant_payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_order_payment_id')->constrained('restaurant_order_payments');
            $table->foreignId('restaurant_order_item_id')->constrained('restaurant_order_items');
            $table->decimal('quantity', 16, 3);
            $table->decimal('amount', 14, 2);
            $table->string('description', 255);
            $table->decimal('unit_price', 14, 2);
            $table->unique(['restaurant_order_payment_id', 'restaurant_order_item_id'], 'restaurant_payment_item_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('restaurant_payment_items');
        Schema::connection('tenant')->dropIfExists('restaurant_order_payments');
        Schema::connection('tenant')->dropIfExists('restaurant_order_shares');
    }
};
