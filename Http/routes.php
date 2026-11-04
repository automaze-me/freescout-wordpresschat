<?php

// Public visitor API: stateless, cross-origin. Deliberately NOT in the 'web'
// group (no session, no CSRF). Registered before the admin group.
Route::group([
    'middleware' => ['bindings', \Modules\WordPressChat\Http\Middleware\ResolveSite::class, \Modules\WordPressChat\Http\Middleware\Cors::class],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\WordPressChat\Http\Controllers\Api',
], function () {
    $site = '[a-f0-9]{32}';
    Route::post('/wordpress-chat/api/v1/sites/{site}/messages', 'ChatController@send')->name('wordpresschat.api.send')->where('site', $site);
    Route::get('/wordpress-chat/api/v1/sites/{site}/messages', 'ChatController@index')->name('wordpresschat.api.index')->where('site', $site);
    Route::post('/wordpress-chat/api/v1/sites/{site}/seen', 'ChatController@seen')->name('wordpresschat.api.seen')->where('site', $site);
    Route::post('/wordpress-chat/api/v1/sites/{site}/identity', 'IdentityController@identify')->name('wordpresschat.api.identity')->where('site', $site);
    Route::post('/wordpress-chat/api/v1/sites/{site}/logout', 'IdentityController@logout')->name('wordpresschat.api.logout')->where('site', $site);
    // Preflight: Cors short-circuits OPTIONS with 204 before the closure runs.
    Route::options('/wordpress-chat/api/v1/sites/{site}/{any}', function () {
        return response('', 204);
    })->where(['site' => $site, 'any' => '.*']);
});

// {id} allows at most 10 digits; SitesController::findSite() 404s the
// 10-digit values above the 4-byte integer range (a 500 on PostgreSQL).
Route::group([
    'middleware' => ['web', 'auth'],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\WordPressChat\Http\Controllers',
], function () {
    Route::get('/wordpress-chat/sites', 'SitesController@index')->name('wordpresschat.sites.index');
    Route::get('/wordpress-chat/sites/create', 'SitesController@create')->name('wordpresschat.sites.create');
    Route::post('/wordpress-chat/sites', 'SitesController@store')->name('wordpresschat.sites.store');
    Route::get('/wordpress-chat/sites/{id}/edit', 'SitesController@edit')->name('wordpresschat.sites.edit')->where('id', '[0-9]{1,10}');
    Route::post('/wordpress-chat/sites/{id}', 'SitesController@update')->name('wordpresschat.sites.update')->where('id', '[0-9]{1,10}');
    Route::post('/wordpress-chat/sites/{id}/delete', 'SitesController@destroy')->name('wordpresschat.sites.destroy')->where('id', '[0-9]{1,10}');
    Route::post('/wordpress-chat/sites/{id}/secret', 'SitesController@regenerateSecret')->name('wordpresschat.sites.secret')->where('id', '[0-9]{1,10}');
});
