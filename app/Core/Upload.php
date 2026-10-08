<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Safe file upload handling.
 *
 * Rules enforced here, all of them non-negotiable:
 *  - the real MIME type is read from the file contents, never from the client;
 *  - the stored filename is generated, never derived from the user's name;
 *  - the extension is chosen from an allow-list keyed by the detected type;
 *  - files land in public/uploads, where .htaccess forbids PHP execution -
 *    or, via storePrivate(), under storage/documents, outside the web root
 *    entirely, for anything an authenticated controller must gate (D-4).
 */
final class Upload
{
    /** @var array<string,string> detected MIME => safe extension */
    private const EXTENSIONS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file A single $_FILES entry
     * @return array{path:string,filename:string,mime:string,size:int}
     */
    public static function store(array $file, string $subdirectory = ''): array
    {
        return self::write(PUBLIC_PATH . '/uploads', 'uploads', $file, $subdirectory);
    }

    /**
     * Same rules as store(), but written under storage/documents — outside
     * PUBLIC_PATH entirely, so nothing here is reachable by a guessed URL.
     * Anything an authenticated controller must gate belongs here: financial
     * attachments (decision D-4) call it directly; employee documents go
     * through storePrivatePdf(), which narrows it to PDF.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{path:string,filename:string,mime:string,size:int}
     */
    public static function storePrivate(array $file, string $subdirectory = ''): array
    {
        return self::write(STORAGE_PATH . '/documents', 'documents', $file, $subdirectory);
    }

    /**
     * storePrivate(), narrowed to PDF only and to its own size limit
     * (uploads.pdf_max_bytes) — employee documents are handbooks and
     * policies, larger than a receipt photo and never anything but a PDF.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{path:string,filename:string,mime:string,size:int}
     */
    public static function storePrivatePdf(array $file, string $subdirectory): array
    {
        return self::write(
            STORAGE_PATH . '/documents',
            'documents',
            $file,
            $subdirectory,
            ['application/pdf'],
            (int) Config::get('uploads.pdf_max_bytes', 10_485_760)
        );
    }

    /**
     * Does the file start with the PDF magic bytes? finfo already sniffs the
     * type; this is the stricter check that the header is at offset 0, which
     * is where a PDF reader looks and where a polyglot usually is not.
     */
    public static function hasPdfSignature(string $path): bool
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, 5);
        fclose($handle);

        return $head === '%PDF-';
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @param list<string>|null $onlyMime Narrows the configured allow-list for this call; never widens it.
     * @param int|null $maxBytes Overrides uploads.max_bytes for this call.
     * @return array{path:string,filename:string,mime:string,size:int}
     */
    private static function write(
        string $baseDir,
        string $publicPrefix,
        array $file,
        string $subdirectory,
        ?array $onlyMime = null,
        ?int $maxBytes = null
    ): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::errorMessage((int) $file['error']));
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            Logger::security('Upload rejected: not an uploaded file', ['ip' => Http::clientIp()]);
            throw new RuntimeException('Invalid upload.');
        }

        $maxBytes ??= (int) Config::get('uploads.max_bytes', 5_242_880);
        if ((int) $file['size'] > $maxBytes) {
            throw new RuntimeException('File is larger than the ' . round($maxBytes / 1048576, 1) . ' MB limit.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);

        /** @var list<string> $allowed */
        $allowed = Config::get('uploads.allowed_mime', []);
        if ($onlyMime !== null) {
            $allowed = array_values(array_intersect($allowed, $onlyMime));
        }
        if (!in_array($mime, $allowed, true) || !isset(self::EXTENSIONS[$mime])) {
            Logger::security('Upload rejected: disallowed type', ['mime' => $mime, 'ip' => Http::clientIp()]);
            throw new RuntimeException('That file type is not allowed.');
        }

        // Images must actually parse as images; a PHP payload with a .jpg header fails here.
        if (str_starts_with($mime, 'image/') && @getimagesize($file['tmp_name']) === false) {
            Logger::security('Upload rejected: corrupt image', ['ip' => Http::clientIp()]);
            throw new RuntimeException('That image could not be read.');
        }

        if ($mime === 'application/pdf' && !self::hasPdfSignature($file['tmp_name'])) {
            Logger::security('Upload rejected: PDF without a PDF header', ['ip' => Http::clientIp()]);
            throw new RuntimeException('That file is not a valid PDF.');
        }

        $subdirectory = trim(preg_replace('/[^a-z0-9\/_-]/i', '', $subdirectory) ?? '', '/');
        $directory = $baseDir . ($subdirectory !== '' ? '/' . $subdirectory : '');

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Upload directory is not writable.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
        $destination = $directory . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        chmod($destination, 0644);

        return [
            'path'     => $publicPrefix . ($subdirectory !== '' ? '/' . $subdirectory : '') . '/' . $filename,
            'filename' => $filename,
            'mime'     => $mime,
            'size'     => (int) $file['size'],
        ];
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted.',
            UPLOAD_ERR_NO_FILE                        => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server could not store the file.',
            default                                   => 'Upload failed.',
        };
    }
}
