<?php

namespace App\Services\TradingEngine;

use App\Models\ApplicationSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class TradingEngineLiveReadiness
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARNING = 'warning';

    public const STATUS_BLOCKING = 'blocking';

    private const ATTEMPT_INSPECTION_LIMIT = 500;

    /** @var list<array{group: string, status: string, name: string, message: string}> */
    private array $checks = [];

    public function __construct(
        private TradingEngineLiveAttemptInspector $attempts,
        private Schedule $schedule,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     overall: string,
     *     chain: string,
     *     checks: list<array{group: string, status: string, name: string, message: string}>
     * }
     */
    public function inspect(string $chain = 'all'): array
    {
        $chain = strtolower(trim($chain));

        if (! in_array($chain, ['all', 'ethereum', 'solana'], true)) {
            throw new InvalidArgumentException('The chain must be ethereum, solana, or all.');
        }

        $this->checks = [];
        $this->checkFlags($chain);
        $this->checkEngineAuthentication();
        $this->checkWebhookSecurity();
        $this->checkRuntime();
        $this->checkProjectionSchema();

        if ($this->includes($chain, 'ethereum')) {
            $this->checkEthereumSchema();
            $this->checkEthereumScheduler();
            $this->checkEthereumProviders();
            $this->checkAttempts('ethereum');
        }

        if ($this->includes($chain, 'solana')) {
            $this->checkSolanaSchema();
            $this->checkSolanaScheduler();
            $this->checkSolanaProviders();
            $this->checkAttempts('solana');
        }

        $ready = ! collect($this->checks)->contains(
            fn (array $check): bool => $check['status'] === self::STATUS_BLOCKING,
        );

        return [
            'ready' => $ready,
            'overall' => $ready ? 'READY' : 'NOT READY',
            'chain' => $chain,
            'checks' => $this->checks,
        ];
    }

    private function checkFlags(string $chain): void
    {
        $flags = [
            'enabled' => 'Base Trading Engine integration',
            'opportunity_export_enabled' => 'Opportunity export',
            'opportunity_projection_enabled' => 'Opportunity projection',
            'evaluation_consumption_enabled' => 'Evaluation consumption boundary',
            'decision_boundary_enabled' => 'Decision boundary',
            'live_decision_integration_enabled' => 'LIVE decision integration',
            'live_preparation_enabled' => 'LIVE preparation',
        ];

        foreach ($flags as $key => $name) {
            $enabled = config("services.trading_engine.{$key}", false) === true;
            $this->add(
                'Configuration',
                $enabled ? self::STATUS_PASS : self::STATUS_BLOCKING,
                $name,
                $enabled ? 'Enabled.' : 'Required rollout flag is disabled.',
            );
        }

        if ($this->includes($chain, 'ethereum')) {
            $recoveryEnabled = config('services.trading_engine.live_recovery_enabled', false) === true;
            $this->add(
                'Configuration',
                $recoveryEnabled ? self::STATUS_PASS : self::STATUS_WARNING,
                'Ethereum interrupted-broadcast recovery',
                $recoveryEnabled ? 'Enabled.' : 'Disabled; operator-assisted recovery remains required.',
            );
        }

        if ($this->includes($chain, 'solana')) {
            $solanaEnabled = config('services.trading_engine.solana_live_integration_enabled', false) === true;
            $this->add(
                'Configuration',
                $solanaEnabled ? self::STATUS_PASS : self::STATUS_BLOCKING,
                'Solana LIVE integration',
                $solanaEnabled ? 'Enabled.' : 'Required rollout flag is disabled.',
            );
        }

        $this->checkFlagDependencies($chain, array_keys($flags));
    }

    /** @param list<string> $orderedCommonFlags */
    private function checkFlagDependencies(string $chain, array $orderedCommonFlags): void
    {
        $inconsistent = false;

        foreach ($orderedCommonFlags as $index => $flag) {
            if (config("services.trading_engine.{$flag}", false) !== true) {
                foreach (array_slice($orderedCommonFlags, $index + 1) as $downstream) {
                    if (config("services.trading_engine.{$downstream}", false) === true) {
                        $inconsistent = true;
                    }
                }
            }
        }

        $commonReady = collect($orderedCommonFlags)->every(
            fn (string $flag): bool => config("services.trading_engine.{$flag}", false) === true,
        );

        if ($this->includes($chain, 'ethereum')
            && config('services.trading_engine.live_recovery_enabled', false) === true
            && ! $commonReady) {
            $inconsistent = true;
        }

        if ($this->includes($chain, 'solana')
            && config('services.trading_engine.solana_live_integration_enabled', false) === true
            && ! $commonReady) {
            $inconsistent = true;
        }

        $this->add(
            'Configuration',
            $inconsistent ? self::STATUS_BLOCKING : self::STATUS_PASS,
            'Rollout flag dependency order',
            $inconsistent
                ? 'A downstream LIVE capability is enabled while a required upstream boundary is disabled.'
                : 'No inconsistent downstream rollout flag was detected.',
        );
    }

    private function checkEngineAuthentication(): void
    {
        $baseUrl = config('services.trading_engine.base_url');
        $privateKey = config('services.trading_engine.private_key_base64');
        $decodedKey = is_string($privateKey) ? base64_decode($privateKey, true) : false;
        $connectTimeout = (int) config('services.trading_engine.connect_timeout_seconds', 3);
        $timeout = (int) config('services.trading_engine.timeout_seconds', 8);
        $lifetime = (int) config('services.trading_engine.assertion_lifetime_seconds', 30);
        $valid = is_string($baseUrl)
            && $this->validEngineUrl($baseUrl)
            && $this->configuredString('service_issuer')
            && $this->configuredString('service_audience')
            && $this->configuredString('service_subject')
            && is_string($decodedKey)
            && strlen($decodedKey) === 64
            && $lifetime >= 1
            && $lifetime <= 300
            && $connectTimeout >= 1
            && $timeout >= $connectTimeout
            && $timeout <= 60;

        $this->add(
            'Security',
            $valid ? self::STATUS_PASS : self::STATUS_BLOCKING,
            'Engine endpoint and service authentication',
            $valid ? 'Configured with valid structural bounds.' : 'Missing or invalid secure engine authentication configuration.',
        );
    }

    private function checkWebhookSecurity(): void
    {
        $secret = config('services.trading_engine.webhook_secret');
        $tolerance = config('services.trading_engine.webhook_timestamp_tolerance_seconds');
        $bodyLimit = config('services.trading_engine.webhook_body_max_bytes');
        $rateLimit = config('services.trading_engine.webhook_rate_limit_per_minute');
        $valid = is_string($secret)
            && strlen(trim($secret)) >= 32
            && is_int($tolerance)
            && $tolerance >= 1
            && $tolerance <= 300
            && is_int($bodyLimit)
            && $bodyLimit >= 1024
            && $bodyLimit <= 1048576
            && is_int($rateLimit)
            && $rateLimit >= 1;

        $this->add(
            'Security',
            $valid ? self::STATUS_PASS : self::STATUS_BLOCKING,
            'Authenticated webhook ingress',
            $valid ? 'Configured with replay, size, and rate-limit bounds.' : 'Missing or invalid webhook security configuration.',
        );
    }

    private function checkRuntime(): void
    {
        $sodium = $this->sodiumAvailable();
        $bcMath = $this->bcMathAvailable();

        $this->add('Runtime', $sodium ? self::STATUS_PASS : self::STATUS_BLOCKING, 'PHP Sodium', $sodium ? 'Available.' : 'Required extension is unavailable.');
        $this->add('Runtime', $bcMath ? self::STATUS_PASS : self::STATUS_BLOCKING, 'PHP BCMath', $bcMath ? 'Available.' : 'Required extension is unavailable.');
    }

    protected function sodiumAvailable(): bool
    {
        return extension_loaded('sodium') && function_exists('sodium_crypto_sign_detached');
    }

    protected function bcMathAvailable(): bool
    {
        return extension_loaded('bcmath') && function_exists('bcadd');
    }

    private function checkProjectionSchema(): void
    {
        $valid = $this->tablesHaveColumns([
            'trading_engine_event_inbox' => [
                'event_id', 'event_type', 'schema_version', 'occurred_at', 'producer', 'aggregate_type',
                'aggregate_id', 'aggregate_version', 'correlation_id', 'causation_id', 'idempotency_key',
                'traceparent', 'payload_sha256', 'raw_body_sha256', 'event_envelope', 'payload',
                'handling_status', 'handling_attempts', 'handling_error_code', 'next_handling_at',
                'received_at', 'handled_at',
            ],
            'trading_engine_opportunity_links' => [
                'trade_opportunity_id', 'engine_opportunity_id', 'recorded_event_id', 'user_id',
                'discovery_key', 'scanner', 'network_id', 'asset_address', 'recorded_at', 'linked_at',
            ],
            'trading_engine_opportunity_evaluations' => [
                'opportunity_link_id', 'trade_opportunity_id', 'evaluation_id', 'engine_opportunity_id',
                'evaluation_event_id', 'recorded_event_id', 'policy_key', 'policy_version', 'algorithm_key',
                'algorithm_version', 'policy_definition_sha256', 'source_request_sha256',
                'evaluation_input_sha256', 'result_sha256', 'outcome', 'reason_codes', 'advisory_codes',
                'evidence', 'correlation_id', 'traceparent', 'evaluated_at', 'event_received_at', 'projected_at',
            ],
        ]);

        $this->add('Database', $valid ? self::STATUS_PASS : self::STATUS_BLOCKING, 'Projection and evaluation schema', $valid ? 'Required tables and columns exist.' : 'Required projection or evaluation schema is missing.');
    }

    private function checkEthereumSchema(): void
    {
        $valid = $this->tablesHaveColumns([
            'ethereum_swap_attempts' => [
                'trade_opportunity_id', 'wallet_address', 'preparation_token', 'preparation_expires_at',
                'revalidation_data', 'preparation_origin', 'preparation_binding_sha256',
                'signing_requested_at', 'signing_claim_hash', 'signing_armed_at', 'transaction_hash',
                'status', 'submitted_at', 'expires_at',
            ],
            'live_positions' => ['trade_opportunity_id', 'ethereum_swap_attempt_id'],
        ]);

        $this->add('Database', $valid ? self::STATUS_PASS : self::STATUS_BLOCKING, 'Ethereum LIVE integrity schema', $valid ? 'Required attempt, integrity, and position-link columns exist.' : 'Required Ethereum LIVE schema is missing.');
    }

    private function checkSolanaSchema(): void
    {
        $valid = $this->tablesHaveColumns([
            'solana_swap_attempts' => [
                'trade_opportunity_id', 'wallet_address', 'input_mint', 'output_mint', 'network',
                'preparation_token', 'preparation_expires_at', 'preparation_origin',
                'preparation_binding_sha256', 'signing_requested_at', 'signing_claim_hash',
                'signing_armed_at', 'request_id', 'message_hash', 'recent_blockhash',
                'prepared_transaction', 'status', 'transaction_signature', 'submitted_at', 'expires_at',
                'acquired_raw_amount', 'token_decimals',
            ],
            'live_positions' => ['trade_opportunity_id', 'solana_swap_attempt_id'],
        ]);

        $this->add('Database', $valid ? self::STATUS_PASS : self::STATUS_BLOCKING, 'Solana LIVE integrity schema', $valid ? 'Required attempt, integrity, accounting, and position-link columns exist.' : 'Required Solana LIVE schema is missing.');
    }

    private function checkEthereumScheduler(): void
    {
        $this->checkScheduler('Ethereum reconciliation and expiry', [
            'ethereum:reconcile-inventory',
            'ethereum:expire-prepared-swaps',
            'ethereum:reconcile-submitted-swaps',
        ]);
    }

    private function checkSolanaScheduler(): void
    {
        $this->checkScheduler('Solana reconciliation and expiry', [
            'solana:expire-prepared-swaps',
            'solana:reconcile-submitted-swaps',
        ]);
    }

    /** @param list<string> $commands */
    private function checkScheduler(string $name, array $commands): void
    {
        try {
            $events = collect($this->schedule->events());
            $valid = collect($commands)->every(function (string $command) use ($events): bool {
                $event = $events->first(
                    fn ($candidate): bool => str_contains((string) ($candidate->command ?? ''), $command),
                );

                return $event !== null
                    && $event->expression === '* * * * *'
                    && $event->withoutOverlapping === true;
            });
        } catch (Throwable) {
            $valid = false;
        }

        $this->add('Scheduler', $valid ? self::STATUS_PASS : self::STATUS_BLOCKING, $name, $valid ? 'Registered every minute with overlap protection.' : 'A required schedule or overlap guard is missing.');
    }

    private function checkEthereumProviders(): void
    {
        $valid = $this->validHttpsUrl(config('services.ethereum.rpc_url'))
            && $this->validHttpsUrl(config('services.zero_x.base_url'))
            && is_string(config('services.zero_x.api_key'))
            && trim((string) config('services.zero_x.api_key')) !== '';

        $this->add('Providers', $valid ? self::STATUS_PASS : self::STATUS_BLOCKING, 'Ethereum RPC and 0x', $valid ? 'Required secure provider configuration is present.' : 'Required secure Ethereum provider configuration is missing.');
    }

    private function checkSolanaProviders(): void
    {
        $validatorKey = config('services.solana_transaction_validator.api_key');
        $valid = $this->validHttpsUrl($this->solanaRpcUrl())
            && $this->validHttpsUrl(config('services.jupiter.base_url'))
            && $this->validHttpsUrl(config('services.jupiter.swap_v2_base_url'))
            && $this->validHttpsUrl(config('services.solana_transaction_validator.url'))
            && is_string($validatorKey)
            && trim($validatorKey) !== '';

        $this->add('Providers', $valid ? self::STATUS_PASS : self::STATUS_BLOCKING, 'Solana RPC, Jupiter, and transaction validator', $valid ? 'Required secure provider configuration is present.' : 'Required secure Solana provider configuration is missing.');
    }

    private function checkAttempts(string $chain): void
    {
        try {
            $attempts = $this->attempts->inspect($chain, self::ATTEMPT_INSPECTION_LIMIT);
        } catch (Throwable) {
            $this->add('Operations', self::STATUS_BLOCKING, ucfirst($chain).' unresolved LIVE attempts', 'Attempt inspection is unavailable.');

            return;
        }

        if ($attempts->count() >= self::ATTEMPT_INSPECTION_LIMIT) {
            $this->add('Operations', self::STATUS_BLOCKING, ucfirst($chain).' unresolved LIVE attempts', 'The bounded inspection limit was reached; complete safety cannot be established.');

            return;
        }

        $classifications = $attempts->countBy('classification');
        $blocking = $classifications->get(TradingEngineLiveAttemptInspector::CLASSIFICATION_UNCERTAIN_BROADCAST, 0)
            + $classifications->get(TradingEngineLiveAttemptInspector::CLASSIFICATION_ATTENTION, 0);
        $known = [
            TradingEngineLiveAttemptInspector::CLASSIFICATION_ACTIVE,
            TradingEngineLiveAttemptInspector::CLASSIFICATION_ATTENTION,
            TradingEngineLiveAttemptInspector::CLASSIFICATION_RECONCILIATION,
            TradingEngineLiveAttemptInspector::CLASSIFICATION_SAFE_TO_CLEAN,
            TradingEngineLiveAttemptInspector::CLASSIFICATION_UNCERTAIN_BROADCAST,
        ];
        $unknown = $classifications->keys()->contains(fn (string $classification): bool => ! in_array($classification, $known, true));

        if ($blocking > 0 || $unknown) {
            $this->add('Operations', self::STATUS_BLOCKING, ucfirst($chain).' unresolved LIVE attempts', 'Uncertain, stale, inconsistent, or unknown LIVE attempt state requires operator attention.');

            return;
        }

        if ($attempts->isNotEmpty()) {
            $this->add('Operations', self::STATUS_WARNING, ucfirst($chain).' unresolved LIVE attempts', $attempts->count().' active, reconciliation-pending, or cleanup-eligible attempt(s) remain.');

            return;
        }

        $this->add('Operations', self::STATUS_PASS, ucfirst($chain).' unresolved LIVE attempts', 'No unresolved attempt was found.');
    }

    /** @param array<string, list<string>> $tables */
    private function tablesHaveColumns(array $tables): bool
    {
        try {
            foreach ($tables as $table => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function solanaRpcUrl(): mixed
    {
        try {
            if (Schema::hasTable('application_settings')) {
                $setting = ApplicationSetting::query()
                    ->where('scope', 'system')
                    ->where('owner_id', 0)
                    ->where('key', 'blockchain.solana_rpc_url')
                    ->first(['value', 'encrypted']);

                if ($setting !== null) {
                    return $setting->encrypted ? Crypt::decryptString($setting->value) : $setting->value;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return config('services.solana.rpc_url');
    }

    private function configuredString(string $key): bool
    {
        $value = config("services.trading_engine.{$key}");

        return is_string($value) && trim($value) !== '';
    }

    private function validEngineUrl(string $url): bool
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && $parts['scheme'] === 'https'
            && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && (! isset($parts['path']) || $parts['path'] === '');
    }

    private function validHttpsUrl(mixed $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'], $parts['pass'], $parts['fragment']);
    }

    private function includes(string $selected, string $chain): bool
    {
        return $selected === 'all' || $selected === $chain;
    }

    private function add(string $group, string $status, string $name, string $message): void
    {
        $this->checks[] = compact('group', 'status', 'name', 'message');
    }
}
