<?php

namespace App\Services;

use InvalidArgumentException;

class DashboardCommandRegistry
{
    /** @var array<string, array{command: string, label: string}> */
    private const ACTIONS = [
        'token-scan' => ['command' => 'tokens:scan', 'label' => 'Run Token Scan'],
        'momentum-scan' => ['command' => 'tokens:momentum', 'label' => 'Run Momentum Scan'],
        'paper-track' => ['command' => 'tokens:paper-track', 'label' => 'Track Positions Now'],
        'paper-report' => ['command' => 'tokens:paper-report', 'label' => 'Paper Report'],
        'paper-reconcile' => ['command' => 'tokens:paper-reconcile', 'label' => 'Check Wallet Reconciliation'],
    ];

    /**
     * @return array{command: string, label: string}
     */
    public function get(string $action): array
    {
        return self::ACTIONS[$action]
            ?? throw new InvalidArgumentException('Unsupported dashboard action.');
    }

    public function supports(string $action): bool
    {
        return array_key_exists($action, self::ACTIONS);
    }

    /**
     * @return array<string, array{command: string, label: string}>
     */
    public function all(): array
    {
        return self::ACTIONS;
    }
}
