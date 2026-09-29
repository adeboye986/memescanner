<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineProjectionRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('trading-engine:retry-projection {event-id : Existing trading engine inbox event ID}')]
#[Description('Safely lease and dispatch one eligible opportunity projection event')]
class RetryTradingEngineProjection extends Command
{
    /** @var array<string, string> */
    private const SAFE_ERROR_MESSAGES = [
        'PROJECTION_RECOVERY_DISABLED' => 'Trading engine opportunity projection is disabled.',
        'PROJECTION_EVENT_NOT_FOUND' => 'The trading engine projection event was not found.',
        'PROJECTION_EVENT_TYPE_UNSUPPORTED' => 'The event type is not supported for opportunity projection.',
        'PROJECTION_ALREADY_PROJECTED' => 'The event is already projected and cannot be retried.',
        'PROJECTION_TERMINALLY_FAILED' => 'The event has a terminal integrity failure and cannot be retried.',
        'PROJECTION_EVENT_UNHANDLED' => 'Unhandled event types cannot be projected.',
        'PROJECTION_STATUS_UNSUPPORTED' => 'The event status is not eligible for projection retry.',
        'PROJECTION_CAUSAL_DEPENDENCY_MISSING' => 'The evaluation is waiting for its recorded opportunity correlation.',
        'PROJECTION_LEASE_ACTIVE' => 'The event currently has an active dispatch lease.',
        'PROJECTION_NOT_DUE' => 'The event is not currently due for projection retry.',
        'PROJECTION_LEASE_NOT_ACQUIRED' => 'The event lease was acquired by another recovery attempt.',
        'PROJECTION_DISPATCH_FAILED' => 'The projection job could not be dispatched and remains recoverable.',
    ];

    public function handle(TradingEngineProjectionRecovery $recovery): int
    {
        $eventId = (string) $this->argument('event-id');

        if (preg_match('/^[A-Za-z0-9]{26}$/D', $eventId) !== 1) {
            $this->error('The projection event ID must be a 26-character identifier.');

            return self::FAILURE;
        }

        try {
            $result = $recovery->retry($eventId);
        } catch (Throwable) {
            $this->error('The projection retry could not safely acquire or dispatch the event.');

            return self::FAILURE;
        }

        if ($result['status'] !== 'dispatched') {
            $this->error(self::SAFE_ERROR_MESSAGES[$result['error_code'] ?? '']
                ?? 'The event is not eligible for projection retry.');

            return self::FAILURE;
        }

        $this->info('The projection event was leased and dispatched.');

        return self::SUCCESS;
    }
}
