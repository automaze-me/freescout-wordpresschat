<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSiteIdIndexToWordpresschatLinksTable extends Migration
{
    public function up()
    {
        Schema::table('wordpresschat_links', function (Blueprint $table) {
            // Deleting a website purges its links by site_id.
            $table->index('site_id', 'wordpresschat_links_site_id_index');
        });
    }

    public function down()
    {
        Schema::table('wordpresschat_links', function (Blueprint $table) {
            $table->dropIndex('wordpresschat_links_site_id_index');
        });
    }
}
