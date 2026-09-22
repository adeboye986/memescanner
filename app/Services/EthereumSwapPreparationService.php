<?php

namespace App\Services;

use App\Chain;
use App\Exceptions\EthereumPreparationException;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class EthereumSwapPreparationService
{
    public function __construct(
        private EthereumService $ethereum,
        private ZeroXSwapService $swaps,
        private EthereumQuoteLimitService $limits,
        private EthereumSwapInputRules $inputs,
    ) {}

    /** @param array{buy_token: string, sell_amount_wei: string, slippage_bps: int|string} $input */
    public function manual(User $user, array $input): EthereumSwapAttempt
    {
        $wallet = $user->connectedWallets()->verifiedEthereum()->first();
        if (! $wallet) {
            throw new EthereumPreparationException('No active verified Ethereum wallet was found.');
        }
        $address = strtolower($wallet->address);
        $prepared = $this->quote($wallet, $user->id, $address, $input);

        return DB::transaction(function () use ($wallet, $user, $address, $input, $prepared): EthereumSwapAttempt {
            $current = ConnectedWallet::query()->lockForUpdate()->find($wallet->id);
            $this->assertWallet($current, $user->id, $address);
            Validator::make($input, $this->inputs->rules())->validate();

            return EthereumSwapAttempt::query()->create([
                'user_id' => $user->id, 'connected_wallet_id' => $wallet->id,
                'buy_token' => strtolower($input['buy_token']), 'sell_amount_wei' => $input['sell_amount_wei'],
                'slippage_bps' => (int) $input['slippage_bps'], ...$prepared, 'status' => 'prepared',
            ]);
        });
    }

    /**
     * Must be called outside a database transaction. ZeroXSwapService validates the
     * provider response and constructs the wallet-bound Ethereum transaction.
     *
     * @param  array{buy_token: string, sell_amount_wei: string, slippage_bps: int|string}  $input
     * @return array{quote_id: ?string, transaction_payload: array<string, string>, expires_at: Carbon}
     */
    public function quote(ConnectedWallet $wallet, int $userId, string $address, array $input): array
    {
        $this->assertWallet($wallet, $userId, $address);
        Validator::make($input, $this->inputs->rules())->validate();
        try {
            $balance = $this->ethereum->getBalanceWei($address);
        } catch (RuntimeException $exception) {
            throw new EthereumPreparationException('Unable to verify the Ethereum wallet balance right now.', 503, $exception);
        }
        if ($this->limits->exceeds($input['sell_amount_wei'], $balance)) {
            throw new EthereumPreparationException('The connected wallet has insufficient ETH for this swap.');
        }
        try {
            $quote = $this->swaps->quote($address, strtolower($input['buy_token']), $input['sell_amount_wei'], (int) $input['slippage_bps']);
        } catch (RuntimeException $exception) {
            throw new EthereumPreparationException('Unable to prepare the Ethereum swap right now.', 503, $exception);
        }
        $required = $this->addUnsignedIntegers($input['sell_amount_wei'], $quote['network_fee_wei'] ?? '0');
        if ($this->limits->exceeds($required, $balance)) {
            throw new EthereumPreparationException('The connected wallet has insufficient ETH for the swap and estimated network fee.');
        }

        return ['quote_id' => $quote['quote_id'], 'transaction_payload' => $quote['transaction'], 'expires_at' => now()->addMinute()];
    }

    public function assertWallet(?ConnectedWallet $wallet, int $userId, string $address): void
    {
        if (! $wallet || $wallet->user_id !== $userId || $wallet->chain !== Chain::Ethereum
            || ! $wallet->isVerified() || strtolower($wallet->address) !== $address) {
            throw new EthereumPreparationException('The reserved Ethereum wallet is no longer available or has changed.');
        }
    }

    private function addUnsignedIntegers(string $left, string $right): string
    {
        $carry = 0;
        $sum = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $total = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0) + $carry;
            $sum = ($total % 10).$sum;
            $carry = intdiv($total, 10);
        }

        return ltrim($sum, '0') ?: '0';
    }
}
