<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\SecretBox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SecretBox keeps employees' bank details unreadable to anyone holding a copy
 * of the database. A tampered value and a value under another key must both
 * fail loudly — never decrypt to something the app would then show.
 */
final class SecretBoxTest extends TestCase
{
    private const IBAN = 'PK36SCBL0000001123456702';

    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('The sodium extension is not loaded; enable extension=sodium in php.ini.');
        }
    }

    private static function box(): SecretBox
    {
        return SecretBox::withKey(random_bytes(SecretBox::KEY_BYTES));
    }

    public function testRoundTripReturnsTheOriginalValue(): void
    {
        $box = self::box();

        self::assertSame(self::IBAN, $box->decrypt($box->encrypt(self::IBAN)));
        self::assertSame('Bank Alfalah — Gulberg', $box->decrypt($box->encrypt('Bank Alfalah — Gulberg')));
    }

    public function testStoredFormIsBase64AndNeverContainsThePlaintext(): void
    {
        $stored = self::box()->encrypt(self::IBAN);

        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]+=*$#', $stored);
        self::assertStringNotContainsString(self::IBAN, $stored);
        self::assertStringNotContainsString(self::IBAN, (string) base64_decode($stored, true));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $box = self::box();

        self::assertNotSame($box->encrypt(self::IBAN), $box->encrypt(self::IBAN));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $box = self::box();
        $raw = (string) base64_decode($box->encrypt(self::IBAN), true);

        // Past the 24-byte nonce, so this alters the authenticated ciphertext itself.
        $offset = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 3;
        $raw[$offset] = chr(ord($raw[$offset]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $box->decrypt(base64_encode($raw));
    }

    public function testTamperedNonceIsRejected(): void
    {
        $box = self::box();
        $raw = (string) base64_decode($box->encrypt(self::IBAN), true);
        $raw[0] = chr(ord($raw[0]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $box->decrypt(base64_encode($raw));
    }

    public function testAValueUnderAnotherKeyIsRejected(): void
    {
        $stored = self::box()->encrypt(self::IBAN);

        $this->expectException(RuntimeException::class);
        self::box()->decrypt($stored);
    }

    public function testTheFailureMessageNeverEchoesTheValue(): void
    {
        $stored = self::box()->encrypt(self::IBAN);

        try {
            self::box()->decrypt($stored);
            self::fail('Decrypting under another key must throw.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString(self::IBAN, $e->getMessage());
            self::assertStringNotContainsString($stored, $e->getMessage());
        }
    }

    public function testMalformedInputIsRejected(): void
    {
        $box = self::box();
        foreach (['', 'not base64 at all !!', base64_encode('too short')] as $input) {
            try {
                $box->decrypt($input);
                self::fail('Malformed input must throw: ' . $input);
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAKeyOfTheWrongLengthIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        SecretBox::withKey(random_bytes(16));
    }

    public function testDecodeKeyAcceptsOnlyBase64OfExactly32Bytes(): void
    {
        self::assertNotNull(SecretBox::decodeKey(base64_encode(random_bytes(32))));
        self::assertNull(SecretBox::decodeKey(''));
        self::assertNull(SecretBox::decodeKey(base64_encode(random_bytes(31))));
        self::assertNull(SecretBox::decodeKey(base64_encode(random_bytes(33))));
        self::assertNull(SecretBox::decodeKey('base64:' . base64_encode(random_bytes(32))));
        self::assertNull(SecretBox::decodeKey('not-a-key'));
    }
}
