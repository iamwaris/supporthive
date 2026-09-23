<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DevAuthController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The quick-login shortcut is an authentication bypass. What matters is not
 * that it works, but that it is OFF unless deliberately switched on — so the
 * gate itself is what gets tested.
 */
final class DevQuickLoginTest extends TestCase
{
    /** @var mixed */
    private mixed $original = null;

    protected function setUp(): void
    {
        $this->original = \App\Core\Config::get('security.dev_quick_login');
    }

    protected function tearDown(): void
    {
        $this->setSwitch($this->original);
    }

    /** Config has no setter, so the store is written directly for the test. */
    private function setSwitch(mixed $value): void
    {
        $reflection = new ReflectionClass(\App\Core\Config::class);
        $items = $reflection->getStaticPropertyValue('items');
        $items['security']['dev_quick_login'] = $value;
        $reflection->setStaticPropertyValue('items', $items);
    }

    public function testDisabledByDefaultInTheShippedExample(): void
    {
        // The committed .env.example must not ship this switched on, or a new
        // environment starts life with a password-free admin login.
        $example = (string) file_get_contents(BASE_PATH . '/.env.example');
        self::assertStringContainsString('DEV_QUICK_LOGIN=false', $example);
    }

    public function testOffMeansNoAccountsAreOffered(): void
    {
        $this->setSwitch(false);

        self::assertFalse(DevAuthController::isEnabled());
        self::assertSame([], DevAuthController::testAccounts(), 'no buttons may render while off');
    }

    public function testOnlyStrictTrueEnablesIt(): void
    {
        // A stray "false", "0" or null must not read as on. The check is
        // identity against true precisely so a loose value cannot enable an
        // authentication bypass.
        foreach ([null, 0, '', '0', 'false', 'no', [], 1, 'true'] as $value) {
            $this->setSwitch($value);
            self::assertFalse(
                DevAuthController::isEnabled(),
                'a non-boolean-true value must not enable quick login'
            );
        }

        $this->setSwitch(true);
        self::assertTrue(DevAuthController::isEnabled());
    }
}
