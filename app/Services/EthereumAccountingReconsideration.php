<?php

namespace App\Services;

use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingReconsideration as Reconsideration;
use App\Models\EthereumAccountingReviewHead;
use App\Models\LivePosition;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class EthereumAccountingReconsideration
{
    public function __construct(private EthereumInventoryAccounting $accounting) {}

    public function summary(string $token): array
    {
        Gate::authorize('review-ethereum-accounting');
        $token = EthereumAccountingNumbers::address($token);
        $all = LivePosition::query()->where('chain', 'ethereum')->where('token_address', $token);
        $counts = $all->selectRaw("COUNT(*) AS total, SUM(CASE WHEN accounting_status = 'discrepancy' THEN 1 ELSE 0 END) AS discrepancy,
            SUM(CASE WHEN accounting_status != 'discrepancy' AND (accounting_status = 'verified' OR accounting_verified_at IS NOT NULL) THEN 1 ELSE 0 END) AS verified")
            ->selectSub($this->candidates($token)->selectRaw('COUNT(*)'), 'eligible')->first();
        $total = (int) $counts->total;
        $discrepancy = (int) $counts->discrepancy;
        $verified = (int) $counts->verified;
        $eligible = (int) $counts->eligible;

        return ['eligible' => $eligible, 'verified' => $verified, 'discrepancy' => $discrepancy,
            'other' => $total - $eligible - $verified - $discrepancy,
            'limit' => max(1, min(Reconsideration::MAX_BATCH, (int) config('services.ethereum.accounting.reconsideration_batch_size', 3)))];
    }

    public function prepare(string $token, int $reviewId, int $version, string $uuid): Reconsideration
    {
        Gate::authorize('review-ethereum-accounting');
        $actor = Auth::user();
        $token = EthereumAccountingNumbers::address($token);
        if (! Str::isUuid($uuid)) {
            throw new DomainException('Invalid reconsideration request identity.');
        }
        try {
            return DB::transaction(function () use ($token, $reviewId, $version, $uuid, $actor): Reconsideration {
                $head = EthereumAccountingReviewHead::locked($token);
                if ($existing = Reconsideration::query()->where('request_uuid', $uuid)->first()) {
                    return $this->sameRequest($existing, $token, $reviewId, $version);
                }
                $review = $head->currentReview();
                if (! $review || $review->id !== $reviewId || (int) $head->version !== $version
                    || ! in_array($review->status, ['approved', 'rejected'], true) || $review->policy_version !== EthereumAccountingEligibility::POLICY) {
                    throw new DomainException('The current review changed. Open the review again.');
                }
                $summary = $this->summary($token);
                $positions = $this->candidates($token)->with(['ethereumSwapAttempt', 'tradeOpportunity'])->orderBy('id')->limit($summary['limit'])->get();
                $snapshot = $positions->map(fn (LivePosition $position): array => ['id' => $position->id, 'state' => $position->accounting_status,
                    'version' => (string) $position->accounting_version,
                    'source' => EthereumInventoryAccounting::sourceFingerprint($position, $position->ethereumSwapAttempt, $position->tradeOpportunity)])->all();
                $request = new Reconsideration;
                $request->forceFill(['request_uuid' => $uuid, 'requested_by_user_id' => $actor->id,
                    'reviewer_identity' => ['id' => (string) $actor->id, 'name' => $actor->name, 'email' => $actor->email],
                    'chain' => 'ethereum', 'token_address' => $token, 'ethereum_accounting_eligibility_id' => $review->id,
                    'review_head_version' => (int) $head->version, 'review_decision' => $review->status, 'policy_version' => $review->policy_version,
                    'candidate_snapshot' => $snapshot, 'outcomes' => [], 'batch_limit' => $summary['limit'],
                    ...Reconsideration::counts([]), 'remaining_count' => max(0, $summary['eligible'] - count($snapshot)),
                    'status' => 'awaiting_confirmation', 'expires_at' => now()->addMinutes(20)->startOfSecond()]);
                $request->binding_digest = $request->digest();
                $request->save();

                return $request;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Reconsideration::query()->where('request_uuid', $uuid)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->sameRequest($existing, $token, $reviewId, $version);
        }
    }

    public function find(string $token, string $uuid): Reconsideration
    {
        Gate::authorize('review-ethereum-accounting');

        return Reconsideration::query()->where('request_uuid', $uuid)->where('requested_by_user_id', Auth::id())
            ->where('chain', 'ethereum')->where('token_address', EthereumAccountingNumbers::address($token))->firstOrFail();
    }

    public function execute(string $token, string $uuid): Reconsideration
    {
        Gate::authorize('review-ethereum-accounting');
        $request = $this->find($token, $uuid);
        $started = DB::transaction(function () use ($request): bool {
            $head = EthereumAccountingReviewHead::locked($request->token_address);
            $locked = Reconsideration::query()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser(Auth::user()->fresh())->authorize('review-ethereum-accounting');
            if ($locked->status !== 'awaiting_confirmation') {
                return false;
            }
            if (! hash_equals($locked->binding_digest, $locked->digest()) || ! $locked->expires_at->isFuture()) {
                $locked->forceFill(['status' => 'failed', 'finished_at' => now()])->save();

                return false;
            }
            if (! EthereumInventoryAccounting::matchesReview($head, $locked->reviewBinding())) {
                $this->finishRemaining($locked, 'stale_review', 'stale');

                return false;
            }
            $locked->forceFill(['status' => 'running', 'started_at' => now()])->save();

            return true;
        });
        if (! $started) {
            return $request->fresh();
        }
        foreach ($request->candidate_snapshot as $candidate) {
            try {
                Gate::forUser(Auth::user()->fresh())->authorize('review-ethereum-accounting');
                if ($request->review_decision === 'rejected') {
                    DB::transaction(function () use ($request, $candidate): void {
                        $head = EthereumAccountingReviewHead::locked($request->token_address);
                        $this->record($request->id, $candidate['id'], EthereumInventoryAccounting::matchesReview($head, $request->reviewBinding()) ? 'skipped_rejected' : 'stale_review');
                    });
                } else {
                    $this->accounting->process($candidate['id'], false, true,
                        ['review' => $request->reviewBinding(), 'candidate' => $candidate],
                        fn (string $result) => $this->record($request->id, $candidate['id'], $result));
                }
            } catch (Throwable) {
                DB::transaction(function () use ($request): void {
                    $locked = Reconsideration::query()->lockForUpdate()->findOrFail($request->id);
                    $this->finishRemaining($locked, 'accounting_failed', 'failed');
                });

                return $request->fresh();
            }
            if ($request->fresh()->stale_count > 0) {
                DB::transaction(function () use ($request): void {
                    $locked = Reconsideration::query()->lockForUpdate()->findOrFail($request->id);
                    $this->finishRemaining($locked, 'stale_review', 'stale');
                });

                return $request->fresh();
            }
        }
        DB::transaction(function () use ($request): void {
            $head = EthereumAccountingReviewHead::locked($request->token_address);
            $locked = Reconsideration::query()->lockForUpdate()->findOrFail($request->id);
            $locked->forceFill(['status' => EthereumInventoryAccounting::matchesReview($head, $locked->reviewBinding()) ? 'completed' : 'stale',
                'remaining_count' => $this->candidates($request->token_address)->count(), 'finished_at' => now()])->save();
        });

        return $request->fresh();
    }

    private function sameRequest(Reconsideration $request, string $token, int $review, int $version): Reconsideration
    {
        if ($request->requested_by_user_id !== Auth::id() || $request->token_address !== $token
            || $request->ethereum_accounting_eligibility_id !== $review || $request->review_head_version !== $version) {
            throw new DomainException('Reconsideration request identity is already bound to different content.');
        }

        return $request;
    }

    private function record(int $requestId, int $positionId, string $result): void
    {
        Gate::forUser(Auth::user()->fresh())->authorize('review-ethereum-accounting');
        $request = Reconsideration::query()->lockForUpdate()->findOrFail($requestId);
        if ($request->status !== 'running') {
            throw new DomainException('Reconsideration is no longer running.');
        }
        $code = match ($result) {
            'verified', 'provisional' => 'reconsidered',
            'unsupported', 'stale_review', 'stale_observation' => $result,
            default => in_array($result, Reconsideration::SKIPS, true) ? $result : 'accounting_failed',
        };
        $outcomes = [...$request->outcomes, ['position_id' => $positionId, 'code' => $code]];
        $request->forceFill(['outcomes' => $outcomes, ...Reconsideration::counts($outcomes)])->save();
    }

    private function finishRemaining(Reconsideration $request, string $code, string $status): void
    {
        $outcomes = $request->outcomes;
        $done = array_column($outcomes, 'position_id');
        foreach ($request->candidate_snapshot as $candidate) {
            if (! in_array($candidate['id'], $done, true)) {
                $outcomes[] = ['position_id' => $candidate['id'], 'code' => $code];
            }
        }
        $request->forceFill(['outcomes' => $outcomes, ...Reconsideration::counts($outcomes), 'status' => $status,
            'remaining_count' => $this->candidates($request->token_address)->count(), 'finished_at' => now()])->save();
    }

    private function candidates(string $token): Builder
    {
        return LivePosition::query()->where('chain', 'ethereum')->where('token_address', $token)->where('network', 'mainnet')
            ->whereIn('accounting_status', ['pending', 'unsupported'])->whereNull('accounting_verified_at')
            ->where(fn ($query) => $query->whereNull('accounting_lease_expires_at')->orWhere('accounting_lease_expires_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('accounting_next_attempt_at')->orWhere('accounting_next_attempt_at', '<=', now()))
            ->whereHas('ethereumSwapAttempt', function ($query): void {
                $query->where('status', 'confirmed')->whereNotNull('confirmed_at')->whereNull('failed_at')->whereNull('failure_reason');
                foreach (['user_id' => 'user_id', 'trade_opportunity_id' => 'trade_opportunity_id', 'connected_wallet_id' => 'connected_wallet_id',
                    'wallet_address' => 'wallet_address', 'buy_token' => 'token_address', 'transaction_hash' => 'entry_transaction_hash',
                    'block_number' => 'entry_block_number', 'confirmed_at' => 'entry_confirmed_at'] as $attempt => $position) {
                    $query->whereColumn('ethereum_swap_attempts.'.$attempt, 'live_positions.'.$position);
                }
            })->whereHas('tradeOpportunity', fn ($query) => $query->where('chain', 'ethereum')->where('execution_mode', 'live')
            ->where('entry_mode', 'confirm')->where('status', 'executed')->whereNotNull('executed_at')
            ->whereColumn('trade_opportunities.user_id', 'live_positions.user_id')->whereRaw('LOWER(trade_opportunities.address) = live_positions.token_address'));
    }
}
