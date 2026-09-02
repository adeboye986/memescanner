<?php

namespace App\Services;

class SettingDefinitionRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'general.application_name' => ['group' => 'general', 'label' => 'Application Name', 'type' => 'string', 'default' => 'Meme Scanner', 'fallback' => 'app.name', 'description' => 'Product name displayed in the control panel.'],
            'trading.execution_mode' => ['group' => 'trading', 'label' => 'Trading Environment', 'type' => 'string', 'default' => 'paper', 'options' => ['paper' => 'Paper', 'live' => 'Live'], 'description' => 'Live is configuration-only and remains blocked server-side.'],
            'trading.entry_mode' => ['group' => 'trading', 'label' => 'Entry Behavior', 'type' => 'string', 'default' => 'auto', 'options' => ['signal' => 'Signal Only', 'confirm' => 'Confirm Before Buy', 'auto' => 'Auto Buy'], 'description' => 'Controls what happens after a scanner qualifies an opportunity.'],
            'scanner.max_chase_percent' => ['group' => 'scanner', 'label' => 'Maximum Chase %', 'type' => 'float', 'default' => 35.0, 'fallback' => 'services.trading.max_chase_percent', 'description' => 'Existing entry protection against buying after excessive movement.'],
            'telegram.enabled' => ['group' => 'telegram', 'label' => 'Telegram Enabled', 'type' => 'boolean', 'default' => true, 'description' => 'Allow outgoing operational notifications.'],
            'telegram.bot_token' => ['group' => 'telegram', 'label' => 'Telegram Bot Token', 'type' => 'string', 'default' => null, 'fallback' => 'services.telegram.bot_token', 'secret' => true, 'description' => 'Encrypted at rest; blank keeps the current value.'],
            'telegram.chat_id' => ['group' => 'telegram', 'label' => 'Telegram Destination', 'type' => 'string', 'default' => null, 'fallback' => 'services.telegram.chat_id', 'secret' => true, 'description' => 'Destination chat or group ID, encrypted at rest.'],
            'telegram.bot_username' => ['group' => 'telegram', 'label' => 'Telegram Bot Username', 'type' => 'string', 'default' => null, 'description' => 'Public bot username used to create secure account-linking deep links.'],
            'telegram.webhook_secret' => ['group' => 'telegram', 'label' => 'Webhook Secret', 'type' => 'string', 'default' => null, 'secret' => true, 'description' => 'Encrypted secret required in every Telegram webhook request.'],
            'market_data.birdeye_api_key' => ['group' => 'market_data', 'label' => 'Birdeye API Key', 'type' => 'string', 'default' => null, 'fallback' => 'services.birdeye.api_key', 'secret' => true, 'description' => 'Used by Solana discovery and security requests.'],
            'blockchain.solana_rpc_url' => ['group' => 'blockchain', 'label' => 'Solana RPC URL', 'type' => 'string', 'default' => 'https://api.mainnet-beta.solana.com', 'fallback' => 'services.solana.rpc_url', 'secret' => true, 'description' => 'Read-only Solana RPC endpoint; URLs may contain credentials.'],
            'tracker.snapshot_seconds' => ['group' => 'tracker', 'label' => 'Snapshot Interval', 'type' => 'integer', 'default' => 10, 'fallback' => 'services.trading.paper_tracker_snapshot_seconds', 'description' => 'Minimum seconds between periodic position snapshots.'],
            'risk.kill_switch' => ['group' => 'risk', 'label' => 'Emergency Trading Kill Switch', 'type' => 'boolean', 'default' => false, 'description' => 'Blocks all new execution while preserving monitoring and exits.'],
            'risk.max_trade_amount' => ['group' => 'risk', 'label' => 'Future Maximum Trade Amount', 'type' => 'float', 'default' => 0.1, 'description' => 'Foundation for live risk policy; not applied to paper accounting yet.'],
            'risk.max_open_positions' => ['group' => 'risk', 'label' => 'Future Maximum Open Positions', 'type' => 'integer', 'default' => 10, 'description' => 'Foundation only; live execution remains unavailable.'],
            'risk.max_daily_loss' => ['group' => 'risk', 'label' => 'Future Maximum Daily Loss', 'type' => 'float', 'default' => 1.0, 'description' => 'Foundation only; expressed in quote currency.'],
            'risk.max_slippage_percent' => ['group' => 'risk', 'label' => 'Future Maximum Slippage %', 'type' => 'float', 'default' => 1.0, 'description' => 'Foundation only; no on-chain quotes are executed.'],
            'risk.minimum_wallet_reserve' => ['group' => 'risk', 'label' => 'Future Minimum Wallet Reserve', 'type' => 'float', 'default' => 0.1, 'description' => 'Foundation only; no live wallet exists.'],
            'risk.trade_cooldown_seconds' => ['group' => 'risk', 'label' => 'Future Trade Cooldown', 'type' => 'integer', 'default' => 60, 'description' => 'Foundation only; duplicate paper entries remain protected separately.'],
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException("Unknown application setting: {$key}");
    }
}
