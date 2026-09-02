<?php

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
        'paper_tracker_stale_seconds' => env('PAPER_TRACKER_STALE_SECONDS', 5),
        'paper_tracker_rate_limit_backoff_ms' => env('PAPER_TRACKER_RATE_LIMIT_BACKOFF_MS', 5000),
    ],

];
