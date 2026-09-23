<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'max_bytes'    => Env::int('UPLOAD_MAX_BYTES', 5242880),
    'allowed_mime' => array_filter(array_map(
        'trim',
        explode(',', (string) Env::get('UPLOAD_ALLOWED_MIME', 'image/jpeg,image/png,image/webp,application/pdf'))
    )),
];
