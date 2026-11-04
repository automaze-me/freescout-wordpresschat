<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVerifiedAtToWordpresschatAccessTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_access', function (Blueprint $table) {
            // Set when this browser redeemed a continue link for the
            // conversation: it then sees the full history (spec §6.4 rule 7).
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_access', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
}
