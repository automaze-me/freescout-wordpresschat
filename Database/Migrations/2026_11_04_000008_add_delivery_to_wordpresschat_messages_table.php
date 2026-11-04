<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDeliveryToWordpresschatMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_messages', function (Blueprint $table) {
            // When an unseen chat reply may be emailed. Null: never (rows
            // from before this plan, visitor rows, email-choice rows).
            $table->timestamp('fallback_due_at')->nullable()->index();
            // Message::EMAIL_*: state of the fallback or chosen email.
            $table->tinyInteger('email_status')->unsigned()->nullable();
            // Batch key: the thread id the email job was dispatched with.
            $table->integer('email_thread_id')->unsigned()->nullable()->index();
            // The reply never reached the chat.
            $table->timestamp('lost_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_messages', function (Blueprint $table) {
            $table->dropIndex(['fallback_due_at']);
            $table->dropIndex(['email_thread_id']);
            $table->dropColumn(['fallback_due_at', 'email_status', 'email_thread_id', 'lost_at']);
        });
    }
}
