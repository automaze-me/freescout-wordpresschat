<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatAccessTable extends Migration
{
    public function up()
    {
        Schema::create('wordpresschat_access', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('session_id')->unsigned();
            $table->integer('conversation_id')->unsigned()->index();
            $table->unique(['session_id', 'conversation_id']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_access');
    }
}
