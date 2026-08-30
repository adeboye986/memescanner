<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoPlusService
{
    protected string $baseUrl = 'https://api.gopluslabs.io/api/v1';

     public function tokenSecurity(string $address): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout(30)
                    ->acceptJson()
                    ->get($this->baseUrl . '/solana/token_security', [
                        'contract_addresses' => $address,
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429 && $attempt < $maxAttempts) {
                    sleep(5 * $attempt);
                    continue;
                }

                throw new RuntimeException(
                    'GoPlus API error: ' .
                    $response->status() .
                    ' - ' .
                    $response->body()
                );

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                sleep(3 * $attempt);
            }
        }

        throw new RuntimeException(
            'GoPlus request failed after retries.'
        );
    }

    public function evaluateToken(string $address): array
    {
        $response = $this->tokenSecurity($address);

        $data = $response['result'][$address] ?? null;

        if (!$data) {
            return [
                'passed' => false,
                'score' => 0,
                'risks' => ['No GoPlus security data returned'],
                'data' => [],
            ];
        }

        $risks = [];
        $score = 100;

        // Critical risks
        if (($data['mintable']['status'] ?? '0') === '1') {
            $risks[] = 'Token is mintable';
            $score -= 30;
        }

        if (($data['freezable']['status'] ?? '0') === '1') {
            $risks[] = 'Token can be frozen';
            $score -= 30;
        }

        if (($data['non_transferable']['status'] ?? '0') === '1') {
            $risks[] = 'Token may be non-transferable';
            $score -= 50;
        }

        if (($data['transfer_hook']['status'] ?? '0') === '1') {
            $risks[] = 'Transfer hook enabled';
            $score -= 25;
        }

        if (($data['balance_mutable_authority']['status'] ?? '0') === '1') {
            $risks[] = 'Mutable balance authority detected';
            $score -= 40;
        }

        // Medium risks
        if (($data['transfer_fee']['status'] ?? '0') === '1') {
            $risks[] = 'Transfer fee enabled';
            $score -= 15;
        }

        if (($data['transfer_fee_upgradable']['status'] ?? '0') === '1') {
            $risks[] = 'Transfer fee can be upgraded';
            $score -= 20;
        }

        if (($data['transfer_hook_upgradable']['status'] ?? '0') === '1') {
            $risks[] = 'Transfer hook can be upgraded';
            $score -= 20;
        }

        if (($data['closable']['status'] ?? '0') === '1') {
            $risks[] = 'Token account/program is closable';
            $score -= 15;
        }

        // Lower severity
        if (($data['metadata_mutable']['status'] ?? '0') === '1') {
            $risks[] = 'Metadata is mutable';
            $score -= 5;
        }

        $score = max(0, $score);

        /*
        * Hard rejection:
        * We do not allow the most dangerous capabilities through
        * regardless of activity/momentum.
        */
        $criticalFailure =
            (($data['mintable']['status'] ?? '0') === '1') ||
            (($data['freezable']['status'] ?? '0') === '1') ||
            (($data['non_transferable']['status'] ?? '0') === '1') ||
            (($data['balance_mutable_authority']['status'] ?? '0') === '1');

        return [
            'passed' => !$criticalFailure && $score >= 60,
            'score' => $score,
            'risks' => $risks,
            'data' => $data,
        ];
    }
}