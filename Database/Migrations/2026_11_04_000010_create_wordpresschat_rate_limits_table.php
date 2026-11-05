<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatRateLimitsTable extends Migration
{
    public function up()
    {
        // One row per rate-limit window (Services/RateLimiter): the bucket
        // is a SHA-256 hex of scope, key and window index.
        Schema::create('wordpresschat_rate_limits', function (Blueprint $table) {
            $table->string('bucket', 64)->primary();
            // Bytes for the upload scopes, so 64-bit.
            $table->bigInteger('hits')->unsigned();
            // The window's end; `wordpresschat:cleanup` deletes rows past
            // it. Nullable only because MySQL without
            // explicit_defaults_for_timestamp gives a NOT NULL first
            // TIMESTAMP column ON UPDATE CURRENT_TIMESTAMP, which every
            // increment would then move. Every row sets it.
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_rate_limits');
    }
}
