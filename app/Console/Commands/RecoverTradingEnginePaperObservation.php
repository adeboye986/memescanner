<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEnginePaperObservationRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('trading-engine:recover-paper-observation
    {observation-id : Existing Laravel PAPER observation row ID}
    {--expected-error= : Exact recoverable error stored as current state or recovery provenance}')]
#[Description('Safely recover or resume one explicitly matched PAPER lifecycle observation')]
class RecoverTradingEnginePaperObservation extends Command
{
    /** @var array<string, string> */
    private const SAFE_ERROR_MESSAGES = [
        'PAPER_OBSERVATION_RECOVERY_DISABLED' => 'Trading engine authoritative PAPER observation recovery is disabled.',
        'PAPER_OBSERVATION_NOT_FOUND' => 'The PAPER lifecycle observation was not found.',
        'PAPER_OBSERVATION_STATUS_NOT_RECOVERABLE' => 'The observation state is not eligible for recovery.',
        'PAPER_OBSERVATION_RECOVERY_PROVENANCE_INVALID' => 'The observation is not an authorized pending or completed recovery.',
        'PAPER_OBSERVATION_ALREADY_PROCESSED_INVALID' => 'The completed recovery is missing valid engine acknowledgement data.',
        'PAPER_OBSERVATION_EXPECTED_ERROR_MISMATCH' => 'The supplied expected error does not match the stored observation error.',
        'PAPER_OBSERVATION_ERROR_NOT_RECOVERABLE' => 'The stored error is not eligible for PAPER observation recovery.',
        'PAPER_OBSERVATION_LINK_NOT_FOUND' => 'The associated PAPER lifecycle link was not found.',
        'PAPER_OBSERVATION_POSITION_NOT_FOUND' => 'The associated PAPER position was not found.',
        'PAPER_OBSERVATION_PAYLOAD_HASH_MISMATCH' => 'The stored observation payload failed its canonical hash check.',
        'PAPER_OBSERVATION_IDENTITY_MISMATCH' => 'The observation failed lifecycle ownership or identity checks.',
        'PAPER_OBSERVATION_SEQUENCE_NOT_RECOVERABLE' => 'The observation is not the unresolved sequence immediately before the next reserved sequence.',
        'PAPER_OBSERVATION_LATER_SEQUENCE_EXISTS' => 'A later observation exists, so this observation cannot be safely recovered.',
        'PAPER_OBSERVATION_ALREADY_ACKNOWLEDGED' => 'The observation already contains engine acknowledgement data.',
        'PAPER_OBSERVATION_RECOVERY_DISPATCH_FAILED' => 'The recovery job could not be dispatched; the observation remains failed.',
    ];

    public function handle(TradingEnginePaperObservationRecovery $recovery): int
    {
        $observationId = filter_var($this->argument('observation-id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $expectedError = $this->option('expected-error');

        if (! is_int($observationId)) {
            $this->error('The observation ID must be a positive integer Laravel row ID.');

            return self::FAILURE;
        }

        if (! is_string($expectedError) || preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $expectedError) !== 1) {
            $this->error('The --expected-error option must contain the exact safe error code currently stored.');

            return self::FAILURE;
        }

        try {
            $result = $recovery->recover($observationId, $expectedError);
        } catch (Throwable) {
            $this->error('The PAPER observation recovery could not be completed safely.');

            return self::FAILURE;
        }

        if ($result['observation_row_id'] !== null) {
            $this->table(['Field', 'Value'], [
                ['Observation row ID', (string) $result['observation_row_id']],
                ['PAPER position ID', (string) $result['paper_position_id']],
                ['Observation ID', (string) $result['observation_id']],
                ['Sequence', (string) $result['sequence']],
                ['Idempotency key', (string) $result['idempotency_key']],
                ['Previous error', (string) $result['previous_error']],
                ['Recovery status', $result['status']],
            ]);
        }

        if (! in_array($result['status'], ['dispatched', 'already_processed'], true)) {
            $this->error(self::SAFE_ERROR_MESSAGES[$result['error_code'] ?? '']
                ?? 'The observation is not eligible for PAPER lifecycle recovery.');

            return self::FAILURE;
        }

        if ($result['status'] === 'already_processed') {
            $this->info('The PAPER observation recovery was already submitted or evaluated; no job was dispatched.');

            return self::SUCCESS;
        }

        $this->info('The existing PAPER observation was authorized and dispatched with its original payload and idempotency key.');

        return self::SUCCESS;
    }
}
