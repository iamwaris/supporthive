<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where one employee's salary notice stands.
 *
 * draft -> sent -> confirmed | query. A response is final for that send:
 * only a query can be sent again (which clears it, after the audit log has
 * kept a copy). A confirmed line is closed.
 */
enum PayrollLineStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Query = 'query';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Not sent',
            self::Sent => 'Sent — awaiting response',
            self::Confirmed => 'Confirmed',
            self::Query => 'Query raised',
        };
    }

    /** Figures and screenshot can change only before the employee has seen them, or after they queried them. */
    public function canEdit(): bool
    {
        return $this === self::Draft || $this === self::Query;
    }

    /** Sending again is allowed until the employee confirms. */
    public function canSend(): bool
    {
        return $this !== self::Confirmed;
    }

    /** The public notice accepts a response only while one is awaited. */
    public function acceptsResponse(): bool
    {
        return $this === self::Sent;
    }
}
