<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('delivery_integrations', function (Blueprint $t) {
            $t->id(); $t->string('provider', 20)->unique(); $t->boolean('enabled')->default(false);
            $t->boolean('manual_test_enabled')->default(false); $t->string('merchant_reference', 120)->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::connection('tenant')->create('delivery_catalog_mappings', function (Blueprint $t) {
            $t->id(); $t->string('provider', 20); $t->string('external_item_id', 100);
            $t->foreignId('product_id')->constrained(); $t->string('station', 20)->default('kitchen');
            $t->unsignedInteger('version')->default(1); $t->foreignId('user_id')->constrained(); $t->timestamps();
            $t->unique(['provider', 'external_item_id'], 'delivery_mapping_identity');
        });
        Schema::connection('tenant')->create('delivery_orders', function (Blueprint $t) {
            $t->id(); $t->string('provider', 20); $t->string('external_id', 100); $t->boolean('simulation')->default(true);
            $t->string('status', 20)->default('review'); $t->unsignedInteger('version')->default(1);
            $t->string('payment_status', 20); $t->decimal('items_total', 14, 2); $t->decimal('delivery_fee', 14, 2)->default(0);
            $t->decimal('platform_fee', 14, 2)->default(0); $t->decimal('total', 14, 2); $t->decimal('expected_payout', 14, 2);
            $t->string('customer_name', 160); $t->string('customer_phone', 40)->nullable(); $t->text('delivery_address')->nullable();
            $t->json('snapshot'); $t->char('snapshot_hash', 64); $t->text('review_reason')->nullable();
            $t->foreignId('user_id')->constrained(); $t->timestamp('accepted_at')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->timestamps(); $t->unique(['provider', 'simulation', 'external_id'], 'delivery_order_identity'); $t->index(['status', 'created_at']);
        });
        Schema::connection('tenant')->create('delivery_order_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('delivery_order_id')->constrained('delivery_orders'); $t->string('external_item_id', 100);
            $t->foreignId('product_id')->nullable()->constrained(); $t->string('description'); $t->decimal('quantity', 12, 3);
            $t->decimal('unit_price', 14, 2); $t->decimal('total', 14, 2); $t->decimal('catalog_unit_price', 14, 2)->nullable();
            $t->string('station', 20)->default('kitchen'); $t->string('status', 20)->default('review');
            $t->text('notes')->nullable(); $t->json('mapping_snapshot')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamps();
            $t->index(['status', 'station', 'sent_at'], 'delivery_production_queue');
        });
        Schema::connection('tenant')->create('delivery_events', function (Blueprint $t) {
            $t->id(); $t->string('provider', 20); $t->string('direction', 10); $t->boolean('simulation')->default(true); $t->string('event_key', 120);
            $t->foreignId('delivery_order_id')->nullable()->constrained('delivery_orders'); $t->string('action', 40);
            $t->char('payload_hash', 64); $t->json('payload'); $t->string('status', 20)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0); $t->timestamp('next_attempt_at')->nullable();
            $t->text('last_error')->nullable(); $t->timestamp('processed_at')->nullable(); $t->foreignId('user_id')->constrained(); $t->timestamps();
            $t->unique(['provider', 'direction', 'simulation', 'event_key'], 'delivery_event_identity'); $t->index(['status', 'next_attempt_at']);
        });
    }
    public function down(): void
    {
        foreach (['delivery_events', 'delivery_order_items', 'delivery_orders', 'delivery_catalog_mappings', 'delivery_integrations'] as $table) Schema::connection('tenant')->dropIfExists($table);
    }
};
