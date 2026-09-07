<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\User;
use App\Models\WalletConnectionChallenge;
use App\Services\ApplicationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SolanaWalletConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_wallet_card_shows_only_the_current_users_verified_wallet(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        [$address, $secret] = $this->solanaKeypair();
        $challenge = $this->createChallenge($owner, $address);
        $this->postJson(route('wallets.solana.verify'), [
            'challenge_id' => $challenge->id,
            'signature' => base64_encode(sodium_crypto_sign_detached($challenge->message, $secret)),
        ])->assertOk();

        $this->get(route('account.edit'))->assertOk()->assertSee('Connected / Verified')->assertSee($address);
        $this->actingAs($other)->get(route('account.edit'))->assertOk()
            ->assertSee('No live wallet is connected.')->assertDontSee($address)
            ->assertSee(route('wallets.solana.challenge'))->assertSee(route('wallets.solana.verify'));

        $owner->connectedWallets()->update(['disconnected_at' => now()]);
        $this->actingAs($owner)->get(route('account.edit'))->assertOk()->assertDontSee($address);
    }

    public function test_challenge_uses_configured_product_branding(): void
    {
        app(ApplicationSettingsService::class)->update(['general.application_name' => 'Meme Scanner']);
        $user = User::factory()->create();
        [$address] = $this->solanaKeypair();
        $challenge = $this->createChallenge($user, $address);
        $this->assertStringStartsWith('Meme Scanner Wallet Verification', $challenge->message);
    }

    public function test_blank_application_name_falls_back_to_trimmed_configured_name(): void
    {
        config()->set('app.name', '  Configured Application  ');
        app(ApplicationSettingsService::class)->update(['general.application_name' => '   ']);
        $user = User::factory()->create();
        [$address] = $this->solanaKeypair();

        $challenge = $this->createChallenge($user, $address);

        $this->assertSame('Configured Application Wallet Verification', explode("\n", $challenge->message)[0]);
    }

    public function test_verified_user_can_create_and_complete_wallet_challenge(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();

        $response = $this->actingAs($user)->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $address,
                'provider' => 'phantom',
            ],
        );

        $response->assertOk()
            ->assertJsonStructure([
                'challenge_id',
                'message',
                'expires_at',
            ]);

        $challenge = WalletConnectionChallenge::findOrFail(
            $response->json('challenge_id'),
        );

        $this->assertStringContainsString(
            'Domain: '.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.url')),
            $challenge->message,
        );

        $this->assertStringContainsString(
            'Chain: '.Chain::Solana->value,
            $challenge->message,
        );

        $this->assertStringContainsString(
            'Wallet: '.$address,
            $challenge->message,
        );

        $this->assertStringContainsString(
            'Expires At: '.$challenge->expires_at->utc()->toIso8601String(),
            $challenge->message,
        );

        $this->assertStringContainsString(
            'It does not authorize a transaction.',
            $challenge->message,
        );

        $signature = sodium_crypto_sign_detached(
            $challenge->message,
            $secretKey,
        );

        $verify = $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode($signature),
            ],
        );

        $verify->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('wallet.address', $address)
            ->assertJsonPath('wallet.provider', 'phantom')
            ->assertJsonPath('wallet.chain', Chain::Solana->value);

        $wallet = ConnectedWallet::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame($address, $wallet->address);
        $this->assertNotNull($wallet->verified_at);
        $this->assertNull($wallet->disconnected_at);
        $this->assertNotNull($challenge->fresh()->used_at);
    }

    public function test_forged_signature_is_rejected(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address] = $this->solanaKeypair();
        [, $attackerSecretKey] = $this->solanaKeypair();

        $challenge = $this->createChallenge($user, $address);

        $forgedSignature = sodium_crypto_sign_detached(
            $challenge->message,
            $attackerSecretKey,
        );

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode($forgedSignature),
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->assertDatabaseCount('connected_wallets', 0);
        $this->assertNull($challenge->fresh()->used_at);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();

        $challenge = $this->createChallenge($user, $address);

        $challenge->update([
            'expires_at' => now()->subSecond(),
        ]);

        $signature = sodium_crypto_sign_detached(
            $challenge->message,
            $secretKey,
        );

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode($signature),
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('challenge_id');

        $this->assertDatabaseCount('connected_wallets', 0);
    }

    public function test_challenge_cannot_be_replayed(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();

        $challenge = $this->createChallenge($user, $address);

        $signature = base64_encode(
            sodium_crypto_sign_detached(
                $challenge->message,
                $secretKey,
            ),
        );

        $payload = [
            'challenge_id' => $challenge->id,
            'signature' => $signature,
        ];

        $this->actingAs($user)
            ->postJson(route('wallets.solana.verify'), $payload)
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('wallets.solana.verify'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('challenge_id');

        $this->assertDatabaseCount('connected_wallets', 1);
    }

    public function test_user_cannot_verify_another_users_challenge(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $attacker = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();

        $challenge = $this->createChallenge($owner, $address);

        $signature = sodium_crypto_sign_detached(
            $challenge->message,
            $secretKey,
        );

        $this->actingAs($attacker)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode($signature),
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('challenge_id');

        $this->assertDatabaseCount('connected_wallets', 0);
    }

    public function test_wallet_cannot_be_claimed_by_two_users(): void
    {
        $first = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $second = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();

        $firstChallenge = $this->createChallenge($first, $address);

        $firstSignature = sodium_crypto_sign_detached(
            $firstChallenge->message,
            $secretKey,
        );

        $this->actingAs($first)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $firstChallenge->id,
                'signature' => base64_encode($firstSignature),
            ],
        )->assertOk();

        // Challenge creation must not disclose whether this wallet
        // already belongs to another Jackyba account.
        $secondChallenge = $this->createChallenge($second, $address);

        $secondSignature = sodium_crypto_sign_detached(
            $secondChallenge->message,
            $secretKey,
        );

        $this->actingAs($second)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $secondChallenge->id,
                'signature' => base64_encode($secondSignature),
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('address');

        $this->assertDatabaseCount('connected_wallets', 1);

        $wallet = ConnectedWallet::query()->firstOrFail();

        $this->assertSame($first->id, $wallet->user_id);
        $this->assertSame($address, $wallet->address);

        // Failed ownership claim must not consume the challenge.
        $this->assertNull($secondChallenge->fresh()->used_at);
    }

    public function test_invalid_solana_address_is_rejected(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => 'not-a-solana-wallet',
                'provider' => 'phantom',
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('address');

        $this->assertDatabaseCount('wallet_connection_challenges', 0);
    }

    public function test_guest_cannot_create_or_verify_wallet_challenge(): void
    {
        $this->postJson(route('wallets.solana.challenge'), [])
            ->assertUnauthorized();

        $this->postJson(route('wallets.solana.verify'), [])
            ->assertUnauthorized();
    }

    public function test_unverified_customer_cannot_create_wallet_challenge(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        [$address] = $this->solanaKeypair();

        $this->actingAs($user)->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $address,
                'provider' => 'phantom',
            ],
        )->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('wallet_connection_challenges', 0);
    }

    public function test_user_can_replace_their_own_verified_wallet(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$firstAddress, $firstSecretKey] = $this->solanaKeypair();

        $firstChallenge = $this->createChallenge($user, $firstAddress);

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $firstChallenge->id,
                'signature' => base64_encode(
                    sodium_crypto_sign_detached(
                        $firstChallenge->message,
                        $firstSecretKey,
                    ),
                ),
            ],
        )->assertOk();

        [$secondAddress, $secondSecretKey] = $this->solanaKeypair();

        $secondChallenge = $this->createChallenge($user, $secondAddress);

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $secondChallenge->id,
                'signature' => base64_encode(
                    sodium_crypto_sign_detached(
                        $secondChallenge->message,
                        $secondSecretKey,
                    ),
                ),
            ],
        )->assertOk()
            ->assertJsonPath('wallet.address', $secondAddress);

        $this->assertDatabaseCount('connected_wallets', 1);

        $wallet = ConnectedWallet::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame($secondAddress, $wallet->address);
        $this->assertSame(
            ConnectedWallet::addressHash(Chain::Solana, $secondAddress),
            $wallet->address_hash,
        );
        $this->assertNotNull($wallet->verified_at);
        $this->assertNull($wallet->disconnected_at);
    }

    public function test_verified_user_can_disconnect_own_active_wallet(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();
        $challenge = $this->createChallenge($user, $address);

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode(
                    sodium_crypto_sign_detached($challenge->message, $secretKey)
                ),
            ],
        )->assertOk();

        $wallet = ConnectedWallet::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('wallets.solana.disconnect'))
            ->assertOk()
            ->assertJsonPath('disconnected', true)
            ->assertJsonPath('wallet.chain', Chain::Solana->value);

        $wallet->refresh();

        $this->assertNotNull($wallet->disconnected_at);
        $this->assertDatabaseHas('connected_wallets', [
            'id' => $wallet->id,
            'user_id' => $user->id,
            'address' => $address,
        ]);
    }

    public function test_user_cannot_disconnect_another_users_wallet(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $attacker = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();
        $challenge = $this->createChallenge($owner, $address);

        $this->actingAs($owner)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode(
                    sodium_crypto_sign_detached($challenge->message, $secretKey)
                ),
            ],
        )->assertOk();

        $wallet = ConnectedWallet::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $this->actingAs($attacker)
            ->postJson(route('wallets.solana.disconnect'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wallet');

        $this->assertNull($wallet->fresh()->disconnected_at);
    }

    public function test_guest_cannot_disconnect_wallet(): void
    {
        $this->postJson(route('wallets.solana.disconnect'))
            ->assertUnauthorized();
    }

    public function test_unverified_customer_cannot_disconnect_wallet(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->postJson(route('wallets.solana.disconnect'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_disconnect_without_active_wallet_is_handled_safely(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('wallets.solana.disconnect'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wallet');

        $this->assertDatabaseCount('connected_wallets', 0);
    }

    public function test_disconnected_wallet_is_not_shown_on_account_page(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();
        $challenge = $this->createChallenge($user, $address);

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode(
                    sodium_crypto_sign_detached($challenge->message, $secretKey)
                ),
            ],
        )->assertOk();

        $this->actingAs($user)
            ->postJson(route('wallets.solana.disconnect'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('account.edit'))
            ->assertOk()
            ->assertSee('No live wallet is connected.')
            ->assertDontSee($address);
    }

    public function test_active_wallet_uses_change_wallet_button_text(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$address, $secretKey] = $this->solanaKeypair();
        $challenge = $this->createChallenge($user, $address);

        $this->actingAs($user)->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge->id,
                'signature' => base64_encode(
                    sodium_crypto_sign_detached($challenge->message, $secretKey)
                ),
            ],
        )->assertOk();

        $this->actingAs($user)
            ->get(route('account.edit'))
            ->assertOk()
            ->assertSee('Change wallet');
    }

    public function test_verified_user_can_read_balance_for_own_active_wallet(): void
    {
        [$address, $secretKey] = $this->solanaKeypair();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $challenge = $this->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $address,
                'provider' => 'phantom',
            ]
        )->json();

        $signature = base64_encode(
            sodium_crypto_sign_detached(
                $challenge['message'],
                $secretKey
            )
        );

        $this->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge['challenge_id'],
                'signature' => $signature,
            ]
        )->assertOk();

        Http::fake([
            '*' => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'context' => ['slot' => 123],
                    'value' => 2_500_000_000,
                ],
                'id' => 1,
            ]),
        ]);

        $this->getJson(
            route('wallets.solana.balance')
        )
            ->assertOk()
            ->assertJsonPath('balance.chain', 'solana')
            ->assertJsonPath('balance.lamports', 2_500_000_000)
            ->assertJsonPath('balance.sol', '2.500000000');

        Http::assertSent(function ($request) use ($address): bool {
            return $request['method'] === 'getBalance'
                && $request['params'][0] === $address;
        });
    }

    public function test_balance_endpoint_does_not_accept_an_arbitrary_wallet_address(): void
    {
        [$ownAddress, $ownSecretKey] = $this->solanaKeypair();
        [$otherAddress] = $this->solanaKeypair();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $challenge = $this->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $ownAddress,
                'provider' => 'phantom',
            ]
        )->json();

        $signature = base64_encode(
            sodium_crypto_sign_detached(
                $challenge['message'],
                $ownSecretKey
            )
        );

        $this->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge['challenge_id'],
                'signature' => $signature,
            ]
        )->assertOk();

        Http::fake([
            '*' => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['value' => 100],
                'id' => 1,
            ]),
        ]);

        $this->getJson(
            route('wallets.solana.balance', [
                'address' => $otherAddress,
            ])
        )->assertOk();

        Http::assertSent(function ($request) use ($ownAddress, $otherAddress): bool {
            return $request['method'] === 'getBalance'
                && $request['params'][0] === $ownAddress
                && $request['params'][0] !== $otherAddress;
        });
    }

    public function test_disconnected_wallet_cannot_read_balance(): void
    {
        [$address, $secretKey] = $this->solanaKeypair();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $challenge = $this->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $address,
                'provider' => 'phantom',
            ]
        )->json();

        $signature = base64_encode(
            sodium_crypto_sign_detached(
                $challenge['message'],
                $secretKey
            )
        );

        $this->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge['challenge_id'],
                'signature' => $signature,
            ]
        )->assertOk();

        $this->postJson(
            route('wallets.solana.disconnect')
        )->assertOk();

        Http::fake();

        $this->getJson(
            route('wallets.solana.balance')
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'No active verified Solana wallet was found for this account.'
            );

        Http::assertNothingSent();
    }

    public function test_guest_cannot_read_wallet_balance(): void
    {
        $this->getJson(
            route('wallets.solana.balance')
        )->assertUnauthorized();
    }

    public function test_rpc_failure_returns_safe_balance_unavailable_response(): void
    {
        [$address, $secretKey] = $this->solanaKeypair();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $challenge = $this->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $address,
                'provider' => 'phantom',
            ]
        )->json();

        $signature = base64_encode(
            sodium_crypto_sign_detached(
                $challenge['message'],
                $secretKey
            )
        );

        $this->postJson(
            route('wallets.solana.verify'),
            [
                'challenge_id' => $challenge['challenge_id'],
                'signature' => $signature,
            ]
        )->assertOk();

        Http::fake([
            '*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => [
                    'code' => -32000,
                    'message' => 'RPC unavailable',
                ],
            ]),
        ]);

        $this->getJson(
            route('wallets.solana.balance')
        )
            ->assertStatus(503)
            ->assertJson([
                'message' => 'Unable to read the Solana wallet balance right now.',
            ]);

        Http::assertSent(function ($request) use ($address): bool {
            return $request['method'] === 'getBalance'
                && $request['params'][0] === $address;
        });
    }

    private function createChallenge(
        User $user,
        string $address,
    ): WalletConnectionChallenge {
        $response = $this->actingAs($user)->postJson(
            route('wallets.solana.challenge'),
            [
                'address' => $address,
                'provider' => 'phantom',
            ],
        );

        $response->assertOk();

        return WalletConnectionChallenge::findOrFail(
            $response->json('challenge_id'),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function solanaKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        return [
            $this->base58Encode($publicKey),
            $secretKey,
        ];
    }

    private function base58Encode(string $value): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

        $digits = [0];

        foreach (array_values(unpack('C*', $value)) as $byte) {
            $carry = $byte;

            for ($i = 0, $count = count($digits); $i < $count; $i++) {
                $carry += $digits[$i] << 8;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }

            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $leadingZeros = 0;

        for ($i = 0, $length = strlen($value); $i < $length && $value[$i] === "\x00"; $i++) {
            $leadingZeros++;
        }

        $encoded = str_repeat('1', $leadingZeros);

        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($i === count($digits) - 1 && $digits[$i] === 0) {
                continue;
            }

            $encoded .= $alphabet[$digits[$i]];
        }

        return $encoded;
    }
}
