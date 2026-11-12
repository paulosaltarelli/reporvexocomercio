<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $t) {
            $t->boolean('discount_enabled')->default(false);
            $t->decimal('max_discount_percent', 5, 2)->default(0);
            $t->decimal('commission_percent', 5, 2)->default(0);
        });
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('discount_enabled')->nullable();
            $t->decimal('max_discount_percent', 5, 2)->nullable();
            $t->decimal('commission_percent', 5, 2)->nullable();
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->foreignId('seller_id')->nullable()->constrained('users');
            $t->foreignId('document_id')->nullable()->unique()->constrained('documents');
            $t->string('seller_name')->nullable();
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('discount_total', 14, 2)->default(0);
            $t->decimal('commission_total', 14, 2)->default(0);
        });
        Schema::table('sale_items', function (Blueprint $t) {
            $t->decimal('catalog_unit_price', 14, 2)->default(0);
            $t->decimal('offered_unit_price', 14, 2)->default(0);
            $t->decimal('explicit_discount_percent', 5, 2)->default(0);
            $t->decimal('discount_percent', 7, 4)->default(0);
            $t->decimal('gross_total', 14, 2)->default(0);
            $t->decimal('discount_amount', 14, 2)->default(0);
            $t->boolean('discount_allowed')->default(false);
            $t->decimal('discount_limit_percent', 5, 2)->default(0);
            $t->decimal('commission_rate', 5, 2)->default(0);
            $t->decimal('commission_amount', 14, 2)->default(0);
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->unsignedTinyInteger('commercial_version')->default(0);
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('discount_total', 14, 2)->default(0);
        });
        // Backfill only new columns. Never infer historical discounts or commissions
        // from today's catalog and never alter the original totals or event dates.
        DB::table('sales')->update(['subtotal' => DB::raw('total')]);
        DB::table('sale_items')->update(['gross_total' => DB::raw('total'),
            'catalog_unit_price' => DB::raw('unit_price'), 'offered_unit_price' => DB::raw('unit_price')]);
        DB::table('documents')->update(['subtotal' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['commercial_version', 'subtotal', 'discount_total']));
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn(['catalog_unit_price', 'offered_unit_price', 'explicit_discount_percent', 'discount_percent', 'gross_total', 'discount_amount', 'discount_allowed', 'discount_limit_percent', 'commission_rate', 'commission_amount']));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropForeign(['seller_id']);
            $t->dropForeign(['document_id']);
            $t->dropUnique(['document_id']);
            $t->dropColumn(['seller_id', 'document_id', 'seller_name', 'subtotal', 'discount_total', 'commission_total']);
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['discount_enabled', 'max_discount_percent', 'commission_percent']));
        Schema::table('product_groups', fn (Blueprint $t) => $t->dropColumn(['discount_enabled', 'max_discount_percent', 'commission_percent']));
    }
};
