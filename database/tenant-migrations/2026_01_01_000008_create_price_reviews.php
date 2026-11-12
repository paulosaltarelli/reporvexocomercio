<?php

use App\Services\Decimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $t) {
            $t->string('price_policy', 12)->default('alert');
        });
        Schema::table('products', function (Blueprint $t) {
            $t->string('price_policy', 12)->nullable();
        });
        Schema::create('price_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained();
            $t->decimal('suggested_price', 14, 2)->nullable();
            $t->decimal('cost_snapshot', 16, 4);
            $t->decimal('margin_snapshot', 5, 2);
            $t->string('mode', 12);
            $t->string('state', 20);
            $t->boolean('needs_attention')->default(false);
            $t->timestamps();
            $t->index(['needs_attention', 'state']);
        });
        Schema::create('price_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->decimal('before_price', 14, 2);
            $t->decimal('after_price', 14, 2);
            $t->decimal('average_cost', 16, 4);
            $t->decimal('margin', 5, 2);
            $t->string('mode', 12);
            $t->string('source', 100);
            $t->uuid('request_key')->nullable()->unique();
            $t->timestamps();
            $t->index(['product_id', 'created_at']);
        });

        // Existing prices stay untouched. New alert reviews expose any current
        // divergence without inventing a historical change or enabling automation.
        DB::table('products as p')->join('product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->select('p.id', 'p.average_cost', 'p.sale_price', 'p.margin as product_margin', 'g.margin as group_margin')
            ->orderBy('p.id')->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $margin = $product->product_margin ?? $product->group_margin;
                    $state = 'no_cost';
                    $suggestion = null;
                    $attention = false;
                    if (bccomp($product->average_cost, '0', 4) > 0) {
                        $candidate = Decimal::price($product->average_cost, $margin);
                        $state = bccomp($candidate, '999999999999.99', 2) > 0 ? 'overflow' : 'ready';
                        $suggestion = $state === 'ready' ? $candidate : null;
                        $attention = $state === 'overflow' || bccomp($product->sale_price, $candidate, 2) !== 0;
                    }
                    DB::table('price_reviews')->insert([
                        'product_id' => $product->id, 'suggested_price' => $suggestion,
                        'cost_snapshot' => $product->average_cost, 'margin_snapshot' => $margin,
                        'mode' => 'alert', 'state' => $state, 'needs_attention' => $attention,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }, 'p.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_changes');
        Schema::dropIfExists('price_reviews');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('price_policy'));
        Schema::table('product_groups', fn (Blueprint $t) => $t->dropColumn('price_policy'));
    }
};
