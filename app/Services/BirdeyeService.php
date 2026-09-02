<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BirdeyeService
{
    protected string $baseUrl;

    protected string $apiKey;

    public function __construct(ApplicationSettingsService $settings)
    {
        $this->baseUrl = config('services.birdeye.base_url');
        $this->apiKey = (string) $settings->getSecret('market_data.birdeye_api_key');
    }

    public function tokenOverview(string $address): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout(30)
                    ->withHeaders([
                        'X-API-KEY' => $this->apiKey,
                        'x-chain' => 'solana',
                        'accept' => 'application/json',
                    ])
                    ->get($this->baseUrl.'/defi/token_overview', [
                        'address' => $address,
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429 && $attempt < $maxAttempts) {
                    sleep(5 * $attempt);

                    continue;
                }

                throw new RuntimeException(
                    'Birdeye API error: '.
                    $response->status().
                    ' - '.
                    $response->body()
                );

            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                sleep(3 * $attempt);
            }
        }

        throw new RuntimeException(
            'Birdeye request failed after retries.'
        );
    }

    public function newListings(int $limit = 20): array
    {
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(20)
                    ->withHeaders([
                        'X-API-KEY' => $this->apiKey,
                        'x-chain' => 'solana',
                        'accept' => 'application/json',
                    ])
                    ->get($this->baseUrl.'/defi/v2/tokens/new_listing', [
                        'limit' => min($limit, 20),
                        'meme_platform_enabled' => 'true',
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429) {
                    if ($attempt < $maxAttempts) {
                        $wait = 10 * $attempt;

                        sleep($wait);

                        continue;
                    }
                }

                throw new RuntimeException(
                    'Birdeye API error: '.
                    $response->status().
                    ' - '.
                    $response->body()
                );

            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                sleep(5 * $attempt);
            }
        }

        throw new RuntimeException(
            "Birdeye new listings request failed after {$maxAttempts} attempts."
        );
    }

    public function tokenSecurity(string $address): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(20)
                    ->withHeaders([
                        'X-API-KEY' => $this->apiKey,
                        'x-chain' => 'solana',
                        'accept' => 'application/json',
                    ])
                    ->get($this->baseUrl.'/defi/token_security', [
                        'address' => $address,
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429 && $attempt < $maxAttempts) {
                    sleep(2 * $attempt);

                    continue;
                }

                throw new RuntimeException(
                    'Birdeye Security API error: '.
                    $response->status().
                    ' - '.
                    $response->body()
                );

            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                sleep(2 * $attempt);
            }
        }

        throw new RuntimeException(
            "Birdeye security request failed after {$maxAttempts} attempts."
        );
    }

    public function momentumTokens(int $limit = 20): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout(30)
                    ->withHeaders([
                        'X-API-KEY' => $this->apiKey,
                        'x-chain' => 'solana',
                        'accept' => 'application/json',
                    ])
                    ->get($this->baseUrl.'/defi/v3/token/list', [
                        'sort_by' => 'volume_5m_usd',
                        'sort_type' => 'desc',

                        // Broader than the launch scanner.
                        'min_market_cap' => 5000,
                        'max_market_cap' => 100000,

                        'min_liquidity' => 1000,
                        'min_holder' => 10,

                        // Require some actual recent trading.
                        'min_volume_5m_usd' => 500,

                        'limit' => min($limit, 100),
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if (
                    $response->status() === 429 &&
                    $attempt < $maxAttempts
                ) {
                    sleep(5 * $attempt);

                    continue;
                }

                throw new RuntimeException(
                    'Birdeye momentum API error: '.
                    $response->status().
                    ' - '.
                    $response->body()
                );

            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                sleep(3 * $attempt);
            }
        }

        throw new RuntimeException(
            'Birdeye momentum request failed after retries.'
        );
    }

    public function credits(): array
    {
        $response = Http::connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'X-API-KEY' => $this->apiKey,
                'accept' => 'application/json',
            ])
            ->get($this->baseUrl.'/utils/v1/credits');

        if ($response->failed()) {
            throw new RuntimeException(
                'Birdeye credits error: '.
                $response->status().
                ' - '.
                $response->body()
            );
        }

        return $response->json();
    }
}
