<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatMessagesTable extends Migration
{
    public function up()
    {
        Schema::create('wordpresschat_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('conversation_id')->unsigned()->index();
            $table->integer('thread_id')->unsigned()->unique();
            $table->integer('session_id')->unsigned()->nullable();
            $table->tinyInteger('direction')->unsigned();
            $table->string('client_message_id', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->unique(['session_id', 'client_message_id']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_messages');
    }
}
