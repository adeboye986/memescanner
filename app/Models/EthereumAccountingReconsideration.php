<?php

namespace App\Models;

use App\Services\EthereumInventoryAccounting;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EthereumAccountingReconsideration extends Model
{
    public const MAX_BATCH = 10;

    public const BINDING_FIELDS = ['request_uuid', 'requested_by_user_id', 'reviewer_identity', 'chain', 'token_address',
        'ethereum_accounting_eligibility_id', 'review_head_version', 'review_decision', 'policy_version',
        'candidate_snapshot', 'batch_limit', 'expires_at'];

    public const SKIPS = ['skipped_verified', 'skipped_discrepancy', 'skipped_provisional', 'skipped_state_changed',
        'skipped_identity', 'skipped_active_lease', 'skipped_backoff', 'skipped_rejected'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['reviewer_identity' => 'array', 'candidate_snapshot' => 'array', 'outcomes' => 'array',
            'requested_by_user_id' => 'integer', 'ethereum_accounting_eligibility_id' => 'integer', 'review_head_version' => 'integer',
            'batch_limit' => 'integer', 'considered_count' => 'integer', 'skipped_count' => 'integer', 'succeeded_count' => 'integer',
            'failed_count' => 'integer', 'stale_count' => 'integer', 'remaining_count' => 'integer',
            'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function digest(): string
    {
        $binding = [];
        foreach (self::BINDING_FIELDS as $field) {
            $binding[$field] = $field === 'expires_at' ? $this->expires_at?->toIso8601String() : $this->{$field};
        }

        return hash('sha256', EthereumInventoryAccounting::canonicalEvidence($binding));
    }

    public function reviewBinding(): array
    {
        return ['chain' => $this->chain, 'token' => $this->token_address, 'review' => (string) $this->ethereum_accounting_eligibility_id,
            'version' => (string) $this->review_head_version, 'decision' => $this->review_decision, 'policy' => $this->policy_version];
    }

    public static function counts(array $outcomes): array
    {
        $counts = ['considered_count' => count($outcomes), 'skipped_count' => 0, 'succeeded_count' => 0, 'failed_count' => 0, 'stale_count' => 0];
        foreach ($outcomes as $outcome) {
            $field = in_array($outcome['code'], self::SKIPS, true) ? 'skipped_count'
                : (in_array($outcome['code'], ['stale_review', 'stale_observation'], true) ? 'stale_count'
                    : ($outcome['code'] === 'reconsidered' ? 'succeeded_count' : 'failed_count'));
            $counts[$field]++;
        }

        return $counts;
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if ($request->status !== 'awaiting_confirmation' || ! Str::isUuid($request->request_uuid)
                || $request->chain !== 'ethereum' || preg_match('/^0x[a-f0-9]{40}$/D', $request->token_address) !== 1
                || ! in_array($request->review_decision, ['approved', 'rejected'], true)
                || $request->policy_version !== EthereumAccountingEligibility::POLICY
                || ! hash_equals($request->digest(), $request->binding_digest) || $request->outcomes !== []) {
                throw new DomainException('Invalid reconsideration binding.');
            }
        });
        static::updating(function (self $request): void {
            if ($request->isDirty([...self::BINDING_FIELDS, 'binding_digest', 'created_at'])) {
                throw new DomainException('Reconsideration binding is immutable.');
            }
            $previous = $request->getRawOriginal('status');
            $allowed = ['awaiting_confirmation' => ['running', 'stale', 'failed'], 'running' => ['completed', 'stale', 'failed']];
            if (! isset($allowed[$previous]) || ($request->isDirty('status') && ! in_array($request->status, $allowed[$previous], true))) {
                throw new DomainException('Invalid reconsideration state transition.');
            }
            $old = json_decode($request->getRawOriginal('outcomes'), true, 512, JSON_THROW_ON_ERROR);
            if (EthereumInventoryAccounting::canonicalEvidence(array_slice($request->outcomes, 0, count($old))) !== EthereumInventoryAccounting::canonicalEvidence($old)) {
                throw new DomainException('Recorded reconsideration outcomes cannot be rewritten.');
            }
        });
        static::saving(fn (self $request) => $request->validateBounds());
        static::deleting(fn () => throw new DomainException('Reconsideration audit records cannot be deleted.'));
    }

    private function validateBounds(): void
    {
        $candidates = $this->candidate_snapshot;
        $outcomes = $this->outcomes;
        if ($this->batch_limit < 1 || $this->batch_limit > self::MAX_BATCH || ! is_array($candidates)
            || ! array_is_list($candidates) || count($candidates) > $this->batch_limit
            || ! is_array($outcomes) || ! array_is_list($outcomes) || count($outcomes) > count($candidates)
            || strlen(EthereumInventoryAccounting::canonicalEvidence([$candidates, $outcomes])) > 32768) {
            throw new DomainException('Reconsideration snapshots and outcomes must be bounded.');
        }
        $ids = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || count($candidate) !== 4 || array_diff(array_keys($candidate), ['id', 'state', 'version', 'source']) !== []
                || ! is_int($candidate['id']) || $candidate['id'] < 1 || isset($ids[$candidate['id']])
                || ! in_array($candidate['state'], ['pending', 'unsupported'], true) || ! is_string($candidate['version'])
                || preg_match('/^[0-9]+$/D', $candidate['version']) !== 1
                || ! is_string($candidate['source']) || preg_match('/^[a-f0-9]{64}$/D', $candidate['source']) !== 1) {
                throw new DomainException('Invalid reconsideration candidate.');
            }
            $ids[$candidate['id']] = true;
        }
        $seen = [];
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome) || count($outcome) !== 2 || array_diff(array_keys($outcome), ['position_id', 'code']) !== [] || ! is_int($outcome['position_id'])
                || ! isset($ids[$outcome['position_id']]) || isset($seen[$outcome['position_id']])
                || ! in_array($outcome['code'], [...self::SKIPS, 'reconsidered', 'unsupported', 'accounting_failed', 'stale_review', 'stale_observation'], true)) {
                throw new DomainException('Invalid reconsideration outcome.');
            }
            $seen[$outcome['position_id']] = true;
        }
        if (($this->status === 'awaiting_confirmation' && ($outcomes !== [] || $this->started_at !== null || $this->finished_at !== null))
            || ($this->status === 'running' && ($this->started_at === null || $this->finished_at !== null))
            || ($this->status === 'completed' && ($this->started_at === null || count($outcomes) !== count($candidates)))
            || (in_array($this->status, ['completed', 'stale', 'failed'], true) && $this->finished_at === null)) {
            throw new DomainException('Reconsideration progress does not match its status.');
        }
        foreach (self::counts($outcomes) as $field => $value) {
            if ($this->{$field} !== $value) {
                throw new DomainException('Reconsideration counters must match recorded outcomes.');
            }
        }
    }
}
