<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWordpresschatSitesTable extends Migration
{
    public function up()
    {
        Schema::create('wordpresschat_sites', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('url', 255);
            $table->string('origin', 255);
            $table->integer('mailbox_id')->unsigned()->index();
            $table->string('public_id', 32)->unique();
            $table->text('secret');
            $table->smallInteger('fallback_delay')->unsigned()->default(120);
            $table->tinyInteger('max_attachment_mb')->unsigned()->default(10);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('wordpresschat_sites');
    }
}
