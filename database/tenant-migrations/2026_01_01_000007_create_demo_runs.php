<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_runs', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->date('anchor_date');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('completed_at')->nullable();
            $t->json('summary')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_runs');
    }
};
