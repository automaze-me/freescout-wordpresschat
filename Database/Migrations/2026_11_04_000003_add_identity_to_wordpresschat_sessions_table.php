<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdentityToWordpresschatSessionsTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            // Session::IDENTITY_*: what the visitor typed (email, order number).
            $table->smallInteger('identity')->unsigned()->default(0);
            // The WordPress account of the last login token, valid until
            // account_until (Session::accountFresh()).
            $table->integer('wp_user_id')->unsigned()->nullable()->index();
            $table->timestamp('account_until')->nullable();
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            $table->dropIndex(['wp_user_id']);
        });

        Schema::table('wordpresschat_sessions', function (Blueprint $table) {
            $table->dropColumn(['identity', 'wp_user_id', 'account_until']);
        });
    }
}
