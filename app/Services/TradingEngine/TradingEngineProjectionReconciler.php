<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TradingEngineProjectionReconciler
{
    private const CHUNK_SIZE = 200;

    private const MAX_RETAINED_ISSUES = 500;

    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    private int $issueCount = 0;

    /** @var array<int, array{code: string, event_id: string|null, projection_id: int|null, related_event_id: string|null}> */
    private array $issues = [];

    public function __construct(private TradingEngineProjectionTimestamp $timestamps) {}

    /**
     * @return array{
     *   counts: array<string, int>,
     *   checked: array{recorded_events: int, evaluated_events: int, opportunity_links: int, evaluations: int},
     *   issue_count: int,
     *   issues_truncated: bool,
     *   issues: array<int, array{code: string, event_id: string|null, projection_id: int|null, related_event_id: string|null}>
     * }
     */
    public function reconcile(): array
    {
        $this->issueCount = 0;
        $this->issues = [];

        $counts = [
            'projected_recorded_events' => $this->projectedEvents('opportunity.recorded.v1')->count(),
            'projected_evaluated_events' => $this->projectedEvents('opportunity.evaluated.v1')->count(),
            'opportunity_links' => TradingEngineOpportunityLink::query()->count(),
            'evaluation_projections' => TradingEngineOpportunityEvaluation::query()->count(),
        ];
        $checked = [
            'recorded_events' => 0,
            'evaluated_events' => 0,
            'opportunity_links' => 0,
            'evaluations' => 0,
        ];

        $this->auditRecordedEvents($checked);
        $this->auditEvaluatedEvents($checked);
        $this->auditOrphanLinks($checked);
        $this->auditOrphanEvaluations($checked);

        return [
            'counts' => $counts,
            'checked' => $checked,
            'issue_count' => $this->issueCount,
            'issues_truncated' => $this->issueCount > count($this->issues),
            'issues' => $this->issues,
        ];
    }

    /** @param array<string, int> $checked */
    private function auditRecordedEvents(array &$checked): void
    {
        $this->projectedEvents('opportunity.recorded.v1')
            ->chunkById(self::CHUNK_SIZE, function (Collection $events) use (&$checked): void {
                $eventIds = $events->pluck('event_id')->all();
                $links = TradingEngineOpportunityLink::query()
                    ->whereIn('recorded_event_id', $eventIds)
                    ->get()
                    ->keyBy('recorded_event_id');
                $opportunities = TradeOpportunity::query()
                    ->whereIn('id', $links->pluck('trade_opportunity_id')->all())
                    ->get()
                    ->keyBy('id');

                foreach ($events as $event) {
                    $checked['recorded_events']++;
                    $payload = $this->recordedPayload($event);

                    if ($payload === null) {
                        $this->issue('RECORDED_EVENT_PAYLOAD_INVALID', $event->event_id);

                        continue;
                    }

                    $link = $links->get($event->event_id);

                    if (! $link instanceof TradingEngineOpportunityLink) {
                        $conflictingLink = $this->conflictingLink($payload);

                        if ($conflictingLink instanceof TradingEngineOpportunityLink) {
                            $this->issue(
                                'OPPORTUNITY_LINK_IDENTITY_MISMATCH',
                                $event->event_id,
                                $conflictingLink->getKey(),
                                $conflictingLink->recorded_event_id,
                            );
                        } else {
                            $this->issue('OPPORTUNITY_LINK_MISSING', $event->event_id);
                        }

                        continue;
                    }

                    $opportunity = $opportunities->get($link->trade_opportunity_id);

                    if (! $opportunity instanceof TradeOpportunity) {
                        $this->issue('LOCAL_OPPORTUNITY_MISSING', $event->event_id, $link->getKey());

                        continue;
                    }

                    if (! $this->recordedEventContractMatches($event, $payload)) {
                        $this->issue('RECORDED_EVENT_CONTRACT_MISMATCH', $event->event_id, $link->getKey());
                    }

                    if (! $this->linkMatches($link, $event, $payload, $opportunity)) {
                        $this->issue('OPPORTUNITY_LINK_IDENTITY_MISMATCH', $event->event_id, $link->getKey());
                    }

                    if (! $this->localOpportunityMatches($opportunity, $payload)) {
                        $this->issue('LOCAL_OPPORTUNITY_IDENTITY_MISMATCH', $event->event_id, $link->getKey());
                    }
                }
            });
    }

    /** @param array<string, int> $checked */
    private function auditEvaluatedEvents(array &$checked): void
    {
        $this->projectedEvents('opportunity.evaluated.v1')
            ->chunkById(self::CHUNK_SIZE, function (Collection $events) use (&$checked): void {
                $eventIds = $events->pluck('event_id')->all();
                $causationIds = $events->pluck('causation_id')->unique()->all();
                $evaluations = TradingEngineOpportunityEvaluation::query()
                    ->whereIn('evaluation_event_id', $eventIds)
                    ->get()
                    ->keyBy('evaluation_event_id');
                $causalEvents = TradingEngineEvent::query()
                    ->whereIn('event_id', $causationIds)
                    ->get()
                    ->keyBy('event_id');
                $links = TradingEngineOpportunityLink::query()
                    ->whereIn('recorded_event_id', $causationIds)
                    ->get()
                    ->keyBy('recorded_event_id');

                foreach ($events as $event) {
                    $checked['evaluated_events']++;
                    $payload = $this->evaluatedPayload($event);
                    $evaluation = $evaluations->get($event->event_id);
                    $causalEvent = $causalEvents->get($event->causation_id);
                    $link = $links->get($event->causation_id);

                    if ($payload === null) {
                        $this->issue('EVALUATION_EVENT_PAYLOAD_INVALID', $event->event_id);
                    }

                    if (! $evaluation instanceof TradingEngineOpportunityEvaluation) {
                        $conflictingEvaluation = $payload === null
                            ? null
                            : $this->conflictingEvaluation($payload);

                        if ($conflictingEvaluation instanceof TradingEngineOpportunityEvaluation) {
                            $this->issue(
                                'EVALUATION_IDENTITY_MISMATCH',
                                $event->event_id,
                                $conflictingEvaluation->getKey(),
                                $conflictingEvaluation->evaluation_event_id,
                            );
                        } else {
                            $this->issue('EVALUATION_PROJECTION_MISSING', $event->event_id);
                        }
                    } elseif ($payload !== null) {
                        $this->auditEvaluationProjection($event, $evaluation, $payload);
                    }

                    if (! $causalEvent instanceof TradingEngineEvent
                        || $causalEvent->event_type !== 'opportunity.recorded.v1'
                        || $causalEvent->handling_status !== TradingEngineEvent::STATUS_PROJECTED
                        || ! $link instanceof TradingEngineOpportunityLink
                        || ($payload !== null && $link->engine_opportunity_id !== $payload['opportunity_id'])
                        || ($evaluation instanceof TradingEngineOpportunityEvaluation
                            && ($evaluation->recorded_event_id !== $event->causation_id
                                || $evaluation->opportunity_link_id !== $link?->getKey()
                                || $evaluation->trade_opportunity_id !== $link?->trade_opportunity_id
                                || $evaluation->engine_opportunity_id !== $link?->engine_opportunity_id))) {
                        $this->issue(
                            'BROKEN_CAUSAL_CHAIN',
                            $event->event_id,
                            $evaluation?->getKey(),
                            $event->causation_id,
                        );
                    }
                }
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function auditEvaluationProjection(
        TradingEngineEvent $event,
        TradingEngineOpportunityEvaluation $evaluation,
        array $payload,
    ): void {
        $policy = $payload['policy'];
        $source = $payload['source'];
        $projectionId = $evaluation->getKey();

        if ($event->aggregate_id !== $payload['evaluation_id']
            || $event->causation_id !== $evaluation->recorded_event_id
            || $event->idempotency_key !== 'evaluation:'.$payload['opportunity_id'].':'.$policy['key'].':'.$policy['version']) {
            $this->issue('EVALUATION_EVENT_CONTRACT_MISMATCH', $event->event_id, $projectionId);
        }

        if ($evaluation->evaluation_id !== $payload['evaluation_id']
            || $evaluation->evaluation_event_id !== $event->event_id
            || $evaluation->engine_opportunity_id !== $payload['opportunity_id']) {
            $this->issue('EVALUATION_IDENTITY_MISMATCH', $event->event_id, $projectionId);
        }

        if ($evaluation->policy_key !== $policy['key']
            || $evaluation->policy_version !== $policy['version']
            || $evaluation->algorithm_key !== $policy['algorithm_key']
            || $evaluation->algorithm_version !== $policy['algorithm_version']
            || $evaluation->policy_definition_sha256 !== $policy['definition_sha256']) {
            $this->issue('EVALUATION_POLICY_MISMATCH', $event->event_id, $projectionId);
        }

        if ($evaluation->source_request_sha256 !== $source['request_sha256']
            || $evaluation->evaluation_input_sha256 !== $source['evaluation_input_sha256']) {
            $this->issue('EVALUATION_SOURCE_HASH_MISMATCH', $event->event_id, $projectionId);
        }

        if ($evaluation->result_sha256 !== $payload['result_sha256']
            || $evaluation->outcome !== $payload['outcome']
            || $evaluation->reason_codes !== $payload['reason_codes']
            || $evaluation->advisory_codes !== $payload['advisory_codes']
            || $evaluation->evidence !== $payload['evidence']) {
            $this->issue('EVALUATION_RESULT_MISMATCH', $event->event_id, $projectionId);
        }

        if ($evaluation->correlation_id !== $event->correlation_id
            || $evaluation->traceparent !== $event->traceparent) {
            $this->issue('EVALUATION_CORRELATION_MISMATCH', $event->event_id, $projectionId);
        }

        if (! $this->timestamps->representsSameStoredInstant($evaluation->evaluated_at, $event->occurred_at)
            || ! $this->timestamps->representsSameStoredInstant($evaluation->event_received_at, $event->received_at)) {
            $this->issue('EVALUATION_TIMESTAMP_MISMATCH', $event->event_id, $projectionId);
        }
    }

    /** @param array<string, int> $checked */
    private function auditOrphanLinks(array &$checked): void
    {
        $checked['opportunity_links'] = TradingEngineOpportunityLink::query()->count();

        TradingEngineOpportunityLink::query()
            ->select('trading_engine_opportunity_links.*')
            ->leftJoin(
                'trading_engine_event_inbox',
                'trading_engine_event_inbox.event_id',
                '=',
                'trading_engine_opportunity_links.recorded_event_id',
            )
            ->whereNull('trading_engine_event_inbox.id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $links) use (&$checked): void {
                foreach ($links as $link) {
                    $this->issue(
                        'ORPHAN_OPPORTUNITY_LINK',
                        null,
                        $link->getKey(),
                        $link->recorded_event_id,
                    );
                }
            }, 'trading_engine_opportunity_links.id', 'id');
    }

    /** @param array<string, int> $checked */
    private function auditOrphanEvaluations(array &$checked): void
    {
        $checked['evaluations'] = TradingEngineOpportunityEvaluation::query()->count();

        TradingEngineOpportunityEvaluation::query()
            ->select('trading_engine_opportunity_evaluations.*')
            ->leftJoin(
                'trading_engine_event_inbox',
                'trading_engine_event_inbox.event_id',
                '=',
                'trading_engine_opportunity_evaluations.evaluation_event_id',
            )
            ->whereNull('trading_engine_event_inbox.id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $evaluations) use (&$checked): void {
                foreach ($evaluations as $evaluation) {
                    $this->issue(
                        'ORPHAN_EVALUATION',
                        null,
                        $evaluation->getKey(),
                        $evaluation->evaluation_event_id,
                    );
                }
            }, 'trading_engine_opportunity_evaluations.id', 'id');
    }

    /** @return Builder<TradingEngineEvent> */
    private function projectedEvents(string $eventType)
    {
        return TradingEngineEvent::query()
            ->where('event_type', $eventType)
            ->where('handling_status', TradingEngineEvent::STATUS_PROJECTED);
    }

    /** @return array<string, mixed>|null */
    private function recordedPayload(TradingEngineEvent $event): ?array
    {
        $payload = $event->payload;

        if (! is_array($payload)
            || ! is_array($payload['source'] ?? null)
            || ! is_array($payload['subject'] ?? null)
            || ! is_array($payload['network'] ?? null)
            || ! is_array($payload['asset'] ?? null)) {
            return null;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function evaluatedPayload(TradingEngineEvent $event): ?array
    {
        $payload = $event->payload;

        if (! is_array($payload)
            || ! is_array($payload['policy'] ?? null)
            || ! is_array($payload['source'] ?? null)
            || ! is_array($payload['reason_codes'] ?? null)
            || ! is_array($payload['advisory_codes'] ?? null)
            || ! is_array($payload['evidence'] ?? null)) {
            return null;
        }

        $requiredStrings = [
            $payload['evaluation_id'] ?? null,
            $payload['opportunity_id'] ?? null,
            $payload['outcome'] ?? null,
            $payload['result_sha256'] ?? null,
            $payload['policy']['key'] ?? null,
            $payload['policy']['algorithm_key'] ?? null,
            $payload['policy']['definition_sha256'] ?? null,
            $payload['source']['request_sha256'] ?? null,
            $payload['source']['evaluation_input_sha256'] ?? null,
        ];

        if (array_filter($requiredStrings, fn (mixed $value): bool => ! is_string($value) || $value === '') !== []
            || ! is_int($payload['policy']['version'] ?? null)
            || ! is_int($payload['policy']['algorithm_version'] ?? null)
            || ! array_is_list($payload['reason_codes'])
            || ! array_is_list($payload['advisory_codes'])) {
            return null;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function recordedEventContractMatches(TradingEngineEvent $event, array $payload): bool
    {
        $source = $payload['source'];

        return is_string($source['opportunity_id'] ?? null)
            && is_string($payload['opportunity_id'] ?? null)
            && is_string($payload['operation_id'] ?? null)
            && $event->aggregate_id === $payload['opportunity_id']
            && $event->causation_id === $payload['operation_id']
            && $event->idempotency_key === 'opportunity:record:laravel:'.$source['opportunity_id'].':v1';
    }

    /** @param array<string, mixed> $payload */
    private function linkMatches(
        TradingEngineOpportunityLink $link,
        TradingEngineEvent $event,
        array $payload,
        TradeOpportunity $opportunity,
    ): bool {
        $source = $payload['source'];
        $subject = $payload['subject'];
        $network = $payload['network'];
        $asset = $payload['asset'];

        return $link->recorded_event_id === $event->event_id
            && $link->engine_opportunity_id === ($payload['opportunity_id'] ?? null)
            && (string) $link->trade_opportunity_id === ($source['opportunity_id'] ?? null)
            && $link->trade_opportunity_id === $opportunity->getKey()
            && (string) $link->user_id === ($subject['control_plane_user_id'] ?? null)
            && $link->discovery_key === ($source['discovery_key'] ?? null)
            && $link->scanner === ($source['scanner'] ?? null)
            && $link->network_id === ($network['id'] ?? null)
            && $link->asset_address === ($asset['address'] ?? null)
            && $this->timestamps->representsSameStoredInstant($link->recorded_at, $event->occurred_at);
    }

    /** @param array<string, mixed> $payload */
    private function localOpportunityMatches(TradeOpportunity $opportunity, array $payload): bool
    {
        $source = $payload['source'];
        $subject = $payload['subject'];
        $network = $payload['network'];
        $asset = $payload['asset'];
        $expectedNetwork = match ($opportunity->chain) {
            Chain::Solana => self::SOLANA_MAINNET_ID,
            Chain::Ethereum => self::ETHEREUM_MAINNET_ID,
        };

        return (string) $opportunity->getKey() === ($source['opportunity_id'] ?? null)
            && $opportunity->user_id !== null
            && (string) $opportunity->user_id === ($subject['control_plane_user_id'] ?? null)
            && $opportunity->discovery_key === ($source['discovery_key'] ?? null)
            && $opportunity->scanner === ($source['scanner'] ?? null)
            && $expectedNetwork === ($network['id'] ?? null)
            && $opportunity->address === ($asset['address'] ?? null);
    }

    /** @param array<string, mixed> $payload */
    private function conflictingLink(array $payload): ?TradingEngineOpportunityLink
    {
        $query = TradingEngineOpportunityLink::query()
            ->where('engine_opportunity_id', $payload['opportunity_id'] ?? '');
        $localOpportunityId = $payload['source']['opportunity_id'] ?? null;

        if (is_string($localOpportunityId) && ctype_digit($localOpportunityId)) {
            $query->orWhere('trade_opportunity_id', $localOpportunityId);
        }

        return $query->orderBy('id')->first();
    }

    /** @param array<string, mixed> $payload */
    private function conflictingEvaluation(array $payload): ?TradingEngineOpportunityEvaluation
    {
        return TradingEngineOpportunityEvaluation::query()
            ->where('evaluation_id', $payload['evaluation_id'])
            ->orWhere(function ($query) use ($payload): void {
                $query->where('engine_opportunity_id', $payload['opportunity_id'])
                    ->where('policy_key', $payload['policy']['key'])
                    ->where('policy_version', $payload['policy']['version']);
            })
            ->orderBy('id')
            ->first();
    }

    private function issue(
        string $code,
        ?string $eventId,
        ?int $projectionId = null,
        ?string $relatedEventId = null,
    ): void {
        $this->issueCount++;

        if (count($this->issues) >= self::MAX_RETAINED_ISSUES) {
            return;
        }

        $this->issues[] = [
            'code' => $code,
            'event_id' => $eventId,
            'projection_id' => $projectionId,
            'related_event_id' => $relatedEventId,
        ];
    }
}
