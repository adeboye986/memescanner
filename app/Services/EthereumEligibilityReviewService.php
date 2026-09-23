<?php

namespace App\Services;

use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingReviewHead;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Sole supported publication path; reviewer assertions never supply runtime observations. */
class EthereumEligibilityReviewService
{
    public const FORMAT = 'trusted-review-v1';

    public function __construct(private EthereumEligibilityObservationCollector $collector) {}

    /** @param array<string, mixed> $input */
    public function publish(array $input): EthereumAccountingEligibility
    {
        Gate::authorize('review-ethereum-accounting');
        /** @var User $actor */
        $actor = Auth::user();
        $this->validate($input);
        if ($existing = EthereumAccountingEligibility::query()->where('submission_id', $input['submission_id'])->first()) {
            return $this->duplicate($existing, $actor, $input);
        }
        // Collection is outside the database transaction. A rejection never invokes the collector.
        $observation = $input['decision'] === 'approved'
            ? $this->collector->collect($input['token_address'], $input['observation_reference']) : null;
        if ($observation !== null) {
            $this->validateObservation($observation, $input['token_address']);
        }
        $digest = self::digest($actor->id, $input, $observation);
        if (! hash_equals($digest, $input['evidence_digest'])) {
            throw new DomainException('The confirmed evidence digest does not match.');
        }
        try {
            return DB::transaction(function () use ($actor, $input, $observation, $digest): EthereumAccountingEligibility {
                Gate::forUser($actor->fresh())->authorize('review-ethereum-accounting');
                $head = EthereumAccountingReviewHead::locked($input['token_address']);
                if ($existing = EthereumAccountingEligibility::query()->where('submission_id', $input['submission_id'])->lockForUpdate()->first()) {
                    return $this->duplicate($existing, $actor, $input);
                }
                if ($head->snapshot() !== ['review' => $input['expected_review_id'] === null ? null : (string) $input['expected_review_id'],
                    'version' => (string) $input['expected_version']]) {
                    throw new DomainException('The current review has changed; review the new decision first.');
                }
                $review = new EthereumAccountingEligibility;
                $review->forceFill([
                    'chain' => 'ethereum', 'chain_id' => 1, 'token_address' => $input['token_address'],
                    'policy_version' => EthereumAccountingEligibility::POLICY, 'status' => $input['decision'],
                    'review_source' => $input['review_source'], 'reason' => $input['rationale'], 'reviewed_at' => now(),
                    'code_sha256' => $observation['code_sha256'] ?? null,
                    'reviewer_user_id' => $actor->id, 'reviewer_identity' => ['id' => (string) $actor->id, 'name' => $actor->name, 'email' => $actor->email],
                    'review_format_version' => self::FORMAT, 'supersedes_review_id' => $head->current_review_id,
                    'observation_block_number' => $observation['block_number'] ?? null,
                    'observation_block_hash' => $observation['block_hash'] ?? null,
                    'evidence_collected_at' => $observation['collected_at'] ?? null,
                    'review_evidence' => ['submission' => self::payload($input), 'observation' => $observation],
                    'evidence_digest' => $digest, 'submission_id' => $input['submission_id'],
                ])->save();
                $head->current_review_id = $review->id;
                $head->version++;
                $head->save();

                return $review;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = EthereumAccountingEligibility::query()->where('submission_id', $input['submission_id'])->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->duplicate($existing, $actor, $input);
        }
    }

    /** Exact confirmation identity includes actor, assertions, expected head and trusted observations. */
    public static function digest(int $actorId, array $input, ?array $observation): string
    {
        return hash('sha256', EthereumInventoryAccounting::canonicalEvidence([
            'format' => self::FORMAT, 'actor_id' => (string) $actorId,
            'submission' => self::payload($input), 'observation' => $observation,
        ]));
    }

    private static function payload(array $input): array
    {
        unset($input['evidence_digest']);

        return $input;
    }

    private function duplicate(EthereumAccountingEligibility $review, User $actor, array $input): EthereumAccountingEligibility
    {
        if ((string) $review->reviewer_user_id !== (string) $actor->id
            || $review->review_format_version !== self::FORMAT
            || $review->evidence_digest !== $input['evidence_digest']
            || EthereumInventoryAccounting::canonicalEvidence($review->review_evidence['submission'] ?? null)
                !== EthereumInventoryAccounting::canonicalEvidence(self::payload($input))) {
            throw new DomainException('Submission identifier was already used for different content.');
        }

        return $review;
    }

    private function validate(array $input): void
    {
        $rules = [
            'chain' => ['required', 'in:ethereum'], 'chain_id' => ['required', 'in:1'],
            'token_address' => ['required', 'string', 'regex:/^0x[a-f0-9]{40}$/D'],
            'policy_version' => ['required', 'in:'.EthereumAccountingEligibility::POLICY],
            'decision' => ['required', 'in:approved,rejected'],
            'review_source' => ['required', 'string', 'max:255'], 'rationale' => ['required', 'string', 'max:255'],
            'expected_review_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'submission_id' => ['required', 'uuid', 'lowercase'],
            'evidence_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'observation_reference' => ['present', 'nullable', 'string', 'max:128'],
            'assertions' => ['present', 'array'],
        ];
        if (array_diff(array_keys($input), array_keys($rules)) !== [] || Validator::make($input, $rules)->fails()
            || $input['chain_id'] !== 1 || ! is_int($input['expected_version'])
            || ($input['expected_review_id'] !== null && ! is_int($input['expected_review_id']))
            || strlen(EthereumInventoryAccounting::canonicalEvidence($input)) > 32768) {
            throw new DomainException('Invalid review submission.');
        }
        if ($input['decision'] === 'rejected') {
            if ($input['observation_reference'] !== null || $input['assertions'] !== []) {
                throw new DomainException('Rejection requires rationale, not approval observations.');
            }

            return;
        }
        $assertions = $input['assertions'];
        if (! is_string($input['observation_reference']) || trim($input['observation_reference']) === ''
            || Validator::make($assertions, [
                'source_reference' => ['required', 'string', 'max:2048'],
                'source_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                'historical_applicability' => ['required', 'string', 'max:4096'],
                'non_proxy' => ['required'], 'standard_transfer_accounting' => ['required'],
                'no_mutable_balance_behavior' => ['required'],
            ])->fails()
            || array_diff(array_keys($assertions), ['source_reference', 'source_sha256', 'historical_applicability', 'non_proxy', 'standard_transfer_accounting', 'no_mutable_balance_behavior']) !== []
            || ($assertions['non_proxy'] ?? null) !== true
            || ($assertions['standard_transfer_accounting'] ?? null) !== true
            || ($assertions['no_mutable_balance_behavior'] ?? null) !== true) {
            throw new DomainException('Complete sourced non-proxy accounting and historical conclusions are required.');
        }
    }

    private function validateObservation(array $observation, string $token): void
    {
        if (Validator::make($observation, [
            'chain_id' => ['required', 'in:1'], 'token_address' => ['required', 'in:'.$token],
            'code_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'block_number' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,77})$/D'],
            'block_hash' => ['required', 'string', 'regex:/^0x[a-f0-9]{64}$/D'],
            'collected_at' => ['required', 'string', 'max:40', 'date', 'before_or_equal:now'],
        ])->fails() || ($observation['chain_id'] ?? null) !== 1
            || bccomp($observation['block_number'], EthereumAccountingNumbers::MAX, 0) > 0
            || array_diff(array_keys($observation), ['chain_id', 'token_address', 'code_sha256', 'block_number', 'block_hash', 'collected_at']) !== []) {
            throw new DomainException('Missing or invalid trusted historical runtime observation.');
        }
    }
}
