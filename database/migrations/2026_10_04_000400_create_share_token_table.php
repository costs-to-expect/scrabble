<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A share link's parameters hold the owner's bearer token, so they are stored encrypted with the application key
 * (App\Casts\EncryptedParameters). Encrypted values are not JSON, the column is text.
 *
 * Changing APP_KEY makes the stored parameters unreadable, the links of games in progress stop working (links only
 * live until the game is finished) so change it between games.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_token', static function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->string('token')->primary();
            $table->string('game_id')->index();
            $table->string('player_id');
            $table->text('parameters');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_token');
    }
};
