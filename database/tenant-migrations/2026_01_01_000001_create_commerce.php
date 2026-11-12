<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('operator');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->decimal('margin', 5, 2)->default(30);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_group_id')->constrained();
            $t->string('name');
            $t->string('sku')->unique();
            $t->string('barcode')->nullable()->unique();
            $t->string('unit', 10)->default('UN');
            $t->decimal('margin', 5, 2)->nullable();
            $t->decimal('sale_price', 14, 2);
            $t->decimal('average_cost', 16, 4)->default(0);
            $t->decimal('quantity', 16, 3)->default(0);
            $t->decimal('minimum_stock', 16, 3)->default(0);
            $t->string('ncm', 8)->nullable();
            $t->string('origin', 1)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('document', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->decimal('price', 14, 2);
            $t->decimal('cost', 14, 2)->default(0);
            $t->string('municipal_service_code', 30)->nullable();
            $t->decimal('iss_rate', 5, 2)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('status')->default('completed');
            $t->string('payment_method', 20);
            $t->boolean('paid');
            $t->date('due_date')->nullable();
            $t->decimal('total', 14, 2);
            $t->decimal('cost_total', 16, 4);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('product_id')->nullable()->constrained();
            $t->foreignId('service_id')->nullable()->constrained();
            $t->string('description');
            $t->decimal('quantity', 16, 3);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('unit_cost', 16, 4);
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->string('request_key')->unique();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->string('type');
            $t->decimal('quantity', 16, 3);
            $t->decimal('unit_cost', 16, 4);
            $t->decimal('balance', 16, 3);
            $t->decimal('average_cost', 16, 4);
            $t->text('reason');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'sale_items', 'sales', 'services', 'customers', 'products', 'product_groups', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
