<?php

Route::group([
    'middleware' => ['web', 'auth'],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\WordPressChat\Http\Controllers',
], function () {
    Route::get('/wordpress-chat/sites', 'SitesController@index')->name('wordpresschat.sites.index');
    Route::get('/wordpress-chat/sites/create', 'SitesController@create')->name('wordpresschat.sites.create');
    Route::post('/wordpress-chat/sites', 'SitesController@store')->name('wordpresschat.sites.store');
    Route::get('/wordpress-chat/sites/{id}/edit', 'SitesController@edit')->name('wordpresschat.sites.edit')->where('id', '[0-9]+');
    Route::post('/wordpress-chat/sites/{id}', 'SitesController@update')->name('wordpresschat.sites.update')->where('id', '[0-9]+');
    Route::post('/wordpress-chat/sites/{id}/delete', 'SitesController@destroy')->name('wordpresschat.sites.destroy')->where('id', '[0-9]+');
    Route::post('/wordpress-chat/sites/{id}/secret', 'SitesController@regenerateSecret')->name('wordpresschat.sites.secret')->where('id', '[0-9]+');
});
