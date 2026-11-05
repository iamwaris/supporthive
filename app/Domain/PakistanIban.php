<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A Pakistani IBAN: "PK", two check digits, a four-letter bank code and a
 * sixteen-digit account number — 24 characters, verified with the ISO 13616
 * mod-97 checksum so a single mistyped digit is caught on the form rather
 * than at the bank.
 */
final class PakistanIban
{
    private const FORMAT = '/^PK\d{2}[A-Z]{4}\d{16}$/';

    /** "pk36 scbl 0000 0011 2345 6702" → "PK36SCBL0000001123456702". Spaces and hyphens only; nothing else is guessed. */
    public static function normalise(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/u', '', $value));
    }

    /** Expects normalise()d input. */
    public static function isValid(string $iban): bool
    {
        return preg_match(self::FORMAT, $iban) === 1 && self::mod97($iban) === 1;
    }

    /**
     * ISO 13616: move the first four characters to the end, replace each
     * letter with two digits (A = 10 … Z = 35) and take the remainder by 97,
     * nine digits at a time so it never overflows an int.
     */
    private static function mod97(string $iban): int
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';
        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder;
    }
}
