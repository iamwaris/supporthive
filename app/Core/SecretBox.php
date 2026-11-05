<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Encryption at rest for personal data the app must read back — employees'
 * and joiners' bank details. libsodium's secretbox (XSalsa20-Poly1305): a
 * fresh random 24-byte nonce per value, and the Poly1305 tag means a
 * tampered value, or one written under another key, fails instead of
 * decrypting to garbage.
 *
 * Stored form: base64(nonce . ciphertext). Base64 keeps the column plain
 * ASCII, so the value travels through PDO's string binding unchanged on both
 * MySQL 8 and MariaDB.
 *
 * Its own key (APP_ENCRYPTION_KEY), deliberately not APP_KEY: APP_KEY also
 * seeds the session fingerprint and App\Core\Crypto, and rotating it for
 * either reason must never make every stored bank detail unreadable. Losing
 * APP_ENCRYPTION_KEY loses the data it protects, so it is backed up apart
 * from the database (docs/DEPLOYMENT.md).
 */
final class SecretBox
{
    public const KEY_BYTES = 32;

    private function __construct(private readonly string $key)
    {
    }

    /** @throws RuntimeException when the key is not exactly 32 bytes */
    public static function withKey(string $key): self
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('Encryption key must be exactly 32 bytes.');
        }

        return new self($key);
    }

    /** The application's box, keyed by APP_ENCRYPTION_KEY. */
    public static function fromConfig(): self
    {
        $key = self::decodeKey((string) Config::get('app.encryption_key', ''));
        if ($key === null) {
            throw new RuntimeException(
                'APP_ENCRYPTION_KEY is missing or not base64 of 32 bytes. Run php scripts/genkey.php --encryption.'
            );
        }

        return new self($key);
    }

    /** The raw key, or null when the configured value is not base64 of exactly 32 bytes. Used by preflight. */
    public static function decodeKey(string $configured): ?string
    {
        $key = base64_decode(trim($configured), true);

        return $key !== false && strlen($key) === self::KEY_BYTES ? $key : null;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * The message never says why: "tampered" and "wrong key" are the same
     * failure to the caller, and neither may echo any part of the value.
     *
     * @throws RuntimeException when the value is malformed, altered or under another key
     */
    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        $minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $minimum) {
            throw new RuntimeException('Encrypted value is malformed.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        if ($plaintext === false) {
            throw new RuntimeException('Encrypted value could not be decrypted.');
        }

        return $plaintext;
    }
}
