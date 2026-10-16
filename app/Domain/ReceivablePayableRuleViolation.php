<?php

declare(strict_types=1);

namespace App\Domain;

use DomainException;

/**
 * A receivables / payables state or date rule refused a change.
 *
 * Carries the per-field messages, so the screen can show each one next to
 * the field it belongs to rather than only in a banner.
 */
final class ReceivablePayableRuleViolation extends DomainException
{
    /** @param array<string,string> $fieldErrors field => message, never empty */
    public function __construct(private readonly array $fieldErrors)
    {
        parent::__construct((string) (array_values($fieldErrors)[0] ?? 'That change is not allowed.'));
    }

    /** @return array<string,string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
