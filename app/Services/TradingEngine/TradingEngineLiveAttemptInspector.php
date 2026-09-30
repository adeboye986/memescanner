<?php

namespace App\Services\TradingEngine;

use App\Models\EthereumSwapAttempt;
use App\Models\SolanaSwapAttempt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TradingEngineLiveAttemptInspector
{
    public const CLASSIFICATION_ACTIVE = 'active';

    public const CLASSIFICATION_ATTENTION = 'attention_required';

    public const CLASSIFICATION_RECONCILIATION = 'reconciliation_pending';

    public const CLASSIFICATION_SAFE_TO_CLEAN = 'safe_to_cleanup';

    public const CLASSIFICATION_TERMINAL = 'terminal';

    public const CLASSIFICATION_UNCERTAIN_BROADCAST = 'uncertain_broadcast';

    private const ABANDONED_RESERVATION_SECONDS = 300;

    private const TERMINAL_STATUSES = ['cancelled', 'confirmed', 'failed', 'released'];

    /**
     * @return Collection<int, array{
     *     chain: string, attempt_id: int, opportunity_id: int|null, status: string,
     *     transaction_evidence: string|null, preparation_origin: string|null,
     *     age_seconds: int, expires_at: string|null, reconciliation_eligible: bool,
     *     classification: string, reason: string
     * }>
     */
    public function inspect(string $chain = 'all', int $limitPerChain = 100): Collection
    {
        $limitPerChain = max(1, min(500, $limitPerChain));
        $attempts = collect();

        if (in_array($chain, ['all', 'ethereum'], true)) {
            $attempts = $attempts->concat($this->ethereumAttempts($limitPerChain));
        }

        if (in_array($chain, ['all', 'solana'], true)) {
            $attempts = $attempts->concat($this->solanaAttempts($limitPerChain));
        }

        return $attempts
            ->reject(fn (array $attempt): bool => $attempt['classification'] === self::CLASSIFICATION_TERMINAL)
            ->sortBy([
                ['chain', 'asc'],
                ['attempt_id', 'asc'],
            ])
            ->values();
    }

    /** @return array<string, int|string|bool|null> */
    public function classifyEthereum(EthereumSwapAttempt $attempt): array
    {
        return $this->classify(
            attempt: $attempt,
            chain: 'ethereum',
            transactionEvidence: $attempt->transaction_hash,
            reconciliationEligible: $attempt->status === 'submitted' && $attempt->transaction_hash !== null,
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function classifySolana(SolanaSwapAttempt $attempt): array
    {
        return $this->classify(
            attempt: $attempt,
            chain: 'solana',
            transactionEvidence: $attempt->transaction_signature,
            reconciliationEligible: in_array($attempt->status, ['submitting', 'submitted'], true)
                && $attempt->transaction_signature !== null,
        );
    }

    /** @return Collection<int, array<string, int|string|bool|null>> */
    private function ethereumAttempts(int $limit): Collection
    {
        return EthereumSwapAttempt::query()
            ->select($this->columns('transaction_hash'))
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->where(fn ($query) => $query->where('status', '!=', 'expired')->orWhereNotNull('signing_armed_at'))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (EthereumSwapAttempt $attempt): array => $this->classifyEthereum($attempt));
    }

    /** @return Collection<int, array<string, int|string|bool|null>> */
    private function solanaAttempts(int $limit): Collection
    {
        return SolanaSwapAttempt::query()
            ->select($this->columns('transaction_signature'))
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->where(fn ($query) => $query->where('status', '!=', 'expired')->orWhereNotNull('signing_armed_at'))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (SolanaSwapAttempt $attempt): array => $this->classifySolana($attempt));
    }

    /** @return list<string> */
    private function columns(string $transactionEvidenceColumn): array
    {
        return [
            'id', 'trade_opportunity_id', 'status', $transactionEvidenceColumn, 'preparation_origin',
            'preparation_expires_at', 'signing_requested_at', 'signing_armed_at', 'expires_at',
            'submitted_at', 'created_at', 'updated_at',
        ];
    }

    /** @return array<string, int|string|bool|null> */
    private function classify(
        Model $attempt,
        string $chain,
        ?string $transactionEvidence,
        bool $reconciliationEligible,
    ): array {
        $status = (string) $attempt->getAttribute('status');
        $ageSeconds = $this->ageSeconds($attempt);
        [$classification, $reason] = $this->classification(
            $attempt,
            $status,
            $transactionEvidence,
            $reconciliationEligible,
            $ageSeconds,
        );
        $expiresAt = $status === 'preparing'
            ? $attempt->getAttribute('preparation_expires_at')
            : $attempt->getAttribute('expires_at');

        return [
            'chain' => $chain,
            'attempt_id' => (int) $attempt->getKey(),
            'opportunity_id' => $attempt->getAttribute('trade_opportunity_id') === null
                ? null
                : (int) $attempt->getAttribute('trade_opportunity_id'),
            'status' => $status,
            'transaction_evidence' => $transactionEvidence,
            'preparation_origin' => $attempt->getAttribute('preparation_origin'),
            'age_seconds' => $ageSeconds,
            'expires_at' => $expiresAt instanceof CarbonInterface ? $expiresAt->toIso8601String() : null,
            'reconciliation_eligible' => $reconciliationEligible,
            'classification' => $classification,
            'reason' => $reason,
        ];
    }

    /** @return array{string, string} */
    private function classification(
        Model $attempt,
        string $status,
        ?string $transactionEvidence,
        bool $reconciliationEligible,
        int $ageSeconds,
    ): array {
        if (in_array($status, self::TERMINAL_STATUSES, true)
            || ($status === 'expired' && $attempt->getAttribute('signing_armed_at') === null)) {
            return [self::CLASSIFICATION_TERMINAL, 'TERMINAL_STATE'];
        }

        if ($reconciliationEligible) {
            if ($ageSeconds >= $this->attentionAfterSeconds()) {
                return [self::CLASSIFICATION_ATTENTION, 'RECEIPT_UNRESOLVED_PAST_THRESHOLD'];
            }

            return [self::CLASSIFICATION_RECONCILIATION, 'RECEIPT_RECONCILIATION_PENDING'];
        }

        if ($transactionEvidence !== null) {
            return [self::CLASSIFICATION_UNCERTAIN_BROADCAST, 'TRANSACTION_EVIDENCE_OUTSIDE_RECONCILABLE_STATE'];
        }

        if ($attempt->getAttribute('signing_armed_at') !== null) {
            return [
                self::CLASSIFICATION_UNCERTAIN_BROADCAST,
                $ageSeconds >= $this->attentionAfterSeconds()
                    ? 'SIGNING_ARMED_OUTCOME_UNRESOLVED_PAST_THRESHOLD'
                    : 'SIGNING_ARMED_OUTCOME_UNRESOLVED',
            ];
        }

        if ($status === 'submitted' || $status === 'submitting') {
            return [self::CLASSIFICATION_ATTENTION, 'SUBMITTED_WITHOUT_TRANSACTION_EVIDENCE'];
        }

        if ($status === 'preparing' && $this->isExpired($attempt->getAttribute('preparation_expires_at'))) {
            return [self::CLASSIFICATION_SAFE_TO_CLEAN, 'PREPARATION_LEASE_EXPIRED'];
        }

        if ($status === 'prepared' && $this->isExpired($attempt->getAttribute('expires_at'))) {
            return [self::CLASSIFICATION_SAFE_TO_CLEAN, 'PREPARED_ATTEMPT_EXPIRED_WITHOUT_BROADCAST_EVIDENCE'];
        }

        if ($status === 'reserved' && $ageSeconds >= self::ABANDONED_RESERVATION_SECONDS) {
            return [self::CLASSIFICATION_SAFE_TO_CLEAN, 'RESERVATION_ABANDONED'];
        }

        if (in_array($status, ['reserved', 'preparing', 'prepared'], true)) {
            return [self::CLASSIFICATION_ACTIVE, 'ACTIVE_HANDOFF'];
        }

        return [self::CLASSIFICATION_ATTENTION, 'UNRECOGNIZED_NONTERMINAL_STATE'];
    }

    private function ageSeconds(Model $attempt): int
    {
        $reference = $attempt->getAttribute('submitted_at')
            ?? $attempt->getAttribute('signing_armed_at')
            ?? $attempt->getAttribute('signing_requested_at')
            ?? $attempt->getAttribute('updated_at')
            ?? $attempt->getAttribute('created_at');

        return $reference instanceof CarbonInterface
            ? max(0, (int) $reference->diffInSeconds(now()))
            : 0;
    }

    private function isExpired(mixed $value): bool
    {
        return ! $value instanceof CarbonInterface || $value->lte(now());
    }

    private function attentionAfterSeconds(): int
    {
        return max(60, (int) config('services.trading_engine.live_attempt_attention_after_seconds', 900));
    }
}
