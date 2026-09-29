<?php

namespace App\Services\TradingEngine;

final readonly class TradingEngineEvaluationConsumptionDecision
{
    public const ELIGIBLE = 'ELIGIBLE_FOR_FUTURE_DECISION';

    public const INELIGIBLE = 'INELIGIBLE_FOR_FUTURE_DECISION';

    /**
     * @param  list<string>  $reasonCodes
     */
    private function __construct(
        public bool $eligible,
        public string $decisionCode,
        public array $reasonCodes,
        public ?string $evaluationId,
        public ?string $engineOpportunityId,
    ) {}

    public static function eligible(string $evaluationId, string $engineOpportunityId): self
    {
        return new self(
            eligible: true,
            decisionCode: self::ELIGIBLE,
            reasonCodes: [],
            evaluationId: $evaluationId,
            engineOpportunityId: $engineOpportunityId,
        );
    }

    public static function ineligible(string $reasonCode): self
    {
        return new self(
            eligible: false,
            decisionCode: self::INELIGIBLE,
            reasonCodes: [$reasonCode],
            evaluationId: null,
            engineOpportunityId: null,
        );
    }
}
