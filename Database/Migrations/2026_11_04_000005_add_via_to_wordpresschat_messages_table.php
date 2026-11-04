<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddViaToWordpresschatMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_messages', function (Blueprint $table) {
            // Message::VIA_*: the channel the thread travelled by. Email rows
            // are shown to verified browsers only (spec §6.4 rules 4 and 7).
            $table->smallInteger('via')->unsigned()->default(1);
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_messages', function (Blueprint $table) {
            $table->dropColumn('via');
        });
    }
}
