<?php

namespace App\Enums;

enum TradeOpportunityStatus: string
{
    case Qualified = 'qualified';
    case PendingConfirmation = 'pending_confirmation';
    case Executing = 'executing';
    case Executed = 'executed';
    case Ignored = 'ignored';
    case Expired = 'expired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Qualified => 'New',
            self::PendingConfirmation => 'Review',
            self::Executing => 'Executing',
            self::Executed => 'Executed',
            self::Ignored => 'Ignored',
            self::Expired => 'Expired',
            self::Failed => 'Failed',
        };
    }
}
