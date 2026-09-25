<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Crypto is what keeps a stored Anthropic API key from being readable by
 * anyone who gets a copy of the database, so the tamper case matters as much
 * as the round trip: a modified ciphertext must fail loudly, never decrypt to
 * something the app would then send upstream.
 */
final class CryptoTest extends TestCase
{
    /** Shaped like an Anthropic key, but deliberately not a usable one. */
    private const FAKE_KEY = 'sk-ant-api03-0000000000000000000000000000000000-not-a-real-key';

    protected function setUp(): void
    {
        if (!str_starts_with((string) Config::get('app.key', ''), 'base64:')) {
            self::markTestSkipped('APP_KEY is not configured; run php scripts/genkey.php and set it in .env.');
        }
    }

    public function testRoundTripReturnsTheOriginalValue(): void
    {
        self::assertSame(self::FAKE_KEY, Crypto::decrypt(Crypto::encrypt(self::FAKE_KEY)));
    }

    public function testEachEncryptionUsesAFreshIv(): void
    {
        self::assertNotSame(Crypto::encrypt(self::FAKE_KEY), Crypto::encrypt(self::FAKE_KEY));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $raw = (string) base64_decode(Crypto::encrypt(self::FAKE_KEY), true);

        // Past the 12-byte IV and 16-byte tag, so this alters the ciphertext itself.
        $offset = 28 + (int) floor((strlen($raw) - 28) / 2);
        $raw[$offset] = chr(ord($raw[$offset]) ^ 0xFF);

        $this->expectException(RuntimeException::class);
        Crypto::decrypt(base64_encode($raw));
    }

    public function testEmptyInputIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Crypto::decrypt('');
    }

    public function testShortGarbageInputIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Crypto::decrypt('not base64 at all !!');
    }
}
