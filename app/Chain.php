<?php

namespace App;

use InvalidArgumentException;

enum Chain: string
{
    case Solana = 'solana';
    case Ethereum = 'ethereum';

    public function label(): string
    {
        return match ($this) {
            self::Solana => 'Solana',
            self::Ethereum => 'Ethereum',
        };
    }

    public function dexScreenerId(): string
    {
        return $this->value;
    }

    public static function fromInput(mixed $value): self
    {
        $chain = self::tryFrom(strtolower(trim((string) $value)));

        if (! $chain) {
            throw new InvalidArgumentException('Unsupported chain. Supported chains: solana, ethereum.');
        }

        return $chain;
    }
}
