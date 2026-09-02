<?php

namespace App\Services;

use App\Chain;
use App\Models\TradeOpportunity;

class OpportunityPresentationService
{
    /** @return array{qualification: list<array{label: string, value: string}>, security: array{status: string, summary: string, fields: list<array{label: string, value: string}>}, failure_reason: ?string} */
    public function present(TradeOpportunity $opportunity): array
    {
        $qualification = $opportunity->qualification_data ?? [];
        $meta = (array) data_get($qualification, 'meta', []);

        return [
            'qualification' => array_values(array_filter([
                $this->field('Pair Address', $opportunity->pair_address),
                $this->field('Scanner / Source', str($opportunity->scanner)->replace('_', ' ')->title()->toString()),
                $this->moneyField('Discovery Market Cap', data_get($qualification, 'discovery_market_cap')),
                $this->percentField('Move Since Discovery', data_get($qualification, 'move_since_discovery_percent')),
                $this->field('Entry Data Source', data_get($meta, 'entry_source') ?? data_get($meta, 'source')),
                $this->field('DEX', data_get($meta, 'dex')),
                $this->field('DEX Confirmation', data_get($meta, 'dex_confirmation')),
                $this->field('Notification', data_get($qualification, 'send_notification', true) ? 'Enabled' : 'Suppressed for this scanner event'),
            ])),
            'security' => $this->security($opportunity),
            'failure_reason' => $this->failureReason($opportunity),
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
}
