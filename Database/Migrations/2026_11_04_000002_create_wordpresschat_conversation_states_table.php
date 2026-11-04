<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatConversationStatesTable extends Migration
{
    public function up()
    {
        // One row per chat conversation: its public key for the widget, the
        // WordPress account it is bound to, and the conversation-wide
        // cut-off for unverified browsers (Ruling P2).
        Schema::create('wordpresschat_conversation_states', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('conversation_id')->unsigned()->unique();
            $table->integer('site_id')->unsigned()->index();
            $table->string('public_id', 32)->unique();
            $table->integer('wp_user_id')->unsigned()->nullable();
            $table->integer('cutoff_message_id')->unsigned()->nullable();
            $table->smallInteger('cutoff_reason')->unsigned()->nullable();
            $table->timestamps();
            $table->index(['site_id', 'wp_user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_conversation_states');
    }
}
