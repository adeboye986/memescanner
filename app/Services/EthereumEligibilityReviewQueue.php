<?php

namespace App\Services;

use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingReviewHead;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class EthereumEligibilityReviewQueue
{
    public function query(): Builder
    {
        $scans = DB::table('token_scans')->where('chain', 'ethereum')->selectRaw('LOWER(address) AS token, symbol, name, 1 AS scanned, 0 AS opportunity, 0 AS live, 0 AS waiting, last_scanned_at AS seen');
        $opportunities = DB::table('trade_opportunities')->where('chain', 'ethereum')->selectRaw('LOWER(address) AS token, symbol, name, 0 AS scanned, 1 AS opportunity, 0 AS live, 0 AS waiting, qualified_at AS seen');
        $positions = DB::table('live_positions')->where('chain', 'ethereum')->selectRaw("LOWER(token_address) AS token, NULL AS symbol, NULL AS name, 0 AS scanned, 0 AS opportunity, 1 AS live, CASE WHEN accounting_status IN ('pending', 'unsupported') THEN 1 ELSE 0 END AS waiting, NULL AS seen");
        $identities = DB::query()->fromSub($scans->unionAll($opportunities)->unionAll($positions), 'identities')
            ->selectRaw('token, MAX(symbol) AS symbol, MAX(name) AS name, MAX(scanned) AS scanned, MAX(opportunity) AS opportunity, MAX(live) AS live, SUM(waiting) AS waiting, MAX(seen) AS seen')
            ->whereRaw('LENGTH(token) = 42')->where('token', 'like', '0x%')->groupBy('token');
        $remaining = 'SUBSTR(token, 3)';
        foreach (str_split('0123456789abcdef') as $digit) {
            $remaining = "REPLACE({$remaining}, '{$digit}', '')";
        }
        $identities->whereRaw("LENGTH({$remaining}) = 0");
        $legacy = DB::table('ethereum_accounting_eligibilities')->where('chain', 'ethereum')->selectRaw('token_address, MAX(id) AS review_id')->groupBy('token_address');

        return DB::query()->fromSub($identities, 'tokens')
            ->leftJoin('ethereum_accounting_review_heads AS heads', fn ($join) => $join->on('heads.token_address', '=', 'tokens.token')->where('heads.chain', 'ethereum'))
            ->leftJoinSub($legacy, 'legacy', 'legacy.token_address', '=', 'tokens.token')
            ->leftJoin('ethereum_accounting_eligibilities AS reviews', function ($join): void {
                $join->on('reviews.id', '=', DB::raw('COALESCE(heads.current_review_id, legacy.review_id)'))
                    ->on('reviews.token_address', '=', 'tokens.token')->where('reviews.chain', 'ethereum');
            })->select('tokens.*', 'reviews.status', 'reviews.review_format_version')->orderBy('tokens.token');
    }

    /** Read-only equivalent of lazy legacy-head adoption; publication remains the atomic writer. */
    public function state(string $token): array
    {
        $head = EthereumAccountingReviewHead::query()->where('chain', 'ethereum')->where('token_address', $token)->first();
        $review = $head?->current_review_id
            ? EthereumAccountingEligibility::query()->where('chain', 'ethereum')->where('token_address', $token)->findOrFail($head->current_review_id)
            : EthereumAccountingEligibility::query()->where('chain', 'ethereum')->where('token_address', $token)->latest('id')->first();

        return ['expected_review_id' => $review?->id, 'expected_version' => (int) ($head?->version ?: ($review ? 1 : 0))];
    }
}
