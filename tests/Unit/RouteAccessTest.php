<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use App\Services\Access;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The route table as a whole, evaluated against the access policy.
 *
 * AccessTest proves each ability answers correctly; this proves the routes
 * actually ask. It loads routes/web.php into a real Router and asserts two
 * things: no route is accidentally ungated, and the exact set of routes an
 * employee (and a partner) can reach. Adding a route that either role can
 * reach changes a snapshot below — on purpose, in the same commit, never by
 * accident.
 */
final class RouteAccessTest extends TestCase
{
    /** Reachable signed out by design. */
    private const PUBLIC_PATHS = ['/', '/login', '/dev-login'];

    private const EMPLOYEE_REACHABLE = [
        'GET /account/password',
        'POST /account/password',
        'POST /logout',
        'GET /portal',
        'GET /portal/documents',
        'GET /employee-documents/{id}/view',
        'GET /employee-documents/{id}/download',
    ];

    private const PARTNER_REACHABLE = [
        'GET /account/password',
        'GET /dashboard',
        'GET /dashboard/charts/trend',
        'GET /dashboard/charts/category',
        'GET /dashboard/charts/budget',
        'GET /partners',
        'GET /categories',
        'GET /accounts',
        'GET /customers',
        'GET /transactions',
        'GET /documents/{id}',
        'GET /transfers',
        'GET /expenses',
        'GET /expenses/new',
        'GET /expenses/vendors',
        'GET /income',
        'GET /income/new',
        'GET /capital',
        'GET /budgets',
        'GET /recurring-rules',
        'GET /recurring-occurrences',
        'GET /profit-distributions',
        'GET /reports',
        'GET /reports/profit-loss',
        'GET /reports/income',
        'GET /reports/expenses',
        'GET /reports/expense-by-category',
        'GET /reports/budget-vs-actual',
        'GET /reports/cash-flow',
        'GET /reports/account-balances',
        'GET /reports/partner-statement',
        'GET /reports/partner-contributions',
        'GET /reports/partner-withdrawals',
        'GET /reports/profit-distribution',
        'GET /reports/daily-transactions',
        'POST /logout',
        'POST /account/password',
        'POST /transactions/{id}/void',
        'POST /transactions/{id}/documents',
        'POST /transfers',
        'POST /expenses',
        'POST /expenses/scan-receipt',
        'POST /income',
        'POST /income/{id}/received',
        'POST /capital',
        'POST /recurring-occurrences/{id}/approve',
        'POST /recurring-occurrences/{id}/reject',
        'POST /recurring-occurrences/approve-bulk',
        'POST /ai/chat',
    ];

    public function testEveryNonPublicRouteIsGated(): void
    {
        foreach ($this->routes() as $key => $middleware) {
            [, $path] = explode(' ', $key, 2);
            if (in_array($path, self::PUBLIC_PATHS, true)) {
                continue;
            }

            $gated = array_filter(
                $middleware,
                static fn (string $m): bool => $m === 'auth'
                    || str_starts_with($m, 'can:')
                    || str_starts_with($m, 'role:')
            );
            self::assertNotSame([], $gated, "{$key} has no auth/can:/role: middleware");
        }
    }

    public function testEmployeeReachesExactlyThePortalAndItsOwnAccountRoutes(): void
    {
        self::assertSame($this->sorted(self::EMPLOYEE_REACHABLE), $this->reachableBy(Access::EMPLOYEE));
    }

    public function testPartnerReachIsUnchangedByTheEmployeeRoutes(): void
    {
        self::assertSame($this->sorted(self::PARTNER_REACHABLE), $this->reachableBy(Access::PARTNER));
    }

    public function testEmployeeRoutesAreAdminGatedApartFromReadingDocuments(): void
    {
        foreach ($this->routes() as $key => $middleware) {
            if (!preg_match('#^(GET|POST) /employees#', $key)) {
                continue;
            }
            self::assertSame(['can:administer'], $middleware, "{$key} must be admin only");
        }
    }

    public function testSalaryChangeIsAdminOnlyAndUnreachableForEmployeesAndPartners(): void
    {
        $routes = $this->routes();

        self::assertSame(['can:administer'], $routes['POST /employees/{id}/salary'] ?? null);
        self::assertNotContains('POST /employees/{id}/salary', $this->reachableBy(Access::EMPLOYEE));
        self::assertNotContains('POST /employees/{id}/salary', $this->reachableBy(Access::PARTNER));
        self::assertContains('POST /employees/{id}/salary', $this->reachableBy(Access::ADMIN));
    }

    /** @return list<string> "METHOD /path" for every gated route this role passes every middleware of */
    private function reachableBy(string $role): array
    {
        $reachable = [];
        foreach ($this->routes() as $key => $middleware) {
            [, $path] = explode(' ', $key, 2);
            if (in_array($path, self::PUBLIC_PATHS, true)) {
                continue;
            }

            $passes = true;
            foreach ($middleware as $gate) {
                [$name, $arg] = array_pad(explode(':', $gate, 2), 2, '');
                $passes = $passes && match ($name) {
                    'auth' => true,
                    'can' => Access::allows($arg, $role),
                    'role' => in_array($role, explode(',', $arg), true),
                    default => false,
                };
            }

            if ($passes) {
                $reachable[] = $key;
            }
        }

        return $this->sorted($reachable);
    }

    /** @return array<string,list<string>> "METHOD /path" => middleware */
    private function routes(): array
    {
        $router = new Router();
        (static function (Router $router): void {
            require dirname(__DIR__, 2) . '/routes/web.php';
        })($router);

        /** @var array<string,array<string,array{handler:mixed,middleware:list<string>}>> $table */
        $table = (new ReflectionProperty(Router::class, 'routes'))->getValue($router);

        $routes = [];
        foreach ($table as $method => $byPath) {
            foreach ($byPath as $path => $route) {
                $routes[$method . ' ' . $path] = $route['middleware'];
            }
        }

        return $routes;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
