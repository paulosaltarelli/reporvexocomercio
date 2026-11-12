<?php

use App\Services\Decimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('type', 12)->default('product');
            $t->boolean('controls_stock')->default(true);
            $t->decimal('reference_cost', 16, 4)->default(0);
            $t->foreignId('legacy_service_id')->nullable()->unique()->constrained('services');
            $t->string('municipal_service_code', 30)->nullable();
            $t->decimal('iss_rate', 5, 2)->nullable();
        });
        Schema::table('price_reviews', function (Blueprint $t) {
            $t->string('cost_source', 20)->default('average_cost');
        });
        Schema::table('price_changes', function (Blueprint $t) {
            $t->string('cost_source', 20)->default('average_cost');
        });

        if (! DB::table('services')->exists()) {
            return;
        }
        // Do not overwrite an existing group's settings. Imported services retain
        // explicit alert mode because their legacy fees were manually maintained.
        DB::table('product_groups')->insertOrIgnore([
            'name' => 'Serviços', 'margin' => '30.00', 'price_policy' => 'alert',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $group = DB::table('product_groups')->where('name', 'Serviços')->first();
        DB::table('services')->orderBy('id')->chunkById(100, function ($services) use ($group) {
            foreach ($services as $service) {
                $suffix = 0;
                while (true) {
                    $sku = 'LEGACY-SERVICE-'.$service->id.($suffix === 0 ? '' : '-'.$suffix);
                    if (DB::table('products')->where('sku', $sku)->exists()) {
                        $suffix++;
                        continue;
                    }
                    try {
                        DB::transaction(function () use ($service, $group, $sku) {
                            $id = DB::table('products')->insertGetId([
                                'name' => $service->name, 'sku' => $sku, 'product_group_id' => $group->id,
                                'type' => 'service', 'controls_stock' => false, 'unit' => 'UN',
                                'sale_price' => $service->price, 'reference_cost' => $service->cost,
                                'average_cost' => '0.0000', 'quantity' => '0.000', 'minimum_stock' => '0.000',
                                'price_policy' => 'alert', 'legacy_service_id' => $service->id,
                                'municipal_service_code' => $service->municipal_service_code,
                                'iss_rate' => $service->iss_rate, 'active' => $service->active,
                                'created_at' => $service->created_at ?? now(), 'updated_at' => now(),
                            ]);
                            $state = 'no_cost';
                            $suggestion = null;
                            $attention = false;
                            if (bccomp($service->cost, '0', 4) > 0) {
                                $candidate = Decimal::price($service->cost, $group->margin);
                                $state = bccomp($candidate, '999999999999.99', 2) > 0 ? 'overflow' : 'ready';
                                $suggestion = $state === 'ready' ? $candidate : null;
                                $attention = $state === 'overflow' || bccomp($service->price, $candidate, 2) !== 0;
                            }
                            DB::table('price_reviews')->insert([
                                'product_id' => $id, 'suggested_price' => $suggestion,
                                'cost_snapshot' => $service->cost, 'cost_source' => 'reference_cost',
                                'margin_snapshot' => $group->margin, 'mode' => 'alert', 'state' => $state,
                                'needs_attention' => $attention, 'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }, 3);
                        break;
                    } catch (UniqueConstraintViolationException $exception) {
                        // Only retry SKU races; never discard another unique-key problem.
                        if (! DB::table('products')->where('sku', $sku)->exists()) {
                            throw $exception;
                        }
                        $suffix++;
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Imported rows may already have been sold through product_id. Removing
        // them or forgetting their type would rewrite history; rollback is unsafe.
        throw new \RuntimeException('A unificação de serviços não possui rollback destrutivo. Restaure um backup verificado ou aplique uma migração corretiva.');
    }
};
