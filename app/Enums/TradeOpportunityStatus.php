<?php

namespace App\Enums;

enum TradeOpportunityStatus: string
{
    case Qualified = 'qualified';
    case PendingConfirmation = 'pending_confirmation';
    case Executed = 'executed';
    case Ignored = 'ignored';
    case Expired = 'expired';
    case Failed = 'failed';
}
