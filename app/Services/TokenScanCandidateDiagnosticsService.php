<?php

namespace App\Services;

use App\Models\SystemActivity;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineClient;
use Throwable;

class TokenScanCandidateDiagnosticsService
{
    private const PER_PAGE = 20;

    public function __construct(private TradingEngineClient $client) {}

    /** @return array<string, mixed> */
    public function forActivity(SystemActivity $activity, User $user, int $page): array
    {
        $activity->loadMissing('tradingEngineScanRequest');
        $request = $activity->tradingEngineScanRequest;

        if ($request === null) {
            return $this->unavailable(
                'historical',
                'Candidate diagnostics were not projected for this historical Laravel scan.',
            );
        }

        if ($request->user_id !== $user->getKey()) {
            return $this->unavailable('forbidden', 'Candidate diagnostics are unavailable for this scan.');
        }

        if (! is_string($request->engine_scan_execution_id) || $request->engine_scan_execution_id === '') {
            return $this->unavailable(
                'pending',
                'The engine has not confirmed a candidate-diagnostics identifier for this scan yet.',
            );
        }

        try {
            $result = $this->client->tokenScanCandidates(
                $request->engine_scan_execution_id,
                $user->getKey(),
                $page,
                self::PER_PAGE,
            );
        } catch (Throwable) {
            return $this->unavailable(
                'unavailable',
                'Candidate diagnostics are temporarily unavailable. The aggregate scan result remains authoritative.',
            );
        }

        $candidates = collect($result['candidates'])
            ->map(fn (array $candidate): array => [
                ...$candidate,
                'outcome_label' => $this->outcomeLabel($candidate['state']),
                'reason_explanation' => $this->reasonExplanation($candidate['reason_code']),
            ])
            ->all();

        return [
            'status' => 'available',
            'message' => null,
            'candidates' => $candidates,
            'pagination' => $result['pagination'],
            'page_summary' => [
                'failed_rate_limited' => collect($candidates)->where('state', 'failed')->where('reason_code', 'PROVIDER_RATE_LIMITED')->count(),
                'skipped_filters' => collect($candidates)->where('state', 'skipped')->count(),
            ],
        ];
    }

    public function reasonExplanation(?string $code): ?string
    {
        return match ($code) {
            null => null,
            'PROVIDER_RATE_LIMITED' => 'This candidate could not be evaluated because the provider rate-limited its request.',
            'DISCOVERY_LIQUIDITY_BELOW_MINIMUM', 'LIQUIDITY_BELOW_MINIMUM' => 'Skipped because reported liquidity was below the configured eligibility minimum.',
            'HOLDERS_BELOW_MINIMUM' => 'Skipped because the available holder count was below the configured eligibility minimum.',
            'HOLDER_CONCENTRATION_FAILED' => 'Skipped because holder concentration exceeded the configured safety limit.',
            'MARKET_CAP_MISSING' => 'Skipped because a usable market capitalization was not reported.',
            'MARKET_CAP_OUTSIDE_RANGE', 'ENTRY_MARKET_CAP_OUTSIDE_RANGE' => 'Skipped because market capitalization was outside the configured eligibility range.',
            'MOMENTUM_DISCOVERY_FILTER_FAILED' => 'Skipped because the candidate did not meet the configured momentum discovery filters.',
            'PRE_BIRDEYE_SCORE_BELOW_MINIMUM' => 'Skipped because its pre-enrichment score was below the configured minimum.',
            'MOMENTUM_PROVIDER_BUDGET_EXHAUSTED' => 'Skipped because the platform-managed momentum enrichment budget was exhausted.',
            'CHASE_PROTECTION' => 'Skipped by configured chase protection.',
            'COLLAPSE_PROTECTION' => 'Skipped by configured collapse protection.',
            'DEX_REQUESTED_TOKEN_NOT_BASE' => 'Skipped because the requested token was not the base asset in the selected market pair.',
            'ETHEREUM_QUALIFICATION_FILTER_FAILED' => 'Skipped because it did not meet the configured Ethereum qualification filters.',
            default => 'The engine retained a safe diagnostic code for this candidate.',
        };
    }

    private function outcomeLabel(string $state): string
    {
        return match ($state) {
            'skipped' => 'Skipped',
            'qualified' => 'Qualified',
            'qualified_downstream_failed' => 'Qualified · opportunity failed',
            'opportunity_created' => 'Opportunity created',
            'opportunity_existing' => 'Existing opportunity',
            'failed' => 'Evaluation failed',
            default => 'Discovered',
        };
    }

    /** @return array<string, mixed> */
    private function unavailable(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'candidates' => [],
            'pagination' => null,
            'page_summary' => [
                'failed_rate_limited' => 0,
                'skipped_filters' => 0,
            ],
        ];
    }
}
