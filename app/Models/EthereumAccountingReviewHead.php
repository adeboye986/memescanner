<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Coordination only: all review publishers and accounting writers lock this row first. */
class EthereumAccountingReviewHead extends Model
{
    protected $fillable = ['chain', 'token_address'];

    public static function locked(string $token): self
    {
        if (DB::transactionLevel() === 0) {
            throw new DomainException('A review head requires a transaction.');
        }
        $identity = ['chain' => 'ethereum', 'token_address' => $token];
        // A no-op upsert takes the same exclusive identity lock for both first and existing writers.
        DB::table('ethereum_accounting_review_heads')->upsert([
            [...$identity, 'version' => 0, 'created_at' => now(), 'updated_at' => now()],
        ], ['chain', 'token_address'], ['token_address']);
        $head = static::query()->where($identity)->lockForUpdate()->firstOrFail();
        if ((string) $head->version === '0') {
            // Adopt only the latest pre-existing decision, without rewriting legacy provenance.
            $legacy = EthereumAccountingEligibility::query()->where($identity)->latest('id')->lockForUpdate()->first();
            if ($legacy) {
                $head->current_review_id = $legacy->id;
                $head->version = 1;
                $head->save();
            }
        }

        return $head;
    }

    public function snapshot(): array
    {
        return ['review' => $this->current_review_id === null ? null : (string) $this->current_review_id,
            'version' => (string) $this->version];
    }

    public function currentReview(): ?EthereumAccountingEligibility
    {
        return $this->current_review_id === null ? null : EthereumAccountingEligibility::query()
            ->where('chain', $this->chain)->where('token_address', $this->token_address)->lockForUpdate()->findOrFail($this->current_review_id);
    }
}
