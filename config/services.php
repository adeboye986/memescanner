<?php

use App\Services\EthereumAccountingReviewerAllowlist;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'goplus' => [
        'access_token' => env('GOPLUS_ACCESS_TOKEN'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'birdeye' => [
        'api_key' => env('BIRDEYE_API_KEY'),
        'base_url' => 'https://public-api.birdeye.so',
        'momentum_budget' => env('MOMENTUM_BIRDEYE_BUDGET', 1),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'solana' => [
        'rpc_url' => env(
            'SOLANA_RPC_URL',
            'https://api.mainnet-beta.solana.com'
        ),
        'opportunity_max_age_seconds' => env('SOLANA_OPPORTUNITY_MAX_AGE_SECONDS', 300),
        'metadata_cache_store' => env('OPERATIONS_CACHE_STORE', 'file'),
        'token_metadata_cache_seconds' => env('SOLANA_TOKEN_METADATA_CACHE_SECONDS', 86400),
        'transaction_validator_node' => env('SOLANA_TRANSACTION_VALIDATOR_NODE', 'node'),
        'transaction_validator_timeout_seconds' => env('SOLANA_TRANSACTION_VALIDATOR_TIMEOUT_SECONDS', 5),
    ],

    'ethereum' => [
        'rpc_url' => env('ETHEREUM_RPC_URL'),
        'chain_id' => 1,
        'opportunity_max_age_seconds' => env('ETHEREUM_OPPORTUNITY_MAX_AGE_SECONDS', 300),
        'metadata_cache_store' => env('ETHEREUM_METADATA_CACHE_STORE', 'file'),
        'token_metadata_cache_seconds' => env('ETHEREUM_TOKEN_METADATA_CACHE_SECONDS', 86400),
        'accounting' => [
            'reviewer_ids' => EthereumAccountingReviewerAllowlist::normalize(env('ETHEREUM_ACCOUNTING_REVIEWER_IDS')),
            'finality' => env('ETHEREUM_ACCOUNTING_FINALITY', 'finalized'),
            'confirmations' => env('ETHEREUM_ACCOUNTING_CONFIRMATIONS'),
            'batch_size' => 25,
            'reconsideration_batch_size' => 3,
            'lease_seconds' => 180,
            'retry_seconds' => 60,
            'retry_max_seconds' => 3600,
        ],
    ],

    'zero_x' => [
        'base_url' => env('ZERO_X_BASE_URL', 'https://api.0x.org'),
        'api_key' => env('ZERO_X_API_KEY'),
    ],

    'coingecko' => [
        'base_url' => env('COINGECKO_BASE_URL', 'https://api.coingecko.com/api/v3'),
        'api_key' => env('COINGECKO_API_KEY'),
        'max_price_age_seconds' => env('COINGECKO_MAX_PRICE_AGE_SECONDS', 120),
    ],

    'jupiter' => [
        'base_url' => env('JUPITER_BASE_URL', 'https://api.jup.ag/swap/v1'),
        'swap_v2_base_url' => env(
            'JUPITER_SWAP_V2_BASE_URL',
            'https://api.jup.ag/swap/v2'
        ),
        'api_key' => env('JUPITER_API_KEY'),
    ],

    'trading' => [
        'paper_trading' => env('PAPER_TRADING', true),

        'fast_paper_alerts' => env('FAST_PAPER_ALERTS', true),

        'max_chase_percent' => env('MOMENTUM_MAX_CHASE_PERCENT', 35),

        'paper_trade_size_sol' => env('PAPER_TRADE_SIZE_SOL', 0.10),
        'paper_trade_size_eth' => env('PAPER_TRADE_SIZE_ETH', 0.10),
        'paper_starting_balance_sol' => env('PAPER_STARTING_BALANCE_SOL', 5),
        'paper_starting_balance_eth' => env('PAPER_STARTING_BALANCE_ETH', 5),
        'paper_tracker_interval_ms' => env('PAPER_TRACKER_INTERVAL_MS', 1000),
        'paper_tracker_snapshot_seconds' => env('PAPER_TRACKER_SNAPSHOT_SECONDS', 10),
        'paper_tracker_lock_seconds' => env('PAPER_TRACKER_LOCK_SECONDS', 300),
        // FAST_TRACKER_STALE_SECONDS remains a legacy fallback; PAPER_TRACKER_STALE_SECONDS takes precedence.
        'paper_tracker_stale_seconds' => env('PAPER_TRACKER_STALE_SECONDS', env('FAST_TRACKER_STALE_SECONDS', 30)),
        'paper_tracker_overlap_minutes' => env('PAPER_TRACKER_OVERLAP_MINUTES', 5),
        'paper_market' => [
            'ethereum' => [
                'geckoterminal_cooldown_seconds' => env('ETHEREUM_PAPER_GECKOTERMINAL_COOLDOWN_SECONDS', 60),
                'geckoterminal_manual_reservation_seconds' => env('ETHEREUM_PAPER_GECKOTERMINAL_MANUAL_RESERVATION_SECONDS', 30),
                'geckoterminal_request_lock_seconds' => env('ETHEREUM_PAPER_GECKOTERMINAL_REQUEST_LOCK_SECONDS', 12),
                'geckoterminal_manual_lock_wait_seconds' => env('ETHEREUM_PAPER_GECKOTERMINAL_MANUAL_LOCK_WAIT_SECONDS', 8),
                'work_budget_seconds' => env('ETHEREUM_PAPER_WORK_BUDGET_SECONDS', 20),
                'max_observation_age_seconds' => env('ETHEREUM_PAPER_OBSERVATION_AGE_SECONDS', 60),
                'require_liquidity' => true,
                'minimum_liquidity_usd' => env('ETHEREUM_PAPER_MINIMUM_LIQUIDITY_USD', 0),
                'decline_diagnostic_percent' => env('ETHEREUM_PAPER_DECLINE_DIAGNOSTIC_PERCENT', 50),
                'valuation_discrepancy_ratio' => env('ETHEREUM_PAPER_DISCREPANCY_RATIO', 2),
            ],
            'solana' => [
                'require_liquidity' => false,
                'max_observation_age_seconds' => null,
                'decline_diagnostic_percent' => null,
                'valuation_discrepancy_ratio' => null,
            ],
        ],
        'paper_tracker_rate_limit_backoff_ms' => env('PAPER_TRACKER_RATE_LIMIT_BACKOFF_MS', 5000),
        'paper_tracker_cache_store' => env('PAPER_TRACKER_CACHE_STORE', 'file'),
        'paper_tracker_persist_seconds' => env('PAPER_TRACKER_PERSIST_SECONDS', 5),
        'sqlite_lock_retries' => env('SQLITE_LOCK_RETRIES', 3),
        'sqlite_lock_backoff_ms' => env('SQLITE_LOCK_BACKOFF_MS', 50),
    ],

    'operations' => [
        'cache_store' => env('OPERATIONS_CACHE_STORE', 'file'),
        'scheduler_stale_seconds' => env('SCHEDULER_STALE_SECONDS', 150),
        'queue_stale_seconds' => env('QUEUE_STALE_SECONDS', 750),
        'queue_max_time' => env('QUEUE_DRAIN_MAX_TIME', 50),
        'queue_memory' => env('QUEUE_DRAIN_MEMORY', 128),
        'queue_job_timeout' => env('QUEUE_JOB_TIMEOUT', 600),
    ],

    'solana_transaction_validator' => [
        'driver' => env('SOLANA_TRANSACTION_VALIDATOR_DRIVER', 'http'),
        'url' => env('SOLANA_TRANSACTION_VALIDATOR_URL'),
        'api_key' => env('SOLANA_TRANSACTION_VALIDATOR_API_KEY'),
        'timeout_seconds' => env(
            'SOLANA_TRANSACTION_VALIDATOR_TIMEOUT_SECONDS',
            5
        ),
    ],

    'trading_engine' => [
        'enabled' => env('TRADING_ENGINE_ENABLED', false),
        'opportunity_export_enabled' => env('TRADING_ENGINE_OPPORTUNITY_EXPORT_ENABLED', false),
        'opportunity_projection_enabled' => env('TRADING_ENGINE_OPPORTUNITY_PROJECTION_ENABLED', false),
        'evaluation_consumption_enabled' => env('TRADING_ENGINE_EVALUATION_CONSUMPTION_ENABLED', false),
        'decision_boundary_enabled' => env('TRADING_ENGINE_DECISION_BOUNDARY_ENABLED', false),
        'paper_decision_integration_enabled' => env('TRADING_ENGINE_PAPER_DECISION_INTEGRATION_ENABLED', false),
        'paper_entry_integration_enabled' => env('TRADING_ENGINE_PAPER_ENTRY_INTEGRATION_ENABLED', false),
        'paper_entry_canary_user_ids' => env('TRADING_ENGINE_PAPER_ENTRY_CANARY_USER_IDS', ''),
        'paper_financial_lifecycle_enabled' => env('TRADING_ENGINE_PAPER_FINANCIAL_LIFECYCLE_ENABLED', false),
        'paper_lifecycle_integration_enabled' => env('TRADING_ENGINE_PAPER_LIFECYCLE_INTEGRATION_ENABLED', false),
        'paper_lifecycle_authoritative_enabled' => env('TRADING_ENGINE_PAPER_LIFECYCLE_AUTHORITATIVE_ENABLED', false),
        'paper_lifecycle_general_rollout_enabled' => env('TRADING_ENGINE_PAPER_LIFECYCLE_GENERAL_ROLLOUT_ENABLED', false),
        'paper_lifecycle_canary_user_ids' => env('TRADING_ENGINE_PAPER_LIFECYCLE_CANARY_USER_IDS', ''),
        'live_decision_integration_enabled' => env('TRADING_ENGINE_LIVE_DECISION_INTEGRATION_ENABLED', false),
        'live_preparation_enabled' => env('TRADING_ENGINE_LIVE_PREPARATION_ENABLED', false),
        'live_recovery_enabled' => env('TRADING_ENGINE_LIVE_RECOVERY_ENABLED', false),
        'solana_live_integration_enabled' => env('TRADING_ENGINE_SOLANA_LIVE_INTEGRATION_ENABLED', false),
        'live_attempt_attention_after_seconds' => (int) env('TRADING_ENGINE_LIVE_ATTEMPT_ATTENTION_AFTER_SECONDS', 900),
        'projection_recovery_batch_size' => (int) env('TRADING_ENGINE_PROJECTION_RECOVERY_BATCH_SIZE', 100),
        'projection_recovery_stale_after_seconds' => (int) env('TRADING_ENGINE_PROJECTION_RECOVERY_STALE_AFTER_SECONDS', 300),
        'projection_recovery_lease_seconds' => (int) env('TRADING_ENGINE_PROJECTION_RECOVERY_LEASE_SECONDS', 120),
        'base_url' => env('TRADING_ENGINE_BASE_URL'),
        'service_issuer' => env('TRADING_ENGINE_SERVICE_ISSUER', 'meme-scanner-laravel'),
        'service_audience' => env('TRADING_ENGINE_SERVICE_AUDIENCE', 'meme-scanner-trading-engine'),
        'service_subject' => env('TRADING_ENGINE_SERVICE_SUBJECT', 'meme-scanner-laravel'),
        'private_key_base64' => env('TRADING_ENGINE_PRIVATE_KEY_BASE64'),
        'assertion_lifetime_seconds' => env('TRADING_ENGINE_ASSERTION_LIFETIME_SECONDS', 30),
        'connect_timeout_seconds' => env('TRADING_ENGINE_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => env('TRADING_ENGINE_TIMEOUT_SECONDS', 8),
        'webhook_secret' => env('TRADING_ENGINE_WEBHOOK_SECRET'),
        'webhook_timestamp_tolerance_seconds' => (int) env('TRADING_ENGINE_WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS', 60),
        'webhook_body_max_bytes' => (int) env('TRADING_ENGINE_WEBHOOK_BODY_MAX_BYTES', 262144),
        'webhook_rate_limit_per_minute' => (int) env('TRADING_ENGINE_WEBHOOK_RATE_LIMIT_PER_MINUTE', 600),
    ],

];
