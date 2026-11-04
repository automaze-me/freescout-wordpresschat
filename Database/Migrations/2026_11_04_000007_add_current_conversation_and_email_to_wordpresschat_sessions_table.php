<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCurrentConversationAndEmailToWordpresschatSessionsTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            // The conversation this browser is in: the one its last message
            // went into, or the one it redeemed a continue link for
            // (Visibility::latestConversation()). Null: the highest granted.
            $table->integer('current_conversation_id')->unsigned()->nullable();
            // The address the visitor's customer was linked by (typed, order
            // billing, account, or a redeemed conversation's). A later
            // conversation of this session gets it as its customer_email.
            $table->string('email', 191)->nullable();
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            $table->dropColumn(['current_conversation_id', 'email']);
        });
    }
}
