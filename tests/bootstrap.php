<?php

/**
 * Test bootstrap.
 *
 * Switches the connection to a separate schema BEFORE the app boots, so the
 * suite never touches development data and development data never influences
 * a result. See config/database.php for how the flag is read.
 *
 * Create it once:
 *   mysql -u root -p -e "CREATE DATABASE supporthive_test
 *     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   LEDGERHIVE_TESTING=1 php database/migrate.php
 */

declare(strict_types=1);

putenv('LEDGERHIVE_TESTING=1');

require dirname(__DIR__) . '/app/bootstrap.php';
