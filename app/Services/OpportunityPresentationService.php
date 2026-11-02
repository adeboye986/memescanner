<?php

namespace App\Services;

use App\Chain;
use App\Models\TradeOpportunity;
use Illuminate\Support\Str;

class OpportunityPresentationService
{
    /** @return array<string, mixed> */
    public function present(TradeOpportunity $opportunity): array
    {
        $qualification = $opportunity->qualification_data ?? [];
        $meta = (array) data_get($qualification, 'meta', []);

        return [
            'qualification' => array_values(array_filter([
                $this->field('Pair Address', $opportunity->pair_address),
                $this->field('Scanner / Source', str($opportunity->scanner)->replace('_', ' ')->title()->toString()),
                $this->field('Execution Owner', $this->ownerLabel($opportunity)),
                $this->field('Scan Execution', data_get($qualification, 'scan_execution_id')),
                $this->moneyField('Discovery Market Cap', data_get($qualification, 'discovery_market_cap')),
                $this->percentField('Move Since Discovery', data_get($qualification, 'move_since_discovery_percent')),
                $this->field('Entry Data Source', data_get($meta, 'entry_source') ?? data_get($meta, 'source')),
                $this->field('DEX', data_get($meta, 'dex')),
                $this->field('DEX Confirmation', data_get($meta, 'dex_confirmation')),
                $this->field('Notification', data_get($qualification, 'send_notification', true) ? 'Enabled' : 'Suppressed for this scanner event'),
            ])),
            'market' => [
                'price' => $this->priceDisplay($opportunity->price),
                'market_cap' => $this->moneyDisplay($opportunity->market_cap),
                'liquidity' => $this->moneyDisplay($opportunity->liquidity),
                'volume' => $this->moneyDisplay($opportunity->volume),
                'qualified_at' => $opportunity->qualified_at?->format('M j, Y H:i:s') ?? '—',
            ],
            'source' => [
                'scanner' => str($opportunity->scanner)->replace('_', ' ')->title()->toString(),
                'owner' => $this->ownerLabel($opportunity),
                'scan_execution_id' => data_get($qualification, 'scan_execution_id'),
            ],
            'security' => $this->security($opportunity),
            'failure_reason' => $this->failureReason($opportunity),
        ];
    }

    /** @return array<string, mixed> */
    public function presentIndex(TradeOpportunity $opportunity): array
    {
        $score = $this->securityScore($opportunity);

        return [
            'id' => $opportunity->getKey(),
            'symbol' => $opportunity->symbol ?: 'Unknown',
            'name' => $opportunity->name ?: 'Unnamed token',
            'address' => $opportunity->address,
            'address_display' => Str::limit($opportunity->address, 16, '…'),
            'network_label' => $opportunity->chain->label(),
            'price_display' => $this->priceDisplay($opportunity->price),
            'market_cap_display' => $this->compactMoneyDisplay($opportunity->market_cap),
            'score' => $score,
            'score_tone' => match (true) {
                $score === null => 'unavailable',
                $score >= 80 => 'strong',
                $score >= 60 => 'moderate',
                default => 'elevated',
            },
            'status' => $opportunity->status,
            'qualified_at_display' => $opportunity->qualified_at?->format('M j, Y') ?? 'Unavailable',
            'qualified_at_iso' => $opportunity->qualified_at?->toIso8601String(),
            'source_owner' => $this->ownerLabel($opportunity),
        ];
    }

