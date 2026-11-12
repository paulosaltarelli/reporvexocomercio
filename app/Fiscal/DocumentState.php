<?php

namespace App\Fiscal;

enum DocumentState: string
{
    case Prepared = 'prepared';
    case Pending = 'pending';
    case Sent = 'sent';
    case Uncertain = 'uncertain';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Prepared => [self::Pending],
            self::Pending => [self::Sent],
            self::Sent => [self::Uncertain, self::Authorized, self::Rejected],
            self::Uncertain => [self::Authorized, self::Rejected],
            self::Authorized => [self::Cancelled],
            self::Rejected, self::Cancelled => [],
        }, true);
    }
}
