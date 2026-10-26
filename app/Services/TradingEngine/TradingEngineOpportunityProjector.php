<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\TradingEngineProjectionException;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEngineScanRequest;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class TradingEngineOpportunityProjector
{
    public function __construct(
        private TradingEnginePaperLifecycleProjector $paperLifecycle,
        private TradingEnginePaperEntryProjector $paperEntries,
        private TradingEnginePaperFinancialLifecycleProjector $paperFinancialLifecycle,
        private TradingEngineOpportunityExportTracker $opportunityExports,
        private TradingEngineCanonicalJson $canonicalJson,
    ) {}

    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    private const PROJECTABLE_EVENT_TYPES = [
        'opportunity.recorded.v1',
        'opportunity.evaluated.v1',
        'paper.position.recorded.v1',
        'paper.position.evaluated.v1',
        'paper.exit.requested.v1',
        'paper.entry.executed.v1',
        'paper.position.held.v1',
        'paper.exit.settled.v1',
    ];

    /**
     * @return array{status: string, dependent_event_ids: array<int, string>}
     */
    public function project(string $eventId): array
    {
        try {
            return DB::transaction(function () use ($eventId): array {
                $event = TradingEngineEvent::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if (! $event || ! in_array($event->event_type, self::PROJECTABLE_EVENT_TYPES, true)) {
                    return ['status' => 'ignored', 'dependent_event_ids' => []];
                }

                if ($event->handling_status === TradingEngineEvent::STATUS_PROJECTED) {
                    return [
                        'status' => TradingEngineEvent::STATUS_PROJECTED,
                        'dependent_event_ids' => $this->dependentEvaluationEventIds($event),
                    ];
                }

                if (in_array($event->handling_status, [
                    TradingEngineEvent::STATUS_FAILED,
                    TradingEngineEvent::STATUS_UNHANDLED,
                ], true)) {
                    return ['status' => $event->handling_status, 'dependent_event_ids' => []];
                }

                $event->handling_attempts++;

                try {
                    if ($event->event_type === 'paper.entry.executed.v1') {
                        $this->paperEntries->project($event);
                        $this->markProjected($event);

                        return ['status' => TradingEngineEvent::STATUS_PROJECTED, 'dependent_event_ids' => []];
                    }
                    if (in_array($event->event_type, ['paper.position.held.v1', 'paper.exit.settled.v1'], true)) {
                        $this->paperFinancialLifecycle->project($event);
                        $this->markProjected($event);

                        return ['status' => TradingEngineEvent::STATUS_PROJECTED, 'dependent_event_ids' => []];
                    }

                    if (str_starts_with($event->event_type, 'paper.')) {
                        $this->paperLifecycle->project($event);
                        $this->markProjected($event);

                        return ['status' => TradingEngineEvent::STATUS_PROJECTED, 'dependent_event_ids' => []];
                    }

                    if ($event->event_type === 'opportunity.recorded.v1') {
                        $this->projectRecordedEvent($event);
                        $this->markProjected($event);

                        return [
                            'status' => TradingEngineEvent::STATUS_PROJECTED,
                            'dependent_event_ids' => $this->dependentEvaluationEventIds($event),
                        ];
                    }

                    $status = $this->projectEvaluatedEvent($event);

                    return ['status' => $status, 'dependent_event_ids' => []];
                } catch (TradingEngineProjectionException $exception) {
                    if ($exception->retryable) {
                        throw $exception;
                    }

                    $this->markFailed($event, $exception->errorCode);

                    return ['status' => TradingEngineEvent::STATUS_FAILED, 'dependent_event_ids' => []];
                }
            }, 3);
        } catch (TradingEngineProjectionException $exception) {
            $this->markRetryableFailure($eventId, $exception->errorCode);

            throw $exception;
        } catch (Throwable) {
            $this->markRetryableFailure($eventId, 'PROJECTION_TEMPORARY_FAILURE');

            throw new TradingEngineProjectionException(
                'PROJECTION_TEMPORARY_FAILURE',
                'The trading engine event projection temporarily failed.',
                true,
            );
        }
    }

    public function markDispatchFailure(string $eventId): void
    {
        try {
            TradingEngineEvent::query()
                ->where('event_id', $eventId)
                ->whereIn('handling_status', [
                    TradingEngineEvent::STATUS_STORED,
                    TradingEngineEvent::STATUS_DEFERRED,
                    TradingEngineEvent::STATUS_RETRYABLE,
                ])
                ->update([
                    'handling_status' => TradingEngineEvent::STATUS_RETRYABLE,
                    'handling_error_code' => 'PROJECTION_DISPATCH_FAILED',
                    'next_handling_at' => now()->addMinute(),
                    'handled_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // The authenticated inbox row remains the durable recovery source.
        }
    }

    private function projectRecordedEvent(TradingEngineEvent $event): void
    {
        $payload = $this->arrayValue($event->payload, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $source = $this->arrayValue($payload['source'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $subject = $this->arrayValue($payload['subject'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $network = $this->arrayValue($payload['network'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $asset = $this->arrayValue($payload['asset'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $localOpportunityId = $this->stringValue($source['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $operationId = $this->stringValue($payload['operation_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        if (($source['system'] ?? null) === 'meme-scanner-trading-engine') {
            $this->projectScannerRecordedEvent($event, $payload, $source, $subject, $network, $asset, $operationId);

            return;
        }

        if (preg_match('/^[1-9][0-9]*$/D', $localOpportunityId) !== 1) {
            $this->reject('RECORDED_EVENT_PAYLOAD_INVALID');
        }

        $opportunity = TradeOpportunity::query()->find($localOpportunityId);

        if (! $opportunity) {
            $this->reject('LOCAL_OPPORTUNITY_NOT_FOUND');
        }

        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $userId = $this->stringValue($subject['control_plane_user_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $discoveryKey = $this->stringValue($source['discovery_key'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $scanner = $this->stringValue($source['scanner'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $networkId = $this->stringValue($network['id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $assetAddress = $this->stringValue($asset['address'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        $this->assertOpportunityIdentity(
            $opportunity,
            $localOpportunityId,
            $userId,
            $discoveryKey,
            $scanner,
            $networkId,
            $assetAddress,
        );

        if ($event->aggregate_id !== $engineOpportunityId
            || $event->idempotency_key !== 'opportunity:record:laravel:'.$localOpportunityId.':v1') {
            $this->reject('RECORDED_EVENT_IDEMPOTENCY_MISMATCH');
        }

        $existing = TradingEngineOpportunityLink::query()
            ->where('trade_opportunity_id', $opportunity->getKey())
            ->orWhere('engine_opportunity_id', $engineOpportunityId)
            ->orWhere('recorded_event_id', $event->event_id)
            ->first();

        if ($existing) {
            if (! $this->linkMatches(
                $existing,
                $opportunity,
                $event,
                $engineOpportunityId,
                $userId,
                $discoveryKey,
                $scanner,
                $networkId,
                $assetAddress,
            )) {
                $this->reject('OPPORTUNITY_LINK_CONFLICT');
            }

            $this->reconcileRecordedExport($opportunity, $event, $operationId, $engineOpportunityId);

            return;
        }

        TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $engineOpportunityId,
            'recorded_event_id' => $event->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $discoveryKey,
            'scanner' => $scanner,
            'network_id' => $networkId,
            'asset_address' => $assetAddress,
            'recorded_at' => $event->occurred_at,
            'linked_at' => now(),
        ]);

        $this->reconcileRecordedExport($opportunity, $event, $operationId, $engineOpportunityId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $network
     * @param  array<string, mixed>  $asset
     */
    private function projectScannerRecordedEvent(
        TradingEngineEvent $event,
        array $payload,
        array $source,
        array $subject,
        array $network,
        array $asset,
        string $operationId,
    ): void {
        $discoveryId = $this->stringValue($source['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $scanExecutionId = $this->stringValue($source['scan_execution_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $userId = (int) $this->stringValue($subject['control_plane_user_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $scanner = $this->stringValue($source['scanner'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $networkId = $this->stringValue($network['id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $assetAddress = $this->stringValue($asset['address'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $discoveryKey = $this->stringValue($source['discovery_key'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $chain = match ($networkId) {
            self::SOLANA_MAINNET_ID => Chain::Solana,
            self::ETHEREUM_MAINNET_ID => Chain::Ethereum,
            default => $this->reject('RECORDED_EVENT_PAYLOAD_INVALID'),
        };
        $expectedAction = $scanner === 'new-token' ? 'token-scan' : 'momentum-scan';

        if ($event->aggregate_id !== $engineOpportunityId
            || $event->causation_id !== $operationId
            || $event->idempotency_key !== 'opportunity:record:scanner:'.$discoveryId.':v1') {
            $this->reject('RECORDED_EVENT_IDEMPOTENCY_MISMATCH');
        }

        $request = TradingEngineScanRequest::query()
            ->where('engine_scan_execution_id', $scanExecutionId)
            ->lockForUpdate()
            ->first();

        if (! $request instanceof TradingEngineScanRequest) {
            $candidates = TradingEngineScanRequest::query()
                ->where('user_id', $userId)
                ->where('scanner', $scanner)
                ->where('network_id', $networkId)
                ->whereNull('engine_scan_execution_id')
                ->whereNull('definitively_rejected_at')
                ->whereNotIn('status', [
                    TradingEngineScanRequest::STATUS_FAILED,
                    TradingEngineScanRequest::STATUS_PROJECTED,
                ])
                ->lockForUpdate()
                ->get();

            if ($candidates->count() !== 1) {
                $this->retryable('SCAN_REQUEST_IDENTITY_PENDING');
            }

            $request = $candidates->first();
        }

        if (! $request instanceof TradingEngineScanRequest
            || ! is_array($request->payload)
            || data_get($request->payload, 'subject.control_plane_user_id') !== (string) $userId
            || data_get($request->payload, 'scanner') !== $scanner
            || data_get($request->payload, 'network.id') !== $networkId
            || $this->canonicalJson->hash($request->payload) !== $request->payload_sha256
            || $request->status === TradingEngineScanRequest::STATUS_FAILED
            || ($request->engine_scan_execution_id !== null
                && $request->engine_scan_execution_id !== $scanExecutionId)
            || $request->definitively_rejected_at !== null) {
            $this->reject('SCAN_REQUEST_IDENTITY_MISMATCH');
        }

        $activity = $request->activity()->lockForUpdate()->first();

        if (! $activity
            || $activity->user_id !== $userId
            || $activity->action !== $expectedAction
            || $activity->chain !== $chain
            || ! in_array($activity->status, ['pending', 'running', 'completed'], true)
            || data_get($request->payload, 'source.activity_id') !== (string) $activity->getKey()) {
            $this->reject('SCAN_ACTIVITY_IDENTITY_MISMATCH');
        }

        $market = $this->arrayValue($payload['market_snapshot'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $qualification = $this->arrayValue($payload['qualification'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $opportunity = TradeOpportunity::query()->firstOrCreate([
            'user_id' => $userId,
            'discovery_key' => $discoveryKey,
        ], [
            'chain' => $chain,
            'address' => $assetAddress,
            'symbol' => $asset['symbol'] ?? null,
            'name' => $asset['name'] ?? null,
            'scanner' => $scanner,
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::from($request->entry_mode),
            'pair_address' => data_get($market, 'pair.address'),
            'price' => data_get($market, 'price_usd.value'),
            'market_cap' => data_get($market, 'market_cap_usd.value'),
            'liquidity' => data_get($market, 'liquidity_usd.value'),
            'volume' => data_get($market, 'volume_usd.value'),
            'qualification_data' => [
                ...$qualification,
                'owner' => 'trading-engine',
                'scan_execution_id' => $scanExecutionId,
            ],
            'security_data' => $payload['security'] ?? null,
            'qualified_at' => $qualification['qualified_at'],
        ]);

        if ($opportunity->user_id !== $userId
            || $opportunity->chain !== $chain
            || $opportunity->address !== $assetAddress
            || $opportunity->scanner !== $scanner
            || $opportunity->execution_mode !== ExecutionMode::Paper
            || $opportunity->entry_mode !== EntryMode::from($request->entry_mode)) {
            $this->reject('LOCAL_OPPORTUNITY_CONFLICT');
        }

        $activity->tradeOpportunities()->syncWithoutDetaching([$opportunity->getKey()]);
        $existing = TradingEngineOpportunityLink::query()
            ->where('trade_opportunity_id', $opportunity->getKey())
            ->orWhere('engine_opportunity_id', $engineOpportunityId)
            ->orWhere('recorded_event_id', $event->event_id)
            ->first();

        if (! $existing) {
            TradingEngineOpportunityLink::query()->create([
                'trade_opportunity_id' => $opportunity->getKey(),
                'engine_opportunity_id' => $engineOpportunityId,
                'recorded_event_id' => $event->event_id,
                'user_id' => $userId,
                'discovery_key' => $discoveryKey,
                'scanner' => $scanner,
                'network_id' => $networkId,
                'asset_address' => $assetAddress,
                'recorded_at' => $event->occurred_at,
                'linked_at' => now(),
            ]);
        } elseif ($existing->trade_opportunity_id !== $opportunity->getKey()
            || $existing->engine_opportunity_id !== $engineOpportunityId
            || $existing->recorded_event_id !== $event->event_id
            || $existing->user_id !== $userId
            || $existing->discovery_key !== $discoveryKey
            || $existing->scanner !== $scanner
            || $existing->network_id !== $networkId
            || $existing->asset_address !== $assetAddress) {
            $this->reject('OPPORTUNITY_LINK_CONFLICT');
        }

        $request->forceFill([
            'engine_scan_execution_id' => $scanExecutionId,
            'status' => $request->status === TradingEngineScanRequest::STATUS_PROJECTED
                ? TradingEngineScanRequest::STATUS_PROJECTED
                : TradingEngineScanRequest::STATUS_ACCEPTED,
            'acceptance_unknown_at' => null,
            'last_error_code' => null,
            'accepted_at' => $request->accepted_at ?? now(),
        ])->save();
    }

    private function reconcileRecordedExport(
        TradeOpportunity $opportunity,
        TradingEngineEvent $event,
        string $operationId,
        string $engineOpportunityId,
    ): void {
        try {
            $this->opportunityExports->acknowledgedByRecordedEvent(
                $opportunity,
                $event->idempotency_key,
                $operationId,
                $engineOpportunityId,
                $event->event_id,
            );
        } catch (LogicException) {
            $this->reject('OPPORTUNITY_EXPORT_ACKNOWLEDGEMENT_CONFLICT');
        }
    }

    private function projectEvaluatedEvent(TradingEngineEvent $event): string
    {
        $causalEvent = TradingEngineEvent::query()
            ->where('event_id', $event->causation_id)
            ->first();

        if (! $causalEvent) {
            $this->markDeferred($event, 'CAUSAL_RECORDED_EVENT_PENDING');

            return TradingEngineEvent::STATUS_DEFERRED;
        }

        if ($causalEvent->event_type !== 'opportunity.recorded.v1') {
            $this->reject('CAUSAL_EVENT_TYPE_MISMATCH');
        }

        $link = TradingEngineOpportunityLink::query()
            ->where('recorded_event_id', $causalEvent->event_id)
            ->first();

        if (! $link) {
            if ($causalEvent->handling_status === TradingEngineEvent::STATUS_FAILED) {
                $this->reject('CAUSAL_RECORDED_EVENT_FAILED');
            }

            $this->markDeferred($event, 'CAUSAL_RECORDED_EVENT_PENDING');

            return TradingEngineEvent::STATUS_DEFERRED;
        }

        $opportunity = TradeOpportunity::query()->find($link->trade_opportunity_id);

        if (! $opportunity) {
            $this->reject('LOCAL_OPPORTUNITY_NOT_FOUND');
        }

        $this->assertLinkAndRecordedEventIdentity($link, $opportunity, $causalEvent);

        $payload = $this->arrayValue($event->payload, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $policy = $this->arrayValue($payload['policy'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $source = $this->arrayValue($payload['source'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $evaluationId = $this->stringValue($payload['evaluation_id'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $policyKey = $this->stringValue($policy['key'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $policyVersion = $this->integerValue($policy['version'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');

        if ($engineOpportunityId !== $link->engine_opportunity_id
            || $causalEvent->aggregate_id !== $link->engine_opportunity_id) {
            $this->reject('ENGINE_OPPORTUNITY_MISMATCH');
        }

        if ($event->correlation_id !== $causalEvent->correlation_id
            || $event->traceparent !== $causalEvent->traceparent) {
            $this->reject('EVALUATION_CORRELATION_MISMATCH');
        }

        if ($event->aggregate_id !== $evaluationId
            || $event->idempotency_key !== 'evaluation:'.$engineOpportunityId.':'.$policyKey.':'.$policyVersion) {
            $this->reject('EVALUATION_IDEMPOTENCY_MISMATCH');
        }

        $existing = TradingEngineOpportunityEvaluation::query()
            ->where('evaluation_id', $evaluationId)
            ->orWhere('evaluation_event_id', $event->event_id)
            ->orWhere(function ($query) use ($engineOpportunityId, $policyKey, $policyVersion): void {
                $query->where('engine_opportunity_id', $engineOpportunityId)
                    ->where('policy_key', $policyKey)
                    ->where('policy_version', $policyVersion);
            })
            ->first();

        if ($existing) {
            if ($existing->evaluation_id !== $evaluationId
                || $existing->evaluation_event_id !== $event->event_id
                || $existing->engine_opportunity_id !== $engineOpportunityId
                || $existing->opportunity_link_id !== $link->getKey()
                || $existing->trade_opportunity_id !== $opportunity->getKey()
                || $existing->recorded_event_id !== $causalEvent->event_id
                || $existing->policy_key !== $policyKey
                || $existing->policy_version !== $policyVersion
                || $existing->result_sha256 !== ($payload['result_sha256'] ?? null)) {
                $this->reject('EVALUATION_PROJECTION_CONFLICT');
            }

            $this->markProjected($event);

            return TradingEngineEvent::STATUS_PROJECTED;
        }

        $reasonCodes = $this->listValue($payload['reason_codes'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $advisoryCodes = $this->listValue($payload['advisory_codes'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $evidence = $this->arrayValue($payload['evidence'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');
        $outcome = $this->stringValue($payload['outcome'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID');

        if (! in_array($outcome, ['passed', 'failed', 'indeterminate'], true)) {
            $this->reject('EVALUATION_EVENT_PAYLOAD_INVALID');
        }

        TradingEngineOpportunityEvaluation::query()->create([
            'opportunity_link_id' => $link->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'evaluation_id' => $evaluationId,
            'engine_opportunity_id' => $engineOpportunityId,
            'evaluation_event_id' => $event->event_id,
            'recorded_event_id' => $causalEvent->event_id,
            'policy_key' => $policyKey,
            'policy_version' => $policyVersion,
            'algorithm_key' => $this->stringValue($policy['algorithm_key'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'algorithm_version' => $this->integerValue($policy['algorithm_version'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'policy_definition_sha256' => $this->stringValue($policy['definition_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'source_request_sha256' => $this->stringValue($source['request_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'evaluation_input_sha256' => $this->stringValue($source['evaluation_input_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'result_sha256' => $this->stringValue($payload['result_sha256'] ?? null, 'EVALUATION_EVENT_PAYLOAD_INVALID'),
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
            'correlation_id' => $event->correlation_id,
            'traceparent' => $event->traceparent,
            'evaluated_at' => $event->occurred_at,
            'event_received_at' => $event->received_at,
            'projected_at' => now(),
        ]);

        $this->markProjected($event);

        return TradingEngineEvent::STATUS_PROJECTED;
    }

    private function assertLinkAndRecordedEventIdentity(
        TradingEngineOpportunityLink $link,
        TradeOpportunity $opportunity,
        TradingEngineEvent $recordedEvent,
    ): void {
        $payload = $this->arrayValue($recordedEvent->payload, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $source = $this->arrayValue($payload['source'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $subject = $this->arrayValue($payload['subject'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $network = $this->arrayValue($payload['network'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $asset = $this->arrayValue($payload['asset'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        $localOpportunityId = $this->stringValue($source['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $userId = $this->stringValue($subject['control_plane_user_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $discoveryKey = $this->stringValue($source['discovery_key'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $scanner = $this->stringValue($source['scanner'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $networkId = $this->stringValue($network['id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $assetAddress = $this->stringValue($asset['address'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');
        $engineOpportunityId = $this->stringValue($payload['opportunity_id'] ?? null, 'RECORDED_EVENT_PAYLOAD_INVALID');

        $this->assertOpportunityIdentity(
            $opportunity,
            $localOpportunityId,
            $userId,
            $discoveryKey,
            $scanner,
            $networkId,
            $assetAddress,
        );

        if (! $this->linkMatches(
            $link,
            $opportunity,
            $recordedEvent,
            $engineOpportunityId,
            $userId,
            $discoveryKey,
            $scanner,
            $networkId,
            $assetAddress,
        )) {
            $this->reject('OPPORTUNITY_LINK_CONFLICT');
        }
    }

    private function assertOpportunityIdentity(
        TradeOpportunity $opportunity,
        string $localOpportunityId,
        string $userId,
        string $discoveryKey,
        string $scanner,
        string $networkId,
        string $assetAddress,
    ): void {
        $expectedNetworkId = match ($opportunity->chain) {
            Chain::Solana => self::SOLANA_MAINNET_ID,
            Chain::Ethereum => self::ETHEREUM_MAINNET_ID,
        };

        if ((string) $opportunity->getKey() !== $localOpportunityId
            || $opportunity->user_id === null
            || (string) $opportunity->user_id !== $userId
            || $opportunity->discovery_key !== $discoveryKey
            || $opportunity->scanner !== $scanner
            || $expectedNetworkId !== $networkId
            || $opportunity->address !== $assetAddress) {
            $this->reject('LOCAL_OPPORTUNITY_IDENTITY_MISMATCH');
        }
    }

    private function linkMatches(
        TradingEngineOpportunityLink $link,
        TradeOpportunity $opportunity,
        TradingEngineEvent $event,
        string $engineOpportunityId,
        string $userId,
        string $discoveryKey,
        string $scanner,
        string $networkId,
        string $assetAddress,
    ): bool {
        return $link->trade_opportunity_id === $opportunity->getKey()
            && $link->engine_opportunity_id === $engineOpportunityId
            && $link->recorded_event_id === $event->event_id
            && (string) $link->user_id === $userId
            && $link->discovery_key === $discoveryKey
            && $link->scanner === $scanner
            && $link->network_id === $networkId
            && $link->asset_address === $assetAddress;
    }

    /** @return array<int, string> */
    private function dependentEvaluationEventIds(TradingEngineEvent $event): array
    {
        if ($event->event_type !== 'opportunity.recorded.v1') {
            return [];
        }

        return TradingEngineEvent::query()
            ->where('event_type', 'opportunity.evaluated.v1')
            ->where('causation_id', $event->event_id)
            ->whereIn('handling_status', [
                TradingEngineEvent::STATUS_STORED,
                TradingEngineEvent::STATUS_DEFERRED,
                TradingEngineEvent::STATUS_RETRYABLE,
            ])
            ->orderBy('id')
            ->pluck('event_id')
            ->all();
    }

    private function markProjected(TradingEngineEvent $event): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_PROJECTED,
            'handling_error_code' => null,
            'next_handling_at' => null,
            'handled_at' => now(),
        ])->save();
    }

    private function markDeferred(TradingEngineEvent $event, string $errorCode): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_DEFERRED,
            'handling_error_code' => $errorCode,
            'next_handling_at' => now()->addMinute(),
            'handled_at' => null,
        ])->save();
    }

    private function markFailed(TradingEngineEvent $event, string $errorCode): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_FAILED,
            'handling_error_code' => $errorCode,
            'next_handling_at' => null,
            'handled_at' => now(),
        ])->save();
    }

    private function markRetryableFailure(string $eventId, string $errorCode): void
    {
        try {
            TradingEngineEvent::query()
                ->where('event_id', $eventId)
                ->whereNotIn('handling_status', [
                    TradingEngineEvent::STATUS_PROJECTED,
                    TradingEngineEvent::STATUS_FAILED,
                    TradingEngineEvent::STATUS_UNHANDLED,
                ])
                ->update([
                    'handling_status' => TradingEngineEvent::STATUS_RETRYABLE,
                    'handling_attempts' => DB::raw('handling_attempts + 1'),
                    'handling_error_code' => $errorCode,
                    'next_handling_at' => now()->addMinute(),
                    'handled_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // The authenticated inbox row remains the durable recovery source.
        }
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value, string $errorCode): array
    {
        if (! is_array($value)) {
            $this->reject($errorCode);
        }

        return $value;
    }

    /** @return array<int, mixed> */
    private function listValue(mixed $value, string $errorCode): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->reject($errorCode);
        }

        return $value;
    }

    private function stringValue(mixed $value, string $errorCode): string
    {
        if (! is_string($value) || $value === '') {
            $this->reject($errorCode);
        }

        return $value;
    }

    private function integerValue(mixed $value, string $errorCode): int
    {
        if (! is_int($value) || $value < 1) {
            $this->reject($errorCode);
        }

        return $value;
    }

    private function retryable(string $errorCode): never
    {
        throw new TradingEngineProjectionException(
            $errorCode,
            'The trading engine event is waiting for authoritative scan identity.',
            true,
        );
    }

    private function reject(string $errorCode): never
    {
        throw new TradingEngineProjectionException(
            $errorCode,
            'The trading engine event failed projection integrity checks.',
        );
    }
}
