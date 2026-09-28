<?php

namespace App\Console\Commands;

use App\Exceptions\TradingEngineException;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:verify {--noop : Submit one synthetic no-op command} {--idempotency-key= : Required with --noop; reuse the exact same value after an uncertain outcome} {--message= : Optional synthetic message; reuse the exact same value after an uncertain outcome}')]
#[Description('Verify authenticated connectivity to the non-trading TypeScript engine')]
class VerifyTradingEngine extends Command
{
    public function handle(TradingEngineClient $engine): int
    {
        try {
            [$idempotencyKey, $message] = $this->noopOptions();
            $liveness = $engine->liveness();
            $readiness = $engine->readiness();
            $version = $engine->version();

            $this->table([], [
                ['Liveness', strtoupper($liveness['status'])],
                ['Readiness', strtoupper($readiness['status'])],
                ['PostgreSQL', strtoupper($readiness['dependencies']['postgres'])],
                ['Redis', strtoupper($readiness['dependencies']['redis'])],
                ['Service', $version['service']],
                ['Version', $version['version']],
                ['Node', $version['node']],
            ]);

            if ($readiness['status'] !== 'ok') {
                $this->error('Trading engine dependencies are unavailable.');

                return self::FAILURE;
            }

            if (! $this->option('noop')) {
                return self::SUCCESS;
            }

            $this->line("No-op idempotency key: {$idempotencyKey}");
            $this->line('After an uncertain outcome, retry with the exact same --idempotency-key and --message value (including an omitted message).');
            $result = $engine->noop($idempotencyKey, $message);
            $this->info('Synthetic no-op accepted.');
            $this->line('Operation: '.$result['operationId']);
            $this->line('Event: '.$result['eventId']);
            $this->line('Duplicate: '.($result['duplicate'] ? 'yes' : 'no'));

            return self::SUCCESS;
        } catch (TradingEngineException $exception) {
            $this->error($exception->getMessage());
            $this->line('Error code: '.$exception->errorCode);

            return self::FAILURE;
        }
    }

    /** @return array{?string, ?string} */
    private function noopOptions(): array
    {
        if (! $this->option('noop')) {
            return [null, null];
        }

        $idempotencyKey = $this->option('idempotency-key');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            throw new TradingEngineException(
                'VALIDATION_FAILED',
                'The --idempotency-key option is required when using --noop.',
            );
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) !== 1) {
            throw new TradingEngineException(
                'VALIDATION_FAILED',
                'The --idempotency-key option must contain 1 to 128 letters, numbers, dots, underscores, colons, or hyphens.',
            );
        }

        $message = $this->option('message');

        return [$idempotencyKey, is_string($message) && $message !== '' ? $message : null];
    }
}
