<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineTerminalPaperLifecycleRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('trading-engine:recover-terminal-paper-lifecycle
    {event-id : Existing terminal PAPER lifecycle inbox event ID}
    {--expected-error= : Exact terminal error currently stored for the event}')]
#[Description('Safely dispatch one explicitly matched terminal PAPER lifecycle event for reprojection')]
class RecoverTerminalTradingEnginePaperLifecycle extends Command
{
    /** @var array<string, string> */
    private const SAFE_ERROR_MESSAGES = [
        'TERMINAL_PAPER_RECOVERY_DISABLED' => 'Trading engine authoritative PAPER lifecycle recovery is disabled.',
        'TERMINAL_PAPER_EVENT_NOT_FOUND' => 'The terminal PAPER lifecycle event was not found.',
        'TERMINAL_PAPER_STATUS_NOT_FAILED' => 'The event is not in the terminal failed state.',
        'TERMINAL_PAPER_EVENT_TYPE_UNSUPPORTED' => 'The event type is not eligible for terminal PAPER lifecycle recovery.',
        'TERMINAL_PAPER_EXPECTED_ERROR_MISMATCH' => 'The supplied expected error does not match the stored terminal error.',
        'TERMINAL_PAPER_ERROR_UNSUPPORTED' => 'The stored terminal error is not eligible for this recovery path.',
        'TERMINAL_PAPER_PAYLOAD_INVALID' => 'The stored lifecycle payload is invalid.',
        'TERMINAL_PAPER_PAYLOAD_HASH_MISMATCH' => 'The stored lifecycle payload failed its canonical hash check.',
        'TERMINAL_PAPER_USER_NOT_CANARY' => 'The lifecycle event does not belong to a configured canary user.',
        'TERMINAL_PAPER_POSITION_NOT_FOUND' => 'The correlated PAPER position was not found.',
        'TERMINAL_PAPER_LINK_NOT_FOUND' => 'The correlated PAPER lifecycle link was not found.',
        'TERMINAL_PAPER_IDENTITY_MISMATCH' => 'The lifecycle event failed recovery identity checks.',
        'TERMINAL_PAPER_REGISTRATION_SNAPSHOT_INVALID' => 'The stored registration snapshot failed its canonical hash check.',
        'TERMINAL_PAPER_OBSERVATION_NOT_FOUND' => 'The correlated PAPER observation was not found.',
        'TERMINAL_PAPER_OBSERVATION_SNAPSHOT_INVALID' => 'The stored PAPER observation failed its canonical hash check.',
        'TERMINAL_PAPER_DISPATCH_FAILED' => 'The projection job could not be dispatched and remains recoverable.',
    ];

    public function handle(TradingEngineTerminalPaperLifecycleRecovery $recovery): int
    {
        $eventId = (string) $this->argument('event-id');
        $expectedError = $this->option('expected-error');

        if (preg_match('/^[A-Za-z0-9]{26}$/D', $eventId) !== 1) {
            $this->error('The projection event ID must be a 26-character identifier.');

            return self::FAILURE;
        }

        if (! is_string($expectedError) || $expectedError === '') {
            $this->error('The --expected-error option is required.');

            return self::FAILURE;
        }

        try {
            $result = $recovery->recover($eventId, $expectedError);
        } catch (Throwable) {
            $this->error('The terminal PAPER lifecycle recovery could not be completed safely.');

            return self::FAILURE;
        }

        if ($result['inbox_id'] !== null) {
            $this->table(['Field', 'Value'], [
                ['Inbox ID', (string) $result['inbox_id']],
                ['Event type', $result['event_type'] ?? '-'],
                ['PAPER position ID', $result['paper_position_id'] === null ? '-' : (string) $result['paper_position_id']],
                ['User ID', $result['user_id'] === null ? '-' : (string) $result['user_id']],
                ['Previous terminal error', $result['previous_error'] ?? '-'],
                ['Recovery status', $result['status']],
            ]);
        }

        if ($result['status'] !== 'dispatched') {
            $this->error(self::SAFE_ERROR_MESSAGES[$result['error_code'] ?? '']
                ?? 'The event is not eligible for terminal PAPER lifecycle recovery.');

            return self::FAILURE;
        }

        $this->info('The terminal PAPER lifecycle event was leased and dispatched for full reprojection.');

        return self::SUCCESS;
    }
}
