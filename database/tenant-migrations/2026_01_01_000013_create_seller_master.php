<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('user_id')->nullable()->unique()->constrained('users');
            $t->boolean('active')->default(true);
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->foreignId('salesperson_id')->nullable()->constrained('sellers');
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('salesperson_id')->nullable()->constrained('sellers');
            $t->string('seller_name')->nullable();
        });
        Schema::create('seller_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents');
            $t->foreignId('previous_seller_id')->nullable()->constrained('sellers');
            $t->foreignId('new_seller_id')->constrained('sellers');
            $t->string('previous_seller_name')->nullable();
            $t->string('new_seller_name');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('actor_name');
            $t->text('reason');
            $t->string('source', 20);
            $t->timestamps();
            $t->index(['document_id', 'created_at']);
        });
        DB::table('users')->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $id = DB::table('sellers')->insertGetId(['name' => $user->name, 'user_id' => $user->id,
                    'active' => $user->active, 'email' => $user->email,
                    'created_at' => now(), 'updated_at' => now()]);
                // Only a previously explicit seller_id is proof. Creator user_id is not.
                DB::table('sales')->where('seller_id', $user->id)->update(['salesperson_id' => $id]);
            }
        });
        // Existing documents remain unassigned: their creator never proved seller ownership.
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_assignments');
        Schema::table('documents', function (Blueprint $t) {
            $t->dropForeign(['salesperson_id']);
            $t->dropColumn(['salesperson_id', 'seller_name']);
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->dropForeign(['salesperson_id']);
            $t->dropColumn('salesperson_id');
        });
        Schema::dropIfExists('sellers');
    }
};
