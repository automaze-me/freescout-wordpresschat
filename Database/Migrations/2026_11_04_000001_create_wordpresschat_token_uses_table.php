<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatTokenUsesTable extends Migration
{
    public function up()
    {
        // One row per identity token FreeScout accepted: the unique jti is
        // what makes a token single use.
        Schema::create('wordpresschat_token_uses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('jti', 32)->unique();
            // Nullable only because MySQL without explicit_defaults_for_timestamp
            // gives a NOT NULL first TIMESTAMP column ON UPDATE CURRENT_TIMESTAMP.
            // Every row sets it.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_token_uses');
    }
}
