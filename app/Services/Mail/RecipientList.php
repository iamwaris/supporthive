<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * A typed-in list of notification addresses, validated.
 *
 * Accepts one per line or separated by commas/semicolons, which covers both
 * typing them and pasting from an address book. Duplicates are dropped
 * case-insensitively. The cap keeps one expense from turning into a mass
 * mailing if the field is ever misused.
 */
final class RecipientList
{
    public const MAX_RECIPIENTS = 10;

    /**
     * @param list<string> $emails
     * @param list<string> $errors
     */
    private function __construct(
        public readonly array $emails,
        public readonly array $errors,
    ) {
    }

    public static function parse(string $raw, int $max = self::MAX_RECIPIENTS): self
    {
        $emails = [];
        $errors = [];
        $seen = [];

        $candidates = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) > 190 || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = '"' . mb_substr($candidate, 0, 60) . '" is not a valid email address.';
                continue;
            }

            $key = strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $emails[] = $candidate;
        }

        if (count($emails) > $max) {
            $errors[] = 'List at most ' . $max . ' addresses (' . count($emails) . ' given).';
        }

        return new self($emails, $errors);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** How the list is stored in settings: one address per line. */
    public function toSetting(): string
    {
        return implode("\n", $this->emails);
    }
}
