<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/** Append reviewed policy decisions; no scanner/security feed implicitly approves accounting. */
class EthereumAccountingEligibility extends Model
{
    public const POLICY = 'reviewed-standard-nonproxy-erc20-v1';

    protected $fillable = ['chain', 'token_address', 'policy_version', 'status', 'review_source', 'code_sha256', 'reviewed_at', 'reason'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'evidence_collected_at' => 'datetime', 'reviewer_identity' => 'array', 'review_evidence' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            if ($review->chain !== 'ethereum' || preg_match('/^0x[a-f0-9]{40}$/D', $review->token_address ?? '') !== 1
                || $review->policy_version !== self::POLICY || ! in_array($review->status, ['approved', 'rejected', 'unsupported'], true)
                || ! $review->reviewed_at || $review->reviewed_at->isFuture()
                || ! is_string($review->review_source) || trim($review->review_source) === '' || strlen($review->review_source) > 255
                || ! is_string($review->reason) || trim($review->reason) === '' || strlen($review->reason) > 255
                || ($review->status === 'approved' && preg_match('/^[a-f0-9]{64}$/D', $review->code_sha256 ?? '') !== 1)) {
                throw new DomainException('Accounting approval requires a sourced standard non-proxy token semantics review and code identity.');
            }
        });
        static::updating(fn () => throw new DomainException('Append a new accounting eligibility review.'));
        static::deleting(fn () => throw new DomainException('Accounting eligibility history cannot be deleted.'));
    }
}
