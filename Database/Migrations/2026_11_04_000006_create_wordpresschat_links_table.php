<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatLinksTable extends Migration
{
    public function up()
    {
        // Continue links (spec §6.2): single-use, they verify the browser
        // that redeems them for one conversation. Only the token's SHA-256
        // is stored, so a database read never yields a usable link.
        Schema::create('wordpresschat_links', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('site_id')->unsigned();
            $table->integer('conversation_id')->unsigned()->index();
            $table->string('token_hash', 64)->unique();
            // Nullable only because MySQL without explicit_defaults_for_timestamp
            // gives a NOT NULL first TIMESTAMP column ON UPDATE CURRENT_TIMESTAMP,
            // which would move the expiry to the moment the link is used.
            // Every row sets it; a NULL never passes the `> now` check.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->integer('used_by_session_id')->unsigned()->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_links');
    }
}
