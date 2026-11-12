<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('sales',fn(Blueprint $t)=>$t->index(['status','created_at'],'sales_status_created_idx'));Schema::table('stock_movements',fn(Blueprint $t)=>$t->index('created_at','stock_movement_created_idx'));}
 public function down():void{Schema::table('sales',fn(Blueprint $t)=>$t->dropIndex('sales_status_created_idx'));Schema::table('stock_movements',fn(Blueprint $t)=>$t->dropIndex('stock_movement_created_idx'));}
};
