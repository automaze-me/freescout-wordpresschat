<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPresenceToWordpresschatSessionsTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            // The last poll of this browser and the path of the page it
            // came from (no query, no fragment): the agent's presence line.
            $table->timestamp('last_seen_at')->nullable();
            $table->string('page_path', 255)->nullable();
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'page_path']);
        });
    }
}
