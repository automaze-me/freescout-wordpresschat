<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\WordPressChat\Http\Middleware\OversizedUpload;
use Modules\WordPressChat\Tests\TestCase;

/**
 * A smoke test for a FreeScout installed in a subdirectory: the module's
 * routes carry the APP_URL path as a prefix, and the oversized-upload
 * middleware, which runs before routing, matches the same prefixed path.
 */
class SubdirectoryTest extends TestCase
{
    /** The router the application booted with, put back in tearDown(). */
    private $originalRouter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRouter = \Route::getFacadeRoot();
    }

    protected function tearDown(): void
    {
        // Even when the test failed half way: later tests need the real router.
        if ($this->originalRouter !== null) {
            \Route::swap($this->originalRouter);
        }

        parent::tearDown();
    }

    public function test_routes_live_under_the_subdirectory()
    {
        config(['app.url' => 'https://x.test/support']);

        $router = new Router(app('events'), app());
        \Route::swap($router);
        require __DIR__.'/../../Http/routes.php';

        $uri = null;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === 'wordpresschat.api.send') {
                $uri = $route->uri();
            }
        }

        $this->assertSame('support/wordpress-chat/api/v1/sites/{site}/messages', $uri);

        $publicId = str_repeat('a', 32);
        $match = new \ReflectionMethod(OversizedUpload::class, 'publicId');
        $match->setAccessible(true);

        $this->assertSame(
            $publicId,
            $match->invoke(null, Request::create('/support/wordpress-chat/api/v1/sites/'.$publicId.'/messages', 'POST'))
        );
        // Without the prefix it is not the endpoint of this installation.
        $this->assertNull(
            $match->invoke(null, Request::create('/wordpress-chat/api/v1/sites/'.$publicId.'/messages', 'POST'))
        );
    }
}
