<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatSessionsTable extends Migration
{
    public function up()
    {
        Schema::create('wordpresschat_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('site_id')->unsigned()->index();
            $table->string('public_id', 32)->unique();
            $table->string('token_hash', 64)->unique();
            $table->integer('customer_id')->unsigned()->nullable()->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_sessions');
    }
}
