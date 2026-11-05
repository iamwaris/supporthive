<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\SecretBox;
use App\Domain\PakistanIban;
use Throwable;

/**
 * Bank name, account number and IBAN — for an employee (employee_profiles)
 * or a joiner who has not been approved yet (joiner_submissions). Both
 * tables store them under the same *_enc column names, always encrypted
 * with App\Core\SecretBox, so this is the one place they are validated,
 * encrypted, decrypted and masked.
 *
 * Plain values never reach a log or an audit row: a decryption failure logs
 * which field of which record failed, and audit entries carry masked values
 * only (mask()).
 */
final class BankDetails
{
    /** Request field => its encrypted column. */
    public const COLUMNS = [
        'bank_name' => 'bank_name_enc',
        'bank_account_number' => 'bank_account_number_enc',
        'bank_iban' => 'bank_iban_enc',
    ];

    /**
     * Never put back into the session as flashed input after a failed
     * submit: a session file is stored in plain text, which would undo the
     * encryption. The form asks for them again instead.
     */
    public const NOT_FLASHED = ['bank_account_number', 'bank_iban'];

    /** Applied to normaliseInput() output. Each field is optional on its own. */
    public const RULES = [
        'bank_name' => 'nullable|max:100',
        'bank_account_number' => 'nullable|regex:/^\d{6,24}$/',
        'bank_iban' => 'nullable|iban_pk',
    ];

    /**
     * The three fields, normalised the way people type them: spaces and
     * hyphens out of the account number and IBAN, the IBAN upper-cased, the
     * bank name's whitespace collapsed. A non-string (name="bank_iban[]")
     * is passed through untouched so the Validator rejects it.
     *
     * @param array<string,mixed> $source usually $_POST
     * @return array<string,mixed>
     */
    public static function normaliseInput(array $source): array
    {
        $normalised = [];
        foreach (array_keys(self::COLUMNS) as $field) {
            $value = $source[$field] ?? null;
            if (!is_string($value)) {
                $normalised[$field] = $value;
                continue;
            }

            $normalised[$field] = match ($field) {
                'bank_name' => trim((string) preg_replace('/\s+/u', ' ', $value)),
                'bank_account_number' => (string) preg_replace('/[\s-]+/u', '', $value),
                default => PakistanIban::normalise($value),
            };
        }

        return $normalised;
    }

    /**
     * @param array<string,mixed> $clean Validator::validated() output covering RULES
     * @return array<string,?string> encrypted column => ciphertext, or null for an empty field
     */
    public static function encrypt(array $clean): array
    {
        // Built only for a value that needs it, so a form with no bank
        // details never depends on the key (or the extension) at all.
        $box = null;
        $columns = [];
        foreach (self::COLUMNS as $field => $column) {
            $value = $clean[$field] ?? null;
            if ($value === null || $value === '') {
                $columns[$column] = null;
                continue;
            }

            $box ??= SecretBox::fromConfig();
            $columns[$column] = $box->encrypt((string) $value);
        }

        return $columns;
    }

    /**
     * Decrypts whichever of the three columns $row holds. A value that cannot
     * be decrypted (tampered, another key, no key configured) comes back
     * null and its field is listed in `unavailable`; the failure is logged
     * without the value, and never becomes an error page.
     *
     * @param array<string,mixed> $row
     * @return array{bank_name:?string,bank_account_number:?string,bank_iban:?string,unavailable:list<string>}
     */
    public static function decrypt(array $row, string $entityType, int $entityId): array
    {
        $details = ['bank_name' => null, 'bank_account_number' => null, 'bank_iban' => null, 'unavailable' => []];
        $box = null;

        foreach (self::COLUMNS as $field => $column) {
            $stored = $row[$column] ?? null;
            if (!is_string($stored) || $stored === '') {
                continue;
            }

            try {
                $box ??= SecretBox::fromConfig();
                $details[$field] = $box->decrypt($stored);
            } catch (Throwable $e) {
                $details['unavailable'][] = $field;
                Logger::error('Bank detail could not be decrypted', [
                    'field' => $field,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'reason' => $e::class,
                ]);
            }
        }

        return $details;
    }

    /** "PK36SCBL0000001123456702" → "****6702". For lists and audit rows; never the whole value. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return '****' . substr($value, -4);
    }

    /**
     * One masked line for a list row: the account number, else the IBAN,
     * else null. "Unavailable" when the stored value cannot be decrypted.
     *
     * @param array<string,mixed> $row holding bank_account_number_enc and bank_iban_enc
     */
    public static function maskedSummary(array $row, string $entityType, int $entityId): ?string
    {
        $details = self::decrypt(
            array_intersect_key($row, array_flip(['bank_account_number_enc', 'bank_iban_enc'])),
            $entityType,
            $entityId
        );

        if ($details['unavailable'] !== []) {
            return 'Unavailable';
        }

        return self::mask($details['bank_account_number']) ?? self::mask($details['bank_iban']);
    }

    /**
     * What an audit row may say about a change: which fields changed, and the
     * masked new account number and IBAN.
     *
     * A field whose old value could not be decrypted counts as changed: it is overwritten either way.
     *
     * @param array{bank_name:?string,bank_account_number:?string,bank_iban:?string,unavailable:list<string>} $before
     * @param array<string,mixed> $after validated, normalised input
     * @return array<string,mixed>|null null when nothing changed
     */
    public static function auditChange(array $before, array $after): ?array
    {
        $changed = [];
        foreach (array_keys(self::COLUMNS) as $field) {
            $old = $before[$field] ?? null;
            $new = ($after[$field] ?? null) === '' ? null : ($after[$field] ?? null);
            if ($old !== $new || in_array($field, $before['unavailable'], true)) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return null;
        }

        return [
            'changed' => $changed,
            'bank_account_number' => self::mask(self::nullableString($after['bank_account_number'] ?? null)),
            'bank_iban' => self::mask(self::nullableString($after['bank_iban'] ?? null)),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
