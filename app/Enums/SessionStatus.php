<?php

namespace App\Enums;

/**
 * Session lifecycle. `Upcoming` is a scheduled session; the other five are the statuses of the
 * SVUIS "My Sessions" legend once the session has been held.
 */
enum SessionStatus: string
{
    case Upcoming = 'upcoming';
    case NotReported = 'notReported';
    case PendingApproval = 'pendingApproval';
    case Rejected = 'rejected';
    case ApprovedUnpaid = 'approvedUnpaid';
    case Paid = 'paid';

    /** Allowed transitions; anything else is a 409 Conflict. */
    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Upcoming => [self::NotReported],
            // A rejected session can be corrected and reported again.
            self::NotReported, self::Rejected => [self::PendingApproval],
            self::PendingApproval => [self::ApprovedUnpaid, self::Rejected],
            self::ApprovedUnpaid => [self::Paid],
            self::Paid => [],
        }, true);
    }
}
