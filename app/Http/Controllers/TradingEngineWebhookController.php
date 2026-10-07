<?php

namespace App\Http\Controllers;

use App\Exceptions\TradingEngineWebhookException;
use App\Jobs\ProjectTradingEngineEvent;
use App\Services\TradingEngine\TradingEngineEventEnvelopeValidator;
use App\Services\TradingEngine\TradingEngineEventInbox;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEngineWebhookAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TradingEngineWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TradingEngineWebhookAuthenticator $authenticator,
        TradingEngineEventEnvelopeValidator $validator,
        TradingEngineEventInbox $inbox,
        TradingEngineOpportunityProjector $projector,
    ): JsonResponse {
        $authenticated = $authenticator->authenticate($request);
        $validated = $validator->validate($authenticated['raw_body'], $request);

        try {
            $result = $inbox->store(
                $validated['envelope'],
                $authenticated['raw_body'],
                $authenticated['raw_body_sha256'],
                $validated['handling_status'],
            );
        } catch (Throwable) {
            throw new TradingEngineWebhookException(
                'EVENT_INBOX_UNAVAILABLE',
                'The trading engine event inbox is temporarily unavailable.',
                503,
            );
        }

        if ($result['conflict']) {
            throw new TradingEngineWebhookException(
                'EVENT_ID_CONFLICT',
                'The trading engine event ID was already used for different content.',
                409,
            );
        }

        $eventType = $validated['envelope']['event_type'];
        $opportunityProjection = config('services.trading_engine.opportunity_projection_enabled', false) === true
            && in_array($eventType, [
                'opportunity.recorded.v1',
                'opportunity.evaluated.v1',
            ], true);
        $paperEntryProjection = config('services.trading_engine.paper_entry_integration_enabled', false) === true
            && $eventType === 'paper.entry.executed.v1';
        $paperLifecycleProjection = config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true
            && in_array($eventType, [
                'paper.position.recorded.v1',
                'paper.position.evaluated.v1',
                'paper.exit.requested.v1',
            ], true);

        if ($opportunityProjection || $paperEntryProjection || $paperLifecycleProjection) {
            try {
                ProjectTradingEngineEvent::dispatch($validated['envelope']['event_id']);
            } catch (Throwable) {
                $projector->markDispatchFailure($validated['envelope']['event_id']);
            }
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'event_id' => $validated['envelope']['event_id'],
            'handling_status' => $result['handling_status'],
        ], $result['duplicate'] ? 200 : 202);
    }
}
