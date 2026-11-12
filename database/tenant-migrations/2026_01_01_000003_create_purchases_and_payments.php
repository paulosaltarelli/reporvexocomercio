<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('reference', 100)->nullable();
            $t->date('purchased_on');
            $t->text('notes')->nullable();
            $t->decimal('total', 14, 2);
            $t->string('payment_method', 20);
            $t->boolean('paid')->default(false);
            $t->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('description');
            $t->decimal('quantity', 16, 3);
            $t->decimal('unit_cost', 16, 4);
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });
        Schema::table('financial_entries', function (Blueprint $t) {
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->foreignId('purchase_id')->nullable()->constrained();
            $t->uuid('schedule_key')->nullable();
            $t->unsignedTinyInteger('installment_no')->default(1);
            $t->unsignedTinyInteger('installments_count')->default(1);
            $t->unique(['schedule_key', 'installment_no']);
            $t->decimal('paid_amount', 14, 2)->default(0);
            $t->decimal('legacy_paid_amount', 14, 2)->default(0);
            $t->boolean('legacy_import')->default(false);
            $t->timestamp('cancelled_at')->nullable();
            $t->index(['direction', 'cancelled_at', 'due_date']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('entry_id')->constrained('financial_entries');
            $t->foreignId('user_id')->constrained();
            $t->decimal('amount', 14, 2);
            $t->date('paid_on');
            $t->string('method', 20);
            $t->string('direction', 3);
            $t->string('kind', 10)->default('payment');
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('payments');
            $t->timestamps();
            $t->index(['paid_on', 'direction']);
        });

        // Preserve legacy settlements without inventing a payment event or its date.
        DB::table('financial_entries')->update(['legacy_import' => true, 'schedule_key' => DB::raw('request_key')]);
        DB::table('financial_entries')->whereNotNull('paid_at')->update([
            'paid_amount' => DB::raw('amount'), 'legacy_paid_amount' => DB::raw('amount'),
        ]);
        DB::table('sales')->where('status', 'completed')->orderBy('id')->chunkById(100, function ($sales) {
            foreach ($sales as $sale) {
                if (bccomp($sale->total, '0', 2) === 0) {
                    DB::table('sales')->where('id', $sale->id)->update(['paid' => true]);

                    continue;
                }
                $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:legacy-sale:'.$sale->request_key)->toString();
                DB::table('financial_entries')->insert([
                    'request_key' => $key, 'schedule_key' => $key,
                    'user_id' => $sale->user_id, 'sale_id' => $sale->id,
                    'direction' => 'in', 'description' => 'Venda #'.$sale->id.' (saldo legado)',
                    'category' => 'Vendas', 'amount' => $sale->total,
                    'due_date' => $sale->due_date ?? substr($sale->created_at, 0, 10),
                    'paid_amount' => $sale->paid ? $sale->total : '0.00',
                    'legacy_paid_amount' => $sale->paid ? $sale->total : '0.00',
                    'legacy_import' => true, 'paid_at' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('financial_entries', function (Blueprint $t) {
            $t->dropForeign(['sale_id']);
            $t->dropForeign(['purchase_id']);
            $t->dropUnique(['schedule_key', 'installment_no']);
            $t->dropIndex(['direction', 'cancelled_at', 'due_date']);
            $t->dropColumn(['sale_id', 'purchase_id', 'schedule_key', 'installment_no', 'installments_count', 'paid_amount', 'legacy_paid_amount', 'legacy_import', 'cancelled_at']);
        });
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
