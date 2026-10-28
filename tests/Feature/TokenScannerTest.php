<?php

namespace Tests\Feature;

use App\Models\SystemActivity;
use App\Models\TradingEngineScanRequest;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineClient;
use Mockery\MockInterface;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TokenScannerTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private const ENGINE_SCAN_ID = '01K9ABCDEFGHJKMNPQRSTVWXYZ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_scanner_routes_require_authentication(): void
    {
        $activity = SystemActivity::factory()->create(['action' => 'token-scan']);

        $this->get(route('scanner.index'))->assertRedirect(route('login'));
        $this->get(route('scanner.show', $activity))->assertRedirect(route('login'));
    }

    public function test_scanner_screen_matches_the_dedicated_workflow_and_preserves_existing_submission_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('scanner.index'))
            ->assertOk()
            ->assertViewIs('scanner.index')
            ->assertSee('Token Scanner')
            ->assertSee('New Token Scanner')
            ->assertSee('Scan History')
            ->assertSee('Provider Status')
            ->assertSee('Run New Scan')
            ->assertSee(route('dashboard.actions.store', 'token-scan'), false)
            ->assertSee(route('dashboard.actions.store', 'momentum-scan'), false)
            ->assertSee('data-theme-toggle', false)
            ->assertDontSee('Manual Scanner &amp; Operations', false)
            ->assertDontSee('PAPER Strategy');
    }

    public function test_scan_history_and_details_are_user_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->scanActivity($owner, ['label' => 'OWNER SCAN']);
        $this->scanActivity($other, ['label' => 'OTHER USER SCAN']);

        $this->actingAs($owner)
            ->get(route('scanner.index', ['tab' => 'history']))
            ->assertOk()
            ->assertViewHas('history', fn ($history): bool => $history->total() === 1
                && $history->first()['id'] === $owned->id);

        $this->actingAs($other)
            ->get(route('scanner.show', $owned))
            ->assertNotFound();
    }

    public function test_known_warning_scenario_is_presented_without_conflating_failed_and_skipped_candidates(): void
    {
        $user = User::factory()->create();
        $activity = $this->scanActivity($user, [
            'result_summary' => $this->warningSummary(),
            'finished_at' => now(),
        ]);
        TradingEngineScanRequest::factory()->create([
            'system_activity_id' => $activity->id,
            'user_id' => $user->id,
            'engine_scan_execution_id' => self::ENGINE_SCAN_ID,
            'status' => TradingEngineScanRequest::STATUS_PROJECTED,
        ]);
        $this->mock(TradingEngineClient::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('tokenScanCandidates')
                ->once()
                ->with(self::ENGINE_SCAN_ID, $user->id, 1, 20)
                ->andReturn($this->candidateResponse($user));
        });

        $response = $this->actingAs($user)->get(route('scanner.show', $activity));

        $response
            ->assertOk()
            ->assertSee('Completed with warnings')
            ->assertSeeText('8 candidates could not be evaluated because provider rate limiting is recorded on those candidate rows')
            ->assertSeeText('12 were skipped by configured eligibility filters')
            ->assertSee('PROVIDER_RATE_LIMITED')
            ->assertSee('DISCOVERY_LIQUIDITY_BELOW_MINIMUM')
            ->assertSee('HOLDERS_BELOW_MINIMUM')
            ->assertSee('Candidate failures did not make the overall execution fail')
            ->assertSee('Skipped candidates were rejected by eligibility filters and are not provider failures');
    }

    public function test_historical_scan_shows_candidate_diagnostics_as_unavailable_instead_of_querying_the_engine(): void
    {
        $user = User::factory()->create();
        $activity = $this->scanActivity($user, [
            'result_summary' => [
                'version' => 1,
                'kind' => 'scanner_execution',
                'scanner' => 'new-token',
                'chain' => 'solana',
                'outcome' => 'succeeded',
                'counts' => ['discovered' => 4],
            ],
        ]);
        $this->mock(TradingEngineClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('tokenScanCandidates');
        });

        $response = $this->actingAs($user)->get(route('scanner.show', $activity));

        $response
            ->assertOk()
            ->assertSee('Candidate details unavailable')
            ->assertSee('Candidate diagnostics were not projected for this historical Laravel scan.')
            ->assertViewHas('scan', fn (array $scan): bool => $scan['counts']['discovered'] === 4
                && $scan['counts']['examined'] === null
                && $scan['counts']['qualified'] === null);
    }

    public function test_candidate_pagination_is_forwarded_as_a_bounded_engine_read(): void
    {
        $user = User::factory()->create();
        $activity = $this->scanActivity($user, ['result_summary' => $this->warningSummary()]);
        TradingEngineScanRequest::factory()->create([
            'system_activity_id' => $activity->id,
            'user_id' => $user->id,
            'engine_scan_execution_id' => self::ENGINE_SCAN_ID,
        ]);
        $response = $this->candidateResponse($user);
        $response['candidates'] = [];
        $response['pagination'] = ['page' => 2, 'per_page' => 20, 'total' => 61, 'last_page' => 4];
        $this->mock(TradingEngineClient::class, function (MockInterface $mock) use ($user, $response): void {
            $mock->shouldReceive('tokenScanCandidates')
                ->once()
                ->with(self::ENGINE_SCAN_ID, $user->id, 2, 20)
                ->andReturn($response);
        });

        $this->actingAs($user)
            ->get(route('scanner.show', ['activity' => $activity, 'candidate_page' => 2]))
            ->assertOk()
            ->assertSee('Page 2 of 4');
    }

    public function test_history_filters_legacy_and_engine_scans_and_preserves_warning_state(): void
    {
        $user = User::factory()->create();
        $warning = $this->scanActivity($user, ['result_summary' => $this->warningSummary()]);
        TradingEngineScanRequest::factory()->create([
            'system_activity_id' => $warning->id,
            'user_id' => $user->id,
            'engine_scan_execution_id' => self::ENGINE_SCAN_ID,
        ]);
        $this->scanActivity($user, [
            'action' => 'momentum-scan',
            'label' => 'LEGACY MOMENTUM',
            'chain' => 'ethereum',
            'result_summary' => null,
        ]);

        $this->actingAs($user)
            ->get(route('scanner.index', [
                'tab' => 'history',
                'status' => 'completed_with_warnings',
                'scanner' => 'new-token',
                'network' => 'solana',
            ]))
            ->assertOk()
            ->assertSee('Completed with warnings')
            ->assertViewHas('history', fn ($history): bool => $history->total() === 1
                && $history->first()['id'] === $warning->id);
    }

    private function scanActivity(User $user, array $attributes = []): SystemActivity
    {
        return SystemActivity::factory()->create([
            'user_id' => $user->id,
            'action' => 'token-scan',
            'chain' => 'solana',
            'command' => 'tokens:scan',
            'label' => 'Run Token Scan — Solana',
            'status' => 'completed',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function warningSummary(): array
    {
        return [
            'version' => 1,
            'kind' => 'scanner_execution',
            'scanner' => 'new-token',
            'chain' => 'solana',
            'outcome' => 'succeeded_with_warnings',
            'zero_result' => true,
            'counts' => [
                'discovered' => 20,
                'examined' => 20,
                'qualified' => 0,
                'opportunities_created' => 0,
                'opportunities_existing' => 0,
                'skipped' => 12,
                'failed' => 8,
            ],
            'provider_failures' => [['code' => 'PROVIDER_RATE_LIMITED', 'count' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function candidateResponse(User $user): array
    {
        $candidates = [];
        for ($index = 0; $index < 20; $index++) {
            $failed = $index < 8;
            $candidates[] = [
                'address' => 'SyntheticTokenAddress'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'symbol' => 'T'.$index,
                'name' => 'Synthetic Token '.$index,
                'provider' => 'birdeye',
                'state' => $failed ? 'failed' : 'skipped',
                'reason_code' => $failed
                    ? 'PROVIDER_RATE_LIMITED'
                    : ($index === 19 ? 'HOLDERS_BELOW_MINIMUM' : 'DISCOVERY_LIQUIDITY_BELOW_MINIMUM'),
                'market' => null,
                'security_status' => 'not_reported',
                'opportunity_id' => null,
                'evaluated_at' => '2026-10-28T09:40:00.000Z',
            ];
        }

        return [
            'schema_version' => 1,
            'scan_execution_id' => self::ENGINE_SCAN_ID,
            'control_plane_user_id' => (string) $user->id,
            'candidates' => $candidates,
            'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 20, 'last_page' => 1],
        ];
    }
}
