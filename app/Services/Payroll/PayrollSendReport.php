<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/** What one press of Send did, line by line, for the admin's flash message. */
final class PayrollSendReport
{
    /**
     * @param list<string> $sent employee names
     * @param list<array{name:string,reason:string}> $skipped not attempted
     * @param list<array{name:string,reason:string}> $failed attempted, not sent
     */
    public function __construct(
        public readonly array $sent,
        public readonly array $skipped,
        public readonly array $failed,
    ) {
    }

    public function summary(): string
    {
        $parts = [];
        $parts[] = count($this->sent) === 1 ? '1 notice sent.' : count($this->sent) . ' notices sent.';

        foreach (['Skipped' => $this->skipped, 'Not sent' => $this->failed] as $label => $entries) {
            if ($entries !== []) {
                $parts[] = $label . ': ' . implode('; ', array_map(
                    static fn (array $entry): string => $entry['name'] . ' (' . $entry['reason'] . ')',
                    $entries
                )) . '.';
            }
        }

        return implode(' ', $parts);
    }

    public function hasProblems(): bool
    {
        return $this->skipped !== [] || $this->failed !== [];
    }
}
