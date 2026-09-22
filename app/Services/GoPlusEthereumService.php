<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GoPlusEthereumService
{
    /**
     * GoPlus EVM flags are strings; omitted/null means unknown, never safe.
     * Minting, balance modification and pausing mirror existing critical rules.
     * https://docs.gopluslabs.io/reference/response-details
     *
     * @var array<string, string>
     */
    private const REQUIRED_FLAGS = [
        'is_open_source' => '1',
        'is_mintable' => '0',
        'owner_change_balance' => '0',
        'transfer_pausable' => '0',
        'is_honeypot' => '0',
        'cannot_buy' => '0',
        'cannot_sell_all' => '0',
    ];

    /** @return array{available: bool, passed: bool, chain: string, address: string, provider: string, checks: array<string, string>, failure_class: ?string, reason: string} */
    public function evaluateToken(string $address): array
    {
        $address = strtolower($address);
        $result = ['available' => false, 'passed' => false, 'chain' => 'ethereum', 'address' => $address,
            'provider' => 'GoPlus', 'checks' => [], 'failure_class' => 'unavailable', 'reason' => 'security_unavailable'];
        if (! preg_match('/^0x[0-9a-f]{40}$/D', $address)) {
            return [...$result, 'failure_class' => 'malformed', 'reason' => 'invalid_address'];
        }
        $request = Http::connectTimeout(10)->timeout(30)->acceptJson();
        $token = config('services.goplus.access_token');
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }
        try {
            $response = $request->get('https://api.gopluslabs.io/api/v1/token_security/1', ['contract_addresses' => $address]);
        } catch (ConnectionException) {
            return $result;
        }
        if (! $response->successful()) {
            return $result;
        }
        $body = $response->json();
        if (! is_array($body) || ($body['code'] ?? null) !== 1 || ! is_array($body['result'] ?? null)) {
            return [...$result, 'failure_class' => 'malformed', 'reason' => 'malformed_response'];
        }
        $matches = array_filter($body['result'], fn ($key) => is_string($key) && strtolower($key) === $address, ARRAY_FILTER_USE_KEY);
        if (count($matches) !== 1 || ! is_array($data = reset($matches))) {
            return [...$result, 'failure_class' => 'malformed', 'reason' => 'requested_token_missing'];
        }
        $checks = [];
        $unknown = false;
        $unsafe = false;
        foreach (self::REQUIRED_FLAGS as $field => $safe) {
            if (! in_array($data[$field] ?? null, ['0', '1'], true)) {
                $unknown = true;

                continue;
            }
            $checks[$field] = $data[$field];
            $unsafe = $unsafe || $data[$field] !== $safe;
        }
        if ($unknown && ! $unsafe) {
            return [...$result, 'checks' => $checks, 'failure_class' => 'malformed', 'reason' => 'required_security_unknown'];
        }
        $passed = $checks === self::REQUIRED_FLAGS;

        return [...$result, 'available' => true, 'passed' => $passed, 'checks' => $checks,
            'failure_class' => $passed ? null : 'unsafe', 'reason' => $passed ? 'qualified' : 'critical_security_risk'];
    }
}
