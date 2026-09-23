<?php

namespace App\Services;

use DomainException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Server-held observations; browser references never supply runtime or block facts. */
class EthereumEligibilityObservationCollector
{
    public function __construct(private EthereumAccountingRpc $rpc) {}

    /** @return array{reference: string, observation: array<string, mixed>} */
    public function capture(string $token): array
    {
        Gate::authorize('review-ethereum-accounting');
        $token = EthereumAccountingNumbers::address($token);
        $this->rpc->assertNetwork();
        $block = $this->rpc->block('finalized');
        $observation = ['chain_id' => 1, 'token_address' => $token,
            'code_sha256' => $this->rpc->codeHash($token, $block['hash']),
            'block_number' => $block['number'], 'block_hash' => $block['hash'], 'collected_at' => now()->toIso8601String()];
        $this->verify($observation);
        $reference = (string) Str::uuid();
        $this->cache()->put('ethereum-review.'.$reference, ['actor' => (string) Auth::id(), 'observation' => $observation], now()->addMinutes(20));

        return ['reference' => $reference, 'observation' => $observation];
    }

    /** @return array{chain_id: int, token_address: string, code_sha256: string, block_number: string, block_hash: string, collected_at: string} */
    public function collect(string $token, string $reference): array
    {
        Gate::authorize('review-ethereum-accounting');
        $stored = Str::isUuid($reference) ? $this->cache()->get('ethereum-review.'.$reference) : null;
        if (! is_array($stored) || $stored['actor'] !== (string) Auth::id() || $stored['observation']['token_address'] !== $token) {
            throw new DomainException('Trusted evidence is missing or expired. Collect evidence and confirm again.');
        }
        $this->verify($stored['observation']);

        return $stored['observation'];
    }

    private function verify(array $observation): void
    {
        $this->rpc->assertNetwork();
        $number = $observation['block_number'];
        $hex = '';
        do {
            $hex = dechex((int) bcmod($number, '16')).$hex;
            $number = bcdiv($number, '16', 0);
        } while ($number !== '0');
        $block = $this->rpc->block('0x'.$hex);
        if ($block['hash'] !== $observation['block_hash']
            || $this->rpc->codeHash($observation['token_address'], $block['hash']) !== $observation['code_sha256']
            || $this->rpc->block('0x'.$hex)['hash'] !== $observation['block_hash']) {
            throw new DomainException('Trusted evidence changed. Collect evidence and confirm again.');
        }
    }

    private function cache(): Repository
    {
        return Cache::store(config('services.ethereum.metadata_cache_store', 'file'));
    }
}
