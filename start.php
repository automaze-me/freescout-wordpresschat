<?php

// Guarded: Laravel re-bootstraps a fresh application (and re-includes every
// module's start.php) per test method within the same PHP process, but PHP
// constants are process-global and never get undefined between tests. An
// unguarded define() would emit "already defined" on the second and later
// test methods, which FreeScout's modules.register_error handler swallows in
// a CLI/test context, aborting the rest of this file -- silently skipping
// the routes require below for every test after the first.
if (!defined('WORDPRESS_CHAT_MODULE')) {
    define('WORDPRESS_CHAT_MODULE', 'wordpresschat');
}

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
