<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registration that is waiting for its password, the reminder email is only sent while the row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partial_registration', static function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->string('token')->primary();
            $table->string('email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partial_registration');
    }
};
