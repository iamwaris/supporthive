<?php

/**
 * Generates an APP_KEY. Run: php scripts/genkey.php
 * Paste the output into .env (and into the GitHub Actions secret for production).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
