<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('legal_name', 180);
            $t->string('trade_name', 180)->nullable();
            $t->string('document', 18)->nullable();
            $t->string('municipal_registration', 30)->nullable();
            $t->string('state_registration', 30)->nullable();
            $t->string('tax_regime', 30)->nullable();
            $t->string('email', 180)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('street', 180)->nullable();
            $t->string('number', 20)->nullable();
            $t->string('district', 100)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('state', 2)->nullable();
            $t->string('postal_code', 9)->nullable();
            $t->string('municipality_code', 7)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
