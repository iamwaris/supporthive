<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\PakistanIban;

/**
 * Bank name, account number and IBAN — for an employee (employee_profiles)
 * or a joiner who has not been approved yet (joiner_submissions). Both
 * tables store them under the same column names, so this is the one place
 * they are normalised, validated, read back and masked.
 *
 * Stored in plain text (decision 2026-11-05, docs/PLAN.md §5), so what keeps
 * them private is the code around them: admin-only routes, masked values in
 * every list, and nothing but masked values in an audit row (mask()). Never
 * pass them to a log.
 */
final class BankDetails
{
    /** The request fields, which are also the column names in both tables. */
    public const FIELDS = ['bank_name', 'bank_account_number', 'bank_iban'];

    /**
     * Never put back into the session as flashed input after a failed
     * submit: session files sit on disk outside the database, and a stray
     * copy of a full account number there is one more place it can leak
     * from. The form asks for them again instead.
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
        foreach (self::FIELDS as $field) {
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
     * The three values as they are stored, from validated input or from a
     * row of either table; a blank or missing field becomes null.
     *
     * @param array<string,mixed> $source Validator::validated() output covering RULES, or a database row
     * @return array{bank_name:?string,bank_account_number:?string,bank_iban:?string}
     */
    public static function from(array $source): array
    {
        return [
            'bank_name' => self::nullableString($source['bank_name'] ?? null),
            'bank_account_number' => self::nullableString($source['bank_account_number'] ?? null),
            'bank_iban' => self::nullableString($source['bank_iban'] ?? null),
        ];
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
     * One masked line for a list row: the account number, else the IBAN, else null.
     *
     * @param array<string,mixed> $row holding bank_account_number and bank_iban
     */
    public static function maskedSummary(array $row): ?string
    {
        $details = self::from($row);

        return self::mask($details['bank_account_number']) ?? self::mask($details['bank_iban']);
    }

    /**
     * What an audit row may say about a change: which fields changed, and the
     * masked new account number and IBAN.
     *
     * @param array{bank_name:?string,bank_account_number:?string,bank_iban:?string} $before
     * @param array<string,mixed> $after validated, normalised input
     * @return array<string,mixed>|null null when nothing changed
     */
    public static function auditChange(array $before, array $after): ?array
    {
        $new = self::from($after);
        $changed = [];
        foreach (self::FIELDS as $field) {
            if ($before[$field] !== $new[$field]) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return null;
        }

        return [
            'changed' => $changed,
            'bank_account_number' => self::mask($new['bank_account_number']),
            'bank_iban' => self::mask($new['bank_iban']),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
