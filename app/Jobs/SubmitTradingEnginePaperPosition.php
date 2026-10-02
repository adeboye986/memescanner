<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperPositionLink;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitTradingEnginePaperPosition implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $positionLinkId,
        public readonly array $payload,
        public readonly string $idempotencyKey,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TradingEngineClient $client): void
    {
        if (! $this->enabled()) {
            return;
        }

        $link = TradingEnginePaperPositionLink::query()->find($this->positionLinkId);

        if (! $link instanceof TradingEnginePaperPositionLink
            || $link->registration_payload_sha256 !== app(TradingEngineCanonicalJson::class)->hash($this->payload)
            || $link->registration_idempotency_key !== $this->idempotencyKey) {
            return;
        }

        try {
            $response = $client->recordPaperPosition($this->idempotencyKey, $this->payload);
            $link->forceFill([
                'engine_position_id' => $response['positionId'],
                'ownership_state' => 'registered',
                'registration_error_code' => null,
                'registered_at' => now(),
            ])->save();
        } catch (TradingEngineException $exception) {
            $link->forceFill(['registration_error_code' => $exception->errorCode])->save();

            if (! $exception->retryable) {
                $link->forceFill(['ownership_state' => 'registration_failed'])->save();
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true;
    }
}
