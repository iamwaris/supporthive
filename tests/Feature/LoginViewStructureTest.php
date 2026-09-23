<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Structural checks on the sign-in view.
 *
 * These exist because of a bug that every request-level test passed straight
 * through: the quick-login forms were nested inside the sign-in form. Nested
 * <form> is invalid HTML, so the browser discarded the inner tags, every
 * hidden user_id ended up owned by the outer form, and the last one won no
 * matter which button was clicked — you always signed in as the same account.
 *
 * Posting directly to the endpoint with curl worked perfectly, because that
 * skips the browser's parser entirely. Only the rendered markup shows it, so
 * the markup is what gets asserted.
 */
final class LoginViewStructureTest extends TestCase
{
    private mixed $originalSwitch = null;
    private ?int $userId = null;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->originalSwitch = Config::get('security.dev_quick_login');
        $this->setSwitch(true);

        // At least two accounts, so the multi-form case is actually rendered.
        $db = Database::instance();
        $db->delete('users', 'email LIKE :e', ['e' => 'structure-%@test.local']);

        $this->userId = $db->insert('users', [
            'name' => 'Structure Admin',
            'email' => 'structure-admin@test.local',
            'password_hash' => password_hash('irrelevant-for-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $db->insert('users', [
            'name' => 'Structure Partner',
            'email' => 'structure-partner@test.local',
            'password_hash' => password_hash('irrelevant-for-this-test', PASSWORD_DEFAULT),
            'role' => 'partner',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Database::instance()->delete('users', 'email LIKE :e', ['e' => 'structure-%@test.local']);
        $this->setSwitch($this->originalSwitch);
        $_SESSION = [];
    }

    private function setSwitch(mixed $value): void
    {
        $reflection = new ReflectionClass(Config::class);
        $items = $reflection->getStaticPropertyValue('items');
        $items['security']['dev_quick_login'] = $value;
        $reflection->setStaticPropertyValue('items', $items);
    }

    private function render(): string
    {
        return View::capture('auth/login', ['errors' => []], null);
    }

    /**
     * @return array{maxDepth:int,owners:list<array{0:string,1:string}>}
     */
    private function analyse(string $html): array
    {
        // Walk the form open/close tags and every named input in document
        // order, which is exactly how a browser assigns form ownership.
        preg_match_all(
            '#<form[^>]*action="([^"]*)"[^>]*>|</form>|<input[^>]*name="(user_id)"[^>]*value="([^"]*)"#i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $depth = 0;
        $maxDepth = 0;
        $stack = [];
        $owners = [];

        foreach ($matches as $match) {
            $tag = $match[0];

            if (stripos($tag, '</form') === 0) {
                array_pop($stack);
                $depth--;
                continue;
            }

            if (stripos($tag, '<form') === 0) {
                $stack[] = basename((string) $match[1]);
                $depth++;
                $maxDepth = max($maxDepth, $depth);
                continue;
            }

            $owners[] = [$match[3] ?? '', $stack === [] ? '(none)' : (string) end($stack)];
        }

        return ['maxDepth' => $maxDepth, 'owners' => $owners];
    }

    public function testTheSignInViewContainsNoNestedForms(): void
    {
        $analysis = $this->analyse($this->render());

        self::assertSame(
            1,
            $analysis['maxDepth'],
            'a <form> inside a <form> is discarded by the browser and silently breaks every inner button'
        );
    }

    public function testEachQuickLoginButtonOwnsExactlyOneAccount(): void
    {
        $analysis = $this->analyse($this->render());

        self::assertNotSame([], $analysis['owners'], 'the quick-login accounts should be rendered');

        $seen = [];
        foreach ($analysis['owners'] as [$userId, $owner]) {
            self::assertSame(
                'dev-login',
                $owner,
                "user_id={$userId} must belong to the quick-login form, not the sign-in form"
            );
            self::assertNotContains($userId, $seen, 'each account must appear once');
            $seen[] = $userId;
        }

        self::assertGreaterThanOrEqual(2, count($seen), 'both test accounts should offer a button');
    }

    public function testNoAccountsRenderWhileTheSwitchIsOff(): void
    {
        $this->setSwitch(false);

        $html = $this->render();

        self::assertStringNotContainsString('dev-login', $html);
        self::assertStringNotContainsString('name="user_id"', $html);
    }
}
