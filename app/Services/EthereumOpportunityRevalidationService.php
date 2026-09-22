<?php

namespace App\Services;

use App\Chain;
use App\Models\TradeOpportunity;
use App\Services\Chains\EthereumChainAdapter;
use Throwable;
use UnexpectedValueException;

class EthereumOpportunityRevalidationService
{
    public function __construct(private EthereumChainAdapter $ethereum, private EthereumQualificationEvaluator $qualification) {}

    /** failure_class is a closed domain: unavailable, malformed, unsafe, or null on success.
     * @return array<string, mixed>
     */
    public function check(TradeOpportunity $opportunity): array
    {
        $audit = ['checked_at' => now()->toIso8601String(), 'chain' => 'ethereum', 'address' => strtolower($opportunity->address),
            'scanner' => $opportunity->scanner, 'passed' => false, 'failure_class' => 'unsafe',
            'market' => ['status' => 'unavailable'], 'security' => ['status' => 'unavailable']];
        if ($opportunity->chain !== Chain::Ethereum || ! in_array($opportunity->scanner, ['new-token', 'momentum'], true)) {
            return [...$audit, 'reason' => 'unsupported_qualification'];
        }
        try {
            $market = $this->ethereum->liveMarketData($opportunity->address, $opportunity->scanner);
            $identity = ($market['available'] ?? null) === true && data_get($market, 'raw.chainId') === 'ethereum'
                && EthereumLiveMarketValidation::address($market['base_token_address'] ?? null) === strtolower($opportunity->address)
                && EthereumLiveMarketValidation::address($market['requested_token_address'] ?? null) === strtolower($opportunity->address);
            if (! $identity) {
                return [...$audit, 'reason' => 'market_revalidation_failed'];
            }
            $facts = EthereumLiveMarketValidation::facts($market, $opportunity->scanner);
            $passed = $this->qualification->qualifies($opportunity->scanner, [...$market, ...$facts]);
            $audit['market'] = ['status' => $passed ? 'passed' : 'failed', 'provider' => 'DexScreener', ...$facts, 'identity_matches' => true];
            if (! $passed) {
                return [...$audit, 'reason' => 'market_revalidation_failed'];
            }
            $security = $this->ethereum->securityData($opportunity->address);
            $safe = ($security['available'] ?? null) === true && ($security['passed'] ?? null) === true
                && ($security['chain'] ?? null) === 'ethereum'
                && EthereumLiveMarketValidation::address($security['address'] ?? null) === strtolower($opportunity->address);
            $checks = [];
            foreach (['is_open_source', 'is_mintable', 'owner_change_balance', 'transfer_pausable', 'is_honeypot', 'cannot_buy', 'cannot_sell_all'] as $field) {
                $value = $security['checks'][$field] ?? null;
                if (in_array($value, ['0', '1'], true)) {
                    $checks[$field] = $value;
                }
            }
            $classification = in_array($security['failure_class'] ?? null, ['unavailable', 'malformed', 'unsafe'], true) ? $security['failure_class'] : 'malformed';
            $audit['security'] = ['status' => $safe ? 'passed' : 'failed', 'provider' => 'GoPlus',
                'available' => ($security['available'] ?? false) === true, 'checks' => $checks];

            return [...$audit, 'passed' => $safe, 'failure_class' => $safe ? null : $classification,
                'reason' => $safe ? 'qualified' : 'security_revalidation_failed'];
        } catch (UnexpectedValueException|\TypeError) {
            return [...$audit, 'failure_class' => 'malformed', 'reason' => 'market_evidence_unusable'];
        } catch (Throwable) {
            return [...$audit, 'failure_class' => 'unavailable', 'reason' => 'revalidation_unavailable'];
        }
    }
}
