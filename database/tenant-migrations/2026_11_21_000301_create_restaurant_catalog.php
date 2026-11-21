<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('restaurant_product_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products');
            $table->string('station', 16)->default('kitchen');
            $table->string('stock_mode', 16)->default('product');
            $table->boolean('show_on_menu')->default(true);
            $table->json('recipe');
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
        });
        Schema::connection('tenant')->create('restaurant_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('name', 100);
            $table->unsignedTinyInteger('min_choices')->default(0);
            $table->unsignedTinyInteger('max_choices')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'active']);
        });
        Schema::connection('tenant')->create('restaurant_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_modifier_group_id')->constrained('restaurant_modifier_groups');
            $table->string('name', 100);
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('ingredient_product_id')->nullable()->constrained('products');
            $table->decimal('ingredient_quantity', 15, 3)->nullable();
            $table->string('ingredient_unit', 10)->nullable();
            $table->timestamps();
            $table->index(['restaurant_modifier_group_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('restaurant_modifiers');
        Schema::connection('tenant')->dropIfExists('restaurant_modifier_groups');
        Schema::connection('tenant')->dropIfExists('restaurant_product_configs');
    }
};
