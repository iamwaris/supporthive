<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Router;

/**
 * Sends one request through the real route table and Router::dispatch() —
 * method matching, CSRF and middleware included — for tests that need to
 * prove what the router itself allows. Run it through ControllerActionRunner
 * (method 'dispatch', route params ['uri' => '/path']) so the exit at the end
 * of every response happens in a separate process.
 */
final class RouteDispatch
{
    /** @param array<string,string> $params */
    public function dispatch(array $params): void
    {
        $_SERVER['REQUEST_URI'] = $params['uri'] ?? '/';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/web.php';
        $router->dispatch();
    }
}
