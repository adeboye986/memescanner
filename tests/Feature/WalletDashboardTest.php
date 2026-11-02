<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\PaperWallet;
use App\Models\TradingEnginePaperWalletProjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('w', 32))]);
    }

    public function test_wallet_screen_requires_authentication_and_uses_the_shared_application_shell(): void
    {
        $this->get(route('wallets.index'))->assertRedirect(route('login'));

        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('data-wallets-page', false)
            ->assertSee('Connect and manage your verified trading wallets.')
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-shell-section="wallets"', false)
            ->assertSee('data-theme-toggle', false)
            ->assertSee('data-wallet-network-dialog', false)
            ->assertSee('Solana Mainnet')
            ->assertSee('Ethereum Mainnet');
    }

    public function test_connected_wallets_are_verified_active_and_strictly_user_scoped(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        $solanaAddress = '5HueCGU8rMjxEXxiPuD5BDuRaMhLFWYdPmxK1uFi8d7G';
        $ethereumAddress = '0x1111111111111111111111111111111111111111';
        $otherAddress = '0x2222222222222222222222222222222222222222';

        $this->createConnectedWallet($user, Chain::Solana, $solanaAddress, 'phantom');
        $this->createConnectedWallet($user, Chain::Ethereum, $ethereumAddress, 'metamask');
        $this->createConnectedWallet($otherUser, Chain::Ethereum, $otherAddress, 'metamask');

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('2 of 2 supported networks verified')
            ->assertSee('Verified')
            ->assertSee($solanaAddress)
            ->assertSee($ethereumAddress)
            ->assertSee('Phantom')
            ->assertSee('MetaMask')
            ->assertSee('https://solscan.io/account/'.rawurlencode($solanaAddress), false)
            ->assertSee('https://etherscan.io/address/'.rawurlencode($ethereumAddress), false)
            ->assertDontSee($otherAddress);
    }

    public function test_disconnected_and_unverified_wallet_records_are_not_presented_as_connected(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $disconnectedAddress = 'BPFLoader1111111111111111111111111111111111';
        $unverifiedAddress = '0x3333333333333333333333333333333333333333';

        $this->createConnectedWallet($user, Chain::Solana, $disconnectedAddress, 'phantom', [
            'disconnected_at' => now(),
        ]);
        $this->createConnectedWallet($user, Chain::Ethereum, $unverifiedAddress, 'metamask', [
            'verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('0 of 2 supported networks verified')
            ->assertSee('No verified Solana wallet')
            ->assertSee('No verified Ethereum wallet')
            ->assertSee('Not connected')
            ->assertDontSee($disconnectedAddress)
            ->assertDontSee($unverifiedAddress);
    }

    public function test_engine_and_legacy_paper_wallets_remain_source_currency_and_user_separated(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);

        $this->createEnginePaperWallet($user, 'solana:mainnet', 'SOL', '0.75', '0.25');
        $this->createEnginePaperWallet($otherUser, 'solana:mainnet', 'SOL', '99', '1');
        $this->createLegacyPaperWallet($user, 'ethereum', 'ETH', 4.5, 0.5);
        $this->createLegacyPaperWallet($otherUser, 'ethereum', 'ETH', 88, 2);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('Engine PAPER')
            ->assertSee('Legacy PAPER')
            ->assertSee('0.75 SOL')
            ->assertSee('0.25 SOL')
            ->assertSee('4.5 ETH')
            ->assertSee('0.5 ETH')
            ->assertSee('Simulated balances remain separate from verified blockchain wallets and from each other.')
            ->assertDontSee('99 SOL')
            ->assertDontSee('88 ETH')
            ->assertDontSee('5.75');
    }

    public function test_missing_paper_wallet_sources_render_unavailable_instead_of_zero(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('No Engine PAPER wallet reported')
            ->assertSee('No Legacy PAPER wallet reported')
            ->assertSee('Balances are unavailable until an authoritative wallet record exists.')
            ->assertDontSee('0 SOL')
            ->assertDontSee('0 ETH');
    }

    public function test_unverified_users_receive_a_verification_path_without_connect_controls(): void
    {
        $user = User::factory()->unverified()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('Verify email to connect')
            ->assertSee(route('verification.notice'), false)
            ->assertDontSee('data-wallet-page-connect', false)
            ->assertDontSee('data-wallet-connect', false)
            ->assertDontSee('data-eth-connect', false);
    }

    public function test_account_retains_an_accessible_compatibility_link_without_embedding_wallet_controls(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('account.edit').'#wallets')
            ->assertOk()
            ->assertSee('Wallets have moved')
            ->assertSee(route('wallets.index'), false)
            ->assertDontSee('data-wallet-connect', false)
            ->assertDontSee('data-eth-connect', false)
            ->assertDontSee(route('wallets.solana.challenge'), false);
    }

    public function test_wallet_screen_discloses_supported_provider_and_mobile_boundaries_without_inventing_protocols(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('Phantom, Solflare, compatible')
            ->assertSee('Phantom, MetaMask, EIP-1193')
            ->assertSee('QR and WalletConnect sessions are not implemented.')
            ->assertDontSee('Connect with WalletConnect')
            ->assertDontSee('Scan QR code')
            ->assertDontSee('Enable LIVE');
    }

    /** @param array<string, mixed> $attributes */
    private function createConnectedWallet(
        User $user,
        Chain $chain,
        string $address,
        string $provider,
        array $attributes = [],
    ): ConnectedWallet {
        return ConnectedWallet::query()->create(array_merge([
            'user_id' => $user->id,
            'chain' => $chain,
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash($chain, $address),
            'provider' => $provider,
            'verified_at' => now(),
            'last_connected_at' => now(),
            'disconnected_at' => null,
        ], $attributes));
    }

    private function createEnginePaperWallet(
        User $user,
        string $network,
        string $currency,
        string $available,
        string $invested,
    ): TradingEnginePaperWalletProjection {
        return TradingEnginePaperWalletProjection::query()->create([
            'engine_wallet_id' => fake()->uuid(),
            'user_id' => $user->id,
            'network_id' => $network,
            'currency' => $currency,
            'opening_balance_native' => (string) ((float) $available + (float) $invested),
            'available_balance_native' => $available,
            'invested_balance_native' => $invested,
            'realized_pnl_native' => '0',
            'last_event_id' => fake()->uuid(),
            'last_event_occurred_at' => now(),
            'last_payload_sha256' => hash('sha256', fake()->uuid()),
            'projected_at' => now(),
        ]);
    }

    private function createLegacyPaperWallet(
        User $user,
        string $chain,
        string $currency,
        float $available,
        float $invested,
    ): PaperWallet {
        return PaperWallet::query()->create([
            'user_id' => $user->id,
            'name' => 'default',
            'chain' => $chain,
            'currency' => $currency,
            'starting_balance_sol' => $available + $invested,
            'available_balance_sol' => $available,
            'invested_balance_sol' => $invested,
            'realized_pnl_sol' => 0,
        ]);
    }
}
