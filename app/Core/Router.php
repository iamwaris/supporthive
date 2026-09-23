<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small explicit router. Routes are declared in config/routes.php; there is no
 * dynamic controller resolution from the URL, so a request can never reach a
 * class or method that was not deliberately registered.
 */
final class Router
{
    /** @var array<string, array<string, array{handler:callable|string, middleware:list<string>}>> */
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []];

    /** @param callable|string $handler "Controller@method" or a closure */
    public function get(string $path, callable|string $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|string $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|string $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|string $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    private function add(string $method, string $path, callable|string $handler, array $middleware): void
    {
        $this->routes[$method]['/' . trim($path, '/')] = ['handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(): void
    {
        // HEAD must resolve to the GET route (PHP discards the body itself).
        // Without this, monitors and the deploy smoke test see a false 404.
        $method = Http::method() === 'HEAD' ? 'GET' : Http::method();
        $path = Http::path();

        // CSRF is enforced for every unsafe method before any handler runs.
        Csrf::check();

        $route = $this->match($method, $path);
        if ($route === null) {
            Http::abort(404);
        }

        foreach ($route['middleware'] as $middleware) {
            $this->applyMiddleware($middleware);
        }

        $this->invoke($route['handler'], $route['params']);
    }

    /**
     * @return array{handler:callable|string, middleware:list<string>, params:array<string,string>}|null
     */
    private function match(string $method, string $path): ?array
    {
        $table = $this->routes[$method] ?? [];

        if (isset($table[$path])) {
            return $table[$path] + ['params' => []];
        }

        // Named placeholders: /tickets/{id}. Values are captured as constrained strings.
        foreach ($table as $pattern => $route) {
            if (!str_contains($pattern, '{')) {
                continue;
            }
            $regex = '#^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[A-Za-z0-9_-]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $matches) === 1) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $route + ['params' => $params];
            }
        }

        return null;
    }

    private function applyMiddleware(string $middleware): void
    {
        [$name, $arg] = array_pad(explode(':', $middleware, 2), 2, null);

        match ($name) {
            'auth'  => Auth::requireLogin(),
            'guest' => Auth::check() ? Http::redirect('/dashboard') : null,
            'role'  => Auth::requireRole(...explode(',', (string) $arg)),
            default => null,
        };
    }

    /** @param array<string,string> $params */
    private function invoke(callable|string $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler($params);
            return;
        }

        [$controller, $method] = array_pad(explode('@', $handler, 2), 2, 'index');
        $class = 'App\\Controllers\\' . $controller;

        if (!class_exists($class) || !method_exists($class, (string) $method)) {
            Logger::error('Route handler missing', ['handler' => $handler]);
            Http::abort(500);
        }

        (new $class())->{$method}($params);
    }
}
