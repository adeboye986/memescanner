<?php

namespace App\Services;

use App\Exceptions\EthereumAccountingException;
use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingEvidence;
use App\Models\EthereumSwapAttempt;
use App\Models\LivePosition;
use App\Models\TradeOpportunity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EthereumInventoryAccounting
{
    public const POLICY = 'ethereum-inventory-v1';

    public function __construct(private EthereumAccountingRpc $rpc, private EthereumTransferExtractor $extractor,
        private EthereumAccountingFinality $finality, private LivePositionService $positions) {}

    /** All RPC happens between the claim and apply transactions. */
    public function process(int $id, bool $audit = false, bool $retryUnsupported = false): string
    {
        $claim = DB::transaction(function () use ($id, $audit, $retryUnsupported): ?array {
            [$position, $attempt, $opportunity] = $this->lock($id);
            if (! $position || $position->chain->value !== 'ethereum'
                || ! in_array($position->accounting_status, [...['pending', 'provisional'], ...($audit ? ['verified'] : []), ...($retryUnsupported ? ['unsupported'] : [])], true)
                || ($position->accounting_lease_expires_at && $position->accounting_lease_expires_at->isFuture())
                || (! $audit && ! $retryUnsupported && $position->accounting_next_attempt_at && $position->accounting_next_attempt_at->isFuture())) {
                return null;
            }
            $position->accounting_lease_token = Str::random(48);
            $position->accounting_lease_expires_at = now()->addSeconds(max(30, min(600, (int) config('services.ethereum.accounting.lease_seconds', 180))));
            $position->accounting_last_attempt_at = now();
            $position->accounting_version++;
            $position->save();
            $identityError = false;
            try {
                if (! $attempt || ! $opportunity) {
                    throw new DomainException('Missing execution linkage.');
                }
                $this->positions->ensureConfirmedEthereum($opportunity, $attempt);
            } catch (DomainException) {
                $identityError = true;
            }

            return ['position' => $position, 'attempt' => $attempt, 'opportunity' => $opportunity,
                'fingerprint' => $this->fingerprint($attempt, $opportunity), 'identity_error' => $identityError,
                'eligibility' => $this->eligibility($position), 'decision' => $this->decision($position)];
        });
        if ($claim === null) {
            return 'skipped';
        }
        $facts = [];
        try {
            if ($claim['identity_error']) {
                throw new EthereumAccountingException('execution_identity', 'discrepancy');
            }
            $facts = $this->gather($claim, $facts);
            $state = $facts['finality']['satisfied'] ? 'verified' : 'provisional';
            $reason = $facts['finality']['reason'];
        } catch (EthereumAccountingException $exception) {
            $state = $exception->state;
            $reason = $exception->reason;
        }

        return DB::transaction(function () use ($claim, $facts, $state, $reason): string {
            [$position, $attempt, $opportunity] = $this->lock($claim['position']->id);
            if (! $position || $position->accounting_lease_token !== $claim['decision']['lease']) {
                return 'stale_observation';
            }
            if (! $position->accounting_lease_expires_at || $position->accounting_lease_expires_at->isPast()
                || $this->decision($position) !== $claim['decision']
                || $this->fingerprint($attempt, $opportunity) !== $claim['fingerprint']
                || $this->eligibility($position)?->id !== $claim['eligibility']?->id) {
                // Only release our lease; preserve the newer decision and its reason/evidence.
                $this->release($position);

                return 'stale_observation';
            }
            try {
                if (! $attempt || ! $opportunity) {
                    throw new DomainException('Missing linkage.');
                }
                $this->positions->ensureConfirmedEthereum($opportunity, $attempt);
            } catch (DomainException) {
                $state = 'discrepancy';
                $reason = 'execution_identity';
            }
            $accepted = $position->accepted_ethereum_evidence_id ? EthereumAccountingEvidence::query()->findOrFail($position->accepted_ethereum_evidence_id) : null;
            if ($accepted && $accepted->state === 'provisional' && isset($facts['metadata'], $facts['quantity'])
                && $this->executionCore($accepted->evidence) !== $this->executionCore($facts)) {
                $state = 'discrepancy';
                $reason = 'provisional_evidence_conflict';
            }
            if ($position->accounting_verified_at) {
                if ($state === 'unsupported') {
                    $state = 'discrepancy';
                }
                if (in_array($state, ['verified', 'provisional'], true) && $accepted && $this->core($accepted->evidence) !== $this->core($facts)) {
                    $state = 'discrepancy';
                    $reason = 'verified_evidence_conflict';
                }
            }
            if ($state === 'pending') {
                if ($position->accounting_status === 'unsupported') {
                    $position->accounting_status = 'pending';
                }
                $position->accounting_reason_code = $reason;
                $this->retry($position);
                $this->release($position);

                return 'retryable';
            }
            if ($position->accounting_verified_at && in_array($state, ['verified', 'provisional'], true)) {
                $position->accounting_reason_code = $state === 'provisional' ? $reason : null;
                $this->release($position);

                return 'verified';
            }
            $payload = ['transaction_hash' => $position->entry_transaction_hash, 'token' => $position->token_address,
                'wallet' => $position->wallet_address, 'chain_id' => '1', 'eligibility_review_id' => $claim['eligibility']?->id,
                'eligibility_version' => EthereumAccountingEligibility::POLICY, 'extraction_version' => EthereumTransferExtractor::VERSION,
                'supersedes_evidence_id' => $accepted?->id, ...$facts];
            $digest = self::evidenceDigest($state, $reason, $payload);
            $evidence = EthereumAccountingEvidence::query()->firstOrCreate(['live_position_id' => $position->id, 'digest' => $digest], [
                'ethereum_swap_attempt_id' => $position->ethereum_swap_attempt_id,
                'ethereum_accounting_eligibility_id' => $claim['eligibility']?->id, 'state' => $state, 'reason_code' => $reason,
                'policy_version' => self::POLICY, 'evidence' => $payload]);
            $position->accounting_status = $state;
            $position->accounting_reason_code = $reason;
            if (! $position->accounting_verified_at) {
                $position->accounting_policy_version = self::POLICY;
                $position->accepted_ethereum_evidence_id = $evidence->id;
            }
            if ($state === 'verified') {
                $position->acquired_raw_amount = $facts['quantity']['net'];
                $position->token_decimals = $facts['metadata']['decimals'];
                $position->accounting_verified_at = now();
                $position->accounting_retry_count = 0;
                $position->accounting_next_attempt_at = null;
            } elseif ($state === 'provisional') {
                $this->retry($position);
            } else {
                $position->accounting_next_attempt_at = null;
            }
            $this->release($position);

            return $state;
        });
    }

    /** @param array<string, mixed> $claim
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function gather(array $claim, array &$facts): array
    {
        $position = $claim['position'];
        $review = $claim['eligibility'];
        if (! $review || $review->status !== 'approved' || $review->policy_version !== EthereumAccountingEligibility::POLICY
            || ! $review->reviewed_at || $review->reviewed_at->isFuture()
            || preg_match('/^[a-f0-9]{64}$/D', $review->code_sha256 ?? '') !== 1) {
            throw new EthereumAccountingException('token_semantics_unreviewed', 'unsupported');
        }
        $facts = ['token' => $position->token_address, 'wallet' => $position->wallet_address, 'chain_id' => '1',
            'eligibility_review_id' => $review->id, 'eligibility_version' => $review->policy_version,
            'extraction_version' => EthereumTransferExtractor::VERSION];
        $this->rpc->assertNetwork();
        $receipt = $this->rpc->receipt($position->entry_transaction_hash);
        if ($receipt === null) {
            throw new EthereumAccountingException('receipt_unavailable');
        }
        $facts['receipt'] = array_diff_key($receipt, ['logs' => true]);
        if ($receipt['status'] !== '1') {
            throw new EthereumAccountingException('receipt_reverted', 'discrepancy');
        }
        $accepted = $position->accepted_ethereum_evidence_id ? EthereumAccountingEvidence::query()->findOrFail($position->accepted_ethereum_evidence_id) : null;
        if ($accepted && isset($accepted->evidence['receipt'])
            && self::canonicalEvidence(array_intersect_key($accepted->evidence['receipt'], array_flip(['block_hash', 'block_number', 'transaction_index'])))
                !== self::canonicalEvidence(array_intersect_key($facts['receipt'], array_flip(['block_hash', 'block_number', 'transaction_index'])))) {
            throw new EthereumAccountingException('reorg_identity_changed', 'discrepancy');
        }
        if (! isset($accepted?->evidence['receipt']) && $receipt['block_number'] !== $position->entry_block_number) {
            throw new EthereumAccountingException('initial_block_changed', 'discrepancy');
        }
        foreach (['gas_used' => 'gas_used', 'effective_gas_price_wei' => 'effective_gas_price_wei', 'network_fee_wei' => 'actual_network_fee_wei'] as $field => $stored) {
            if ($receipt[$field] !== $claim['attempt']->{$stored}) {
                throw new EthereumAccountingException('retained_receipt_conflict', 'discrepancy');
            }
        }
        $block = $this->rpc->block($receipt['block_tag']);
        if ($block['hash'] !== $receipt['block_hash']) {
            throw new EthereumAccountingException('noncanonical_receipt', 'discrepancy');
        }
        $facts['block'] = $block;
        $tx = $this->rpc->transaction($position->entry_transaction_hash);
        foreach (['block_hash', 'block_number', 'transaction_index'] as $key) {
            if ($tx[$key] !== $receipt[$key]) {
                throw new EthereumAccountingException('transaction_receipt_conflict', 'discrepancy');
            }
        }
        $prepared = $claim['attempt']->transaction_payload;
        if ($tx['from'] !== $position->wallet_address || $tx['to'] !== strtolower($prepared['to'])
            || $tx['value'] !== $claim['attempt']->sell_amount_wei || $tx['input'] !== strtolower($prepared['data'])) {
            throw new EthereumAccountingException('transaction_preparation_conflict', 'discrepancy');
        }
        $facts['transaction'] = [...array_diff_key($tx, ['input' => true]), 'calldata_sha256' => hash('sha256', hex2bin(substr($tx['input'], 2)))];
        $code = $this->rpc->codeHash($position->token_address, $receipt['block_hash']);
        $facts['code_sha256'] = $code;
        if ($code !== $review->code_sha256) {
            throw new EthereumAccountingException('reviewed_code_changed', 'unsupported');
        }
        $facts['quantity'] = $this->extractor->extract($receipt['logs'], $position->token_address, $position->wallet_address);
        $facts['metadata'] = ['decimals' => $this->rpc->decimals($position->token_address, $receipt['block_hash']),
            'source' => 'eth_call:decimals:blockHash', 'block_hash' => $receipt['block_hash'], 'block_number' => $receipt['block_number'],
            'observed_at' => now()->toIso8601String()];
        $facts['finality'] = $this->finality->observe($receipt);
        if ($facts['finality']['satisfied']) {
            $again = $this->rpc->receipt($position->entry_transaction_hash);
            if ($again === null) {
                throw new EthereumAccountingException('receipt_unavailable');
            }
            if (self::canonicalEvidence($again) !== self::canonicalEvidence($receipt)
                || self::canonicalEvidence($this->rpc->block($receipt['block_tag'])) !== self::canonicalEvidence($block)) {
                throw new EthereumAccountingException('evidence_changed_before_verification', 'discrepancy');
            }
        }

        return $facts;
    }

    private function eligibility(LivePosition $position): ?EthereumAccountingEligibility
    {
        return EthereumAccountingEligibility::query()->where('chain', 'ethereum')->where('token_address', $position->token_address)->latest('id')->first();
    }

    /** Preserve the same opportunity → attempt → position lock ordering as Phase 4A. @return array */
    private function lock(int $id): array
    {
        $candidate = LivePosition::query()->find($id);
        if (! $candidate) {
            return [null, null, null];
        }
        $opportunity = TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id);
        $attempt = EthereumSwapAttempt::query()->lockForUpdate()->find($candidate->ethereum_swap_attempt_id);
        $position = LivePosition::query()->lockForUpdate()->find($id);

        return [$position, $attempt, $opportunity];
    }

    private function fingerprint(?EthereumSwapAttempt $attempt, ?TradeOpportunity $opportunity): string
    {
        return hash('sha256', serialize([$attempt?->getRawOriginal(), $opportunity?->getRawOriginal()]));
    }

    /** The claim increments the version; this captures the POST-CLAIM decision. @return array<string, mixed> */
    private function decision(LivePosition $position): array
    {
        return ['id' => (string) $position->id, 'status' => $position->accounting_status,
            'version' => (string) $position->accounting_version,
            'accepted_evidence' => $position->accepted_ethereum_evidence_id === null ? null : (string) $position->accepted_ethereum_evidence_id,
            'lease' => $position->accounting_lease_token];
    }

    /** Stable accounting facts exclude observation time and finality progress. @param array<string, mixed> $facts */
    private function stableFacts(array $facts): array
    {
        $facts = array_intersect_key($facts, array_flip(['receipt', 'block', 'transaction', 'code_sha256', 'quantity', 'metadata', 'finality',
            'token', 'wallet', 'chain_id', 'eligibility_review_id', 'eligibility_version', 'extraction_version']));
        unset($facts['metadata']['observed_at'], $facts['finality']['head'], $facts['finality']['reason'], $facts['finality']['satisfied']);

        return $facts;
    }

    /** @param array<string, mixed> $facts */
    private function core(array $facts): string
    {
        return self::canonicalEvidence($this->stableFacts($facts));
    }

    /** @param array<string, mixed> $facts */
    private function executionCore(array $facts): string
    {
        return self::canonicalEvidence(array_intersect_key($this->stableFacts($facts),
            array_flip(['receipt', 'block', 'transaction', 'code_sha256', 'quantity', 'metadata'])));
    }

    /**
     * Revision identity includes finality observations and accounting policy. Execution equality above
     * deliberately ignores head progress. Timestamps/pointers alone must not create revision loops.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function evidenceDigest(string $state, ?string $reason, array $payload): string
    {
        unset($payload['supersedes_evidence_id'], $payload['metadata']['observed_at']);

        return hash('sha256', self::canonicalEvidence(['policy' => self::POLICY, 'state' => $state, 'reason' => $reason, 'evidence' => $payload]));
    }

    /** Preserve scalar types, object identity and list order through a canonical JSON representation. */
    public static function canonicalEvidence(mixed $value): string
    {
        return json_encode(self::canonicalValue($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(self::canonicalValue(...), $value);
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $values = (array) $value;
            ksort($values, SORT_STRING);
            foreach ($values as $key => $item) {
                $values[$key] = self::canonicalValue($item);
            }

            return (object) $values;
        }

        return $value;
    }

    private function retry(LivePosition $position): void
    {
        $position->accounting_retry_count = min(1000000, $position->accounting_retry_count + 1);
        $base = max(1, (int) config('services.ethereum.accounting.retry_seconds', 60));
        $cap = max($base, (int) config('services.ethereum.accounting.retry_max_seconds', 3600));
        $seconds = min($cap, $base * (2 ** min(10, $position->accounting_retry_count - 1)));
        $position->accounting_next_attempt_at = now()->addSeconds($seconds + random_int(0, min(30, $base)));
    }

    private function release(LivePosition $position): void
    {
        $position->accounting_lease_token = null;
        $position->accounting_lease_expires_at = null;
        $position->save();
    }
}
