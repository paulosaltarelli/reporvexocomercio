<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('must_change_password')->default(false);
            $t->unsignedInteger('auth_version')->default(0);
            $t->timestamp('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['must_change_password', 'auth_version', 'password_changed_at']);
        });
    }
};
