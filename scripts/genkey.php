<?php

/**
 * Generates a key for .env.
 *
 *   php scripts/genkey.php               APP_KEY            (base64:… form)
 *   php scripts/genkey.php --encryption  APP_ENCRYPTION_KEY (plain base64 of 32 bytes)
 *
 * Paste the output into .env (and into the GitHub Actions secret of the same
 * name for production).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$key = base64_encode(random_bytes(32));

echo (in_array('--encryption', $argv, true) ? $key : 'base64:' . $key) . PHP_EOL;
