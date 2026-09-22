<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/** Immutable normalized observations. A later row supersedes, never rewrites, earlier evidence. */
class EthereumAccountingEvidence extends Model
{
    protected $fillable = ['live_position_id', 'ethereum_swap_attempt_id', 'ethereum_accounting_eligibility_id',
        'state', 'reason_code', 'digest', 'policy_version', 'evidence'];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evidence): void {
            if (strlen(json_encode($evidence->evidence, JSON_THROW_ON_ERROR)) > 524288) {
                throw new DomainException('Normalized accounting evidence exceeds its storage bound.');
            }
        });
        static::updating(fn () => throw new DomainException('Accounting evidence is immutable; append a revision.'));
        static::deleting(fn () => throw new DomainException('Accounting evidence cannot be deleted.'));
    }
}
