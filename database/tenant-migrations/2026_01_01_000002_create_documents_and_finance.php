<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('document', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('status')->default('draft');
            $t->uuid('request_key')->unique();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('sale_id')->nullable()->unique()->constrained();
            $t->json('items');
            $t->decimal('total', 14, 2);
            $t->date('valid_until')->nullable();
            $t->string('asset')->nullable();
            $t->string('technician')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('financial_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->string('direction');
            $t->string('description');
            $t->string('category');
            $t->decimal('amount', 14, 2);
            $t->date('due_date');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_entries');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('suppliers');
    }
};
