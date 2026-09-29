<?php

namespace App\Services\TradingEngine;

final readonly class TradingEngineOpportunityDecision
{
    public const WOULD_ENTER = 'WOULD_ENTER';

    public const WOULD_HOLD = 'WOULD_HOLD';

    public const WOULD_REJECT = 'WOULD_REJECT';

    /**
     * @param  list<string>  $reasonCodes
     */
    private function __construct(
        public string $decisionCode,
        public array $reasonCodes,
        public string $consumptionDecisionCode,
        public ?string $evaluationId,
        public ?string $engineOpportunityId,
    ) {}

    public static function wouldEnter(
        TradingEngineEvaluationConsumptionDecision $consumption,
        string $reasonCode,
    ): self {
        return self::fromEligibleConsumption(self::WOULD_ENTER, $reasonCode, $consumption);
    }

    public static function wouldHold(
        TradingEngineEvaluationConsumptionDecision $consumption,
        string $reasonCode,
    ): self {
        return self::fromEligibleConsumption(self::WOULD_HOLD, $reasonCode, $consumption);
    }

    public static function wouldReject(
        TradingEngineEvaluationConsumptionDecision $consumption,
        string $reasonCode,
    ): self {
        return self::fromEligibleConsumption(self::WOULD_REJECT, $reasonCode, $consumption);
    }

    public static function consumptionIneligible(
        TradingEngineEvaluationConsumptionDecision $consumption,
    ): self {
        return new self(
            decisionCode: self::WOULD_REJECT,
            reasonCodes: $consumption->reasonCodes,
            consumptionDecisionCode: $consumption->decisionCode,
            evaluationId: null,
            engineOpportunityId: null,
        );
    }

    private static function fromEligibleConsumption(
        string $decisionCode,
        string $reasonCode,
        TradingEngineEvaluationConsumptionDecision $consumption,
    ): self {
        if (! $consumption->eligible
            || $consumption->decisionCode !== TradingEngineEvaluationConsumptionDecision::ELIGIBLE
            || $consumption->evaluationId === null
            || $consumption->engineOpportunityId === null) {
            return self::consumptionIneligible($consumption);
        }

        return new self(
            decisionCode: $decisionCode,
            reasonCodes: [$reasonCode],
            consumptionDecisionCode: $consumption->decisionCode,
            evaluationId: $consumption->evaluationId,
            engineOpportunityId: $consumption->engineOpportunityId,
        );
    }
}
