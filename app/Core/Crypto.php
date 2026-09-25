<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Reversible encryption for secrets the app must later read back — currently
 * the per-branch Anthropic API key, which has to be decrypted to be sent as an
 * HTTP header. This is NOT for passwords: those stay one-way (password_hash).
 *
 * AES-256-GCM, so a tampered ciphertext fails the tag check instead of
 * decrypting to garbage. The wire format is base64(iv|tag|ciphertext) with a
 * 12-byte IV and the 16-byte GCM tag, keyed by APP_KEY.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_BYTES = 16;
    private const KEY_BYTES = 32;

    public static function encrypt(string $plaintext): string
    {
        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new RuntimeException('Encrypted value is not valid base64.');
        }

        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
        if (strlen($raw) <= $ivLength + self::TAG_BYTES) {
            throw new RuntimeException('Encrypted value is malformed.');
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, self::TAG_BYTES);
        $ciphertext = substr($raw, $ivLength + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // False here means the GCM tag did not verify: the value was altered,
        // or it was encrypted under a different APP_KEY.
        if ($plaintext === false) {
            throw new RuntimeException('Encrypted value could not be decrypted.');
        }

        return $plaintext;
    }

    private static function key(): string
    {
        $configured = (string) Config::get('app.key', '');
        if (!str_starts_with($configured, 'base64:')) {
            throw new RuntimeException('APP_KEY is missing or not in base64: form. Run php scripts/genkey.php.');
        }

        $key = base64_decode(substr($configured, 7), true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('APP_KEY must decode to exactly 32 bytes. Run php scripts/genkey.php.');
        }

        return $key;
    }
}
