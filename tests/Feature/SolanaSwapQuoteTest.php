<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\TokenScan;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\SolanaSwapQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SolanaSwapQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_customer_can_request_normalized_read_only_quote(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        $this->createTokenMetadata();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.jup.ag/swap/v1/quote*' => Http::response($this->providerQuote()),
            'https://api.coingecko.com/api/v3/simple/price*' => Http::response([
                'solana' => ['usd' => 200, 'last_updated_at' => now()->subSeconds(30)->timestamp],
            ]),
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => 10_000_000], 'id' => 1]),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertOk()
            ->assertJsonPath('quote.input.mint', SolanaSwapQuoteService::SOL_MINT)
            ->assertJsonPath('quote.input.amount', '10000000')
            ->assertJsonPath('quote.output.mint', $this->outputMint())
            ->assertJsonPath('quote.output.amount', '2500000')
            ->assertJsonPath('quote.output.amount_formatted', '2.5')
            ->assertJsonPath('quote.output.symbol', 'USDC')
            ->assertJsonPath('quote.output.decimals', 6)
            ->assertJsonPath('quote.minimum_received', '2475000')
            ->assertJsonPath('quote.minimum_received_formatted', '2.475')
            ->assertJsonPath('quote.route.0.fee_amount', null)
            ->assertJsonPath('quote.route.0.fee_mint', null)
            ->assertJsonPath('quote.spend_usd', '2.00');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_contains($request->url(), '/quote'));
        Http::assertNotSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return preg_match('#/(swap|swap-instructions)$#', $path) === 1;
        });
    }

    public function test_guest_and_unverified_customer_are_blocked(): void
    {
        Http::preventStrayRequests();
        $this->postJson(route('wallets.solana.quote'), $this->payload())->assertUnauthorized();

        $user = User::factory()->create(['email_verified_at' => null, 'is_admin' => false]);
        $this->createVerifiedWallet($user);
        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertRedirect(route('verification.notice'));

        Http::assertNothingSent();
    }

    public function test_disconnected_wallet_cannot_request_quote(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $this->createVerifiedWallet($user);
        $this->createTokenMetadata();
        $wallet->update(['disconnected_at' => now()]);
        Http::preventStrayRequests();

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No active verified Solana wallet was found for this account.');

        Http::assertNothingSent();
    }

    public function test_client_cannot_override_the_authenticated_wallet_context(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = $this->createVerifiedWallet($user);
        $this->createTokenMetadata();
        Http::fake([
            'https://api.jup.ag/swap/v1/quote*' => Http::response($this->providerQuote()),
            'https://api.coingecko.com/api/v3/simple/price*' => Http::response([
                'solana' => ['usd' => 200, 'last_updated_at' => now()->timestamp],
            ]),
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => 10_000_000], 'id' => 1]),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), [
            ...$this->payload(),
            'wallet_address' => $this->outputMint(),
        ])->assertOk();

        $this->assertSame($wallet->id, $user->connectedWallets()->whereNull('disconnected_at')->sole()->id);
        Http::assertSentCount(3);
    }

    public function test_native_sol_trade_limit_accepts_boundary_and_rejects_one_extra_lamport(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        $this->createTokenMetadata();
        app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => 0.01]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.jup.ag/swap/v1/quote*' => Http::response($this->providerQuote()),
            'https://api.coingecko.com/api/v3/simple/price*' => Http::response([
                'solana' => ['usd' => 200, 'last_updated_at' => now()->timestamp],
            ]),
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => 1_000_000_000], 'id' => 1]),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertOk();

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), [
            ...$this->payload(),
            'amount' => '10000001',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        Http::assertSentCount(3);
    }

    public function test_spend_exactly_equal_to_wallet_balance_is_accepted(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        $this->createTokenMetadata();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.jup.ag/swap/v1/quote*' => Http::response($this->providerQuote()),
            'https://api.coingecko.com/api/v3/simple/price*' => Http::response([
                'solana' => ['usd' => 200, 'last_updated_at' => now()->timestamp],
            ]),
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => 10_000_000], 'id' => 1]),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertOk();

        Http::assertSentCount(3);
    }

    public function test_spend_above_current_wallet_balance_is_rejected_before_quote_request(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => 9_999_999], 'id' => 1]),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount')
            ->assertJsonPath('message', 'The requested SOL amount exceeds the connected wallet balance.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['method'] === 'getBalance');
    }

    public function test_wallet_balance_connection_failure_returns_controlled_service_error(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::failedConnection('timeout'),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Unable to verify the available SOL balance right now.']);
    }

    public function test_invalid_mint_amount_and_slippage_are_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        Http::preventStrayRequests();

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), [...$this->payload(), 'output_mint' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('output_mint');
        $this->actingAs($user)->postJson(route('wallets.solana.quote'), [...$this->payload(), 'amount' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->actingAs($user)->postJson(route('wallets.solana.quote'), [...$this->payload(), 'slippage_bps' => 501])
            ->assertUnprocessable()->assertJsonValidationErrors('slippage_bps');

        Http::assertNothingSent();
    }

    public function test_provider_failure_returns_safe_service_unavailable_response(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->createVerifiedWallet($user);
        Http::fake([
            'https://api.jup.ag/swap/v1/quote*' => Http::response(['provider_secret' => 'hidden'], 500),
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => 10_000_000], 'id' => 1]),
        ]);

        $this->actingAs($user)->postJson(route('wallets.solana.quote'), $this->payload())
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Unable to retrieve a Solana swap quote right now.']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'input_mint' => SolanaSwapQuoteService::SOL_MINT,
            'output_mint' => $this->outputMint(),
            'amount' => '10000000',
            'slippage_bps' => 100,
        ];
    }

    /** @return array<string, mixed> */
    private function providerQuote(): array
    {
        return [
            'inputMint' => SolanaSwapQuoteService::SOL_MINT,
            'inAmount' => '10000000',
            'outputMint' => $this->outputMint(),
            'outAmount' => '2500000',
            'otherAmountThreshold' => '2475000',
            'swapMode' => 'ExactIn',
            'slippageBps' => 100,
            'priceImpactPct' => '0.001',
            'routePlan' => [[
                'swapInfo' => [
                    'ammKey' => 'GoonFi1111111111111111111111111111111111111',
                    'label' => 'GoonFi V2',
                    'inputMint' => SolanaSwapQuoteService::SOL_MINT,
                    'outputMint' => $this->outputMint(),
                    'inAmount' => '10000000',
                    'outAmount' => '2500000',
                    'updateContextSlot' => '445090017',
                ],
                'percent' => 100,
                'bps' => null,
            ]],
        ];
    }

    private function createVerifiedWallet(User $user): ConnectedWallet
    {
        $address = SolanaSwapQuoteService::SOL_MINT;

        return $user->connectedWallets()->create([
            'chain' => Chain::Solana,
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(Chain::Solana, $address),
            'provider' => 'phantom',
            'verified_at' => now(),
            'last_connected_at' => now(),
        ]);
    }

    private function outputMint(): string
    {
        return 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    }

    private function createTokenMetadata(): TokenScan
    {
        return TokenScan::query()->create([
            'chain' => Chain::Solana,
            'address' => $this->outputMint(),
            'symbol' => 'USDC',
            'raw_data' => ['decimals' => 6],
            'first_seen_at' => now(),
            'last_scanned_at' => now(),
        ]);
    }
}
