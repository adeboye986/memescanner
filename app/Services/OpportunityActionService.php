<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use App\Models\User;
use App\Services\Trading\PaperTradeExecutor;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OpportunityActionService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private TradeExecutionManager $executions,
        private PaperTradeExecutor $paperExecutor,
        private UserTradingPreferenceService $preferences,
    ) {}

    public function approve(TradeOpportunity $opportunity, User $actor): PaperPosition
    {
        $this->authorize($opportunity, $actor);
        $executionMode = $opportunity->user_id
            ? $this->preferences->forUser($actor)->execution_mode
            : ExecutionMode::from((string) $this->settings->get('trading.execution_mode'));

        try {
            $position = DB::transaction(function () use ($opportunity, $actor, $executionMode): PaperPosition {
                $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $this->authorize($locked, $actor);

                if ($locked->status !== TradeOpportunityStatus::PendingConfirmation) {
                    throw new DomainException($this->approvalRejectionMessage($locked));
                }

                $locked->update(['execution_mode' => $executionMode]);
                $position = $this->executions->execute($locked->fresh(), false);

                if (! $position) {
                    throw new RuntimeException('The opportunity did not produce a paper position.');
                }

                $this->record($locked, $actor, 'approved', TradeOpportunityStatus::PendingConfirmation, TradeOpportunityStatus::Executed);

                return $position;
            });

            if ($executionMode === ExecutionMode::Paper && (bool) data_get($opportunity->qualification_data, 'send_notification', true)) {
                $this->paperExecutor->sendNotification($position);
            }

            return $position;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Live execution is not enabled yet.') {
                $this->recordLiveFailure($opportunity, $actor);
            }

            throw $exception;
        }
    }

    public function ignore(TradeOpportunity $opportunity, User $actor): bool
    {
        $this->authorize($opportunity, $actor);

        return DB::transaction(function () use ($opportunity, $actor): bool {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $this->authorize($locked, $actor);

            if ($locked->status === TradeOpportunityStatus::Ignored) {
                return false;
            }

            if (! in_array($locked->status, [TradeOpportunityStatus::Qualified, TradeOpportunityStatus::PendingConfirmation], true)) {
                throw new DomainException('This opportunity can no longer be ignored.');
            }

            $from = $locked->status;
            $locked->update(['status' => TradeOpportunityStatus::Ignored]);
            $this->record($locked, $actor, 'ignored', $from, TradeOpportunityStatus::Ignored);

            return true;
        });
    }

    private function recordLiveFailure(TradeOpportunity $opportunity, User $actor): void
    {
        DB::transaction(function () use ($opportunity, $actor): void {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);

            if ($locked->status !== TradeOpportunityStatus::PendingConfirmation) {
                return;
            }

            $locked->update([
                'status' => TradeOpportunityStatus::Failed,
                'execution_mode' => ExecutionMode::Live,
                'execution_data' => ['executor' => 'live', 'reason' => 'live_execution_disabled'],
            ]);
            $this->record($locked, $actor, 'approval_failed', TradeOpportunityStatus::PendingConfirmation, TradeOpportunityStatus::Failed, [
                'reason' => 'live_execution_disabled',
            ]);
        });
    }

    private function authorize(TradeOpportunity $opportunity, User $actor): void
    {
        if ($opportunity->user_id === $actor->id) {
            return;
        }

        if ($opportunity->user_id === null && $actor->is_admin) {
            return;
        }

        throw new DomainException('This opportunity does not belong to your account.');
    }

    private function approvalRejectionMessage(TradeOpportunity $opportunity): string
    {
        return match ($opportunity->status) {
            TradeOpportunityStatus::Executed => 'This opportunity has already been executed.',
            TradeOpportunityStatus::Ignored => 'This opportunity has been ignored and cannot be executed.',
            TradeOpportunityStatus::Failed => 'This opportunity has already failed and cannot be executed again.',
            default => 'Only pending confirmation opportunities can be approved.',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function record(TradeOpportunity $opportunity, User $actor, string $action, TradeOpportunityStatus $from, TradeOpportunityStatus $to, array $metadata = []): void
    {
        TradeOpportunityEvent::query()->create([
            'trade_opportunity_id' => $opportunity->id,
            'user_id' => $actor->id,
            'action' => $action,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'metadata' => $metadata,
        ]);
    }
}