    /** @return array{status: string, summary: string, fields: list<array{label: string, value: string}>} */
    private function security(TradeOpportunity $opportunity): array
    {
        $security = $opportunity->security_data ?? [];

        if ($opportunity->chain === Chain::Ethereum) {
            $validatedBase = data_get($security, 'market_validation.requested_token_is_base');
            $pairAvailable = data_get($security, 'market_validation.pair_available');

            return [
                'status' => 'unavailable',
                'summary' => (string) ($security['coverage'] ?? 'No Ethereum token-security snapshot was stored for this opportunity.'),
                'fields' => array_values(array_filter([
                    $this->field('Ethereum Security Coverage', 'Unavailable'),
                    $this->field('Market Validation Provider', data_get($security, 'market_validation.provider')),
                    $this->field('DEX Pair Available', $pairAvailable === true ? 'Confirmed' : ($pairAvailable === false ? 'No' : null)),
                    $this->field('Requested Token Is Pair Base', $validatedBase === true ? 'Confirmed' : ($validatedBase === false ? 'No' : null)),
                ])),
            ];
        }

        if ($security === []) {
            return ['status' => 'unavailable', 'summary' => 'No Solana security snapshot was stored for this opportunity.', 'fields' => []];
        }

        $passed = $security['passed'] ?? null;
        $status = $passed === true ? 'passed' : ($passed === false ? 'failed' : 'unavailable');
        $holder = (array) ($security['holder_concentration'] ?? []);

        return [
            'status' => $status,
            'summary' => (string) ($security['coverage'] ?? 'Stored Solana security information.'),
            'fields' => array_values(array_filter([
                $this->field('Security Status', match ($status) {
                    'passed' => 'Passed', 'failed' => 'Failed', default => 'Unavailable'
                }),
                $this->field('Provider / Check', $security['provider'] ?? null),
                $this->field('Security Score', isset($security['score']) ? (string) $security['score'] : null),
                $this->percentField('Largest Holder', $holder['largest_holder_percentage'] ?? null),
                $this->percentField('Top 5 Holders', $holder['top_5_percentage'] ?? null),
                $this->percentField('Top 10 Holders', $holder['top_10_percentage'] ?? null),
                $this->field('Holder Risk', $holder['risk_level'] ?? null),
                $this->field('Recorded Risks', ! empty($security['risks']) ? implode('; ', (array) $security['risks']) : null),
            ])),
        ];
    }

    private function failureReason(TradeOpportunity $opportunity): ?string
    {
        return match (data_get($opportunity->execution_data, 'reason')) {
            'live_execution_disabled' => 'Live execution is not enabled yet.',
            'execution_failed' => 'Execution could not be completed.',
            default => null,
        };
    }

    /** @return array{label: string, value: string}|null */
    private function field(string $label, mixed $value): ?array
    {
        return $value === null || $value === '' ? null : ['label' => $label, 'value' => (string) $value];
    }

    /** @return array{label: string, value: string}|null */
    private function moneyField(string $label, mixed $value): ?array
    {
        return is_numeric($value) ? $this->field($label, '$'.number_format((float) $value, 2)) : null;
    }

    /** @return array{label: string, value: string}|null */
    private function percentField(string $label, mixed $value): ?array
    {
        return is_numeric($value) ? $this->field($label, sprintf('%+.2f%%', (float) $value)) : null;
    }

    private function ownerLabel(TradeOpportunity $opportunity): string
    {
        $engineOwned = data_get($opportunity->qualification_data, 'owner') === 'trading-engine'
            || ($opportunity->relationLoaded('engineOpportunityLink') && $opportunity->engineOpportunityLink !== null);

        return $engineOwned ? 'TypeScript engine projection' : 'Laravel opportunity record';
    }

    private function securityScore(TradeOpportunity $opportunity): ?int
    {
        $score = data_get($opportunity->security_data, 'score');

        if (! is_numeric($score)) {
            return null;
        }

        $numericScore = (float) $score;

        if (floor($numericScore) !== $numericScore || $numericScore < 0 || $numericScore > 100) {
            return null;
        }

        return (int) $numericScore;
    }

    private function priceDisplay(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $price = (float) $value;

        if ($price === 0.0 || abs($price) >= 1) {
            return '$'.number_format($price, 2);
        }

        return '$'.rtrim(rtrim(number_format($price, 12, '.', ''), '0'), '.');
    }

    private function moneyDisplay(mixed $value): string
    {
        return is_numeric($value) ? '$'.number_format((float) $value, 2) : '—';
    }

    private function compactMoneyDisplay(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $amount = (float) $value;

        return match (true) {
            abs($amount) >= 1_000_000_000 => '$'.rtrim(rtrim(number_format($amount / 1_000_000_000, 1), '0'), '.').'B',
            abs($amount) >= 1_000_000 => '$'.rtrim(rtrim(number_format($amount / 1_000_000, 1), '0'), '.').'M',
            abs($amount) >= 1_000 => '$'.rtrim(rtrim(number_format($amount / 1_000, 1), '0'), '.').'K',
            default => '$'.number_format($amount, 0),
        };
    }
}
