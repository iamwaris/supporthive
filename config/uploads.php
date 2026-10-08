<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'max_bytes'    => Env::int('UPLOAD_MAX_BYTES', 5242880),
    // Employee documents only (Upload::storePrivatePdf()). php.ini's
    // upload_max_filesize and post_max_size must be at least this —
    // scripts/preflight.php checks — or a large PDF is cut off before PHP
    // sees it and the request fails as a CSRF 419 instead of a field error.
    'pdf_max_bytes' => Env::int('UPLOAD_PDF_MAX_BYTES', 10485760),
    'allowed_mime' => array_filter(array_map(
        'trim',
        explode(',', (string) Env::get('UPLOAD_ALLOWED_MIME', 'image/jpeg,image/png,image/webp,application/pdf'))
    )),
];
