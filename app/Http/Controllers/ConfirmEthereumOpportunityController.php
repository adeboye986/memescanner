<?php

namespace App\Http\Controllers;

use App\Exceptions\EthereumPreparationException;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Services\EthereumOpportunityPreparationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConfirmEthereumOpportunityController extends Controller
{
    public function __invoke(Request $request, TradeOpportunity $opportunity, EthereumOpportunityPreparationService $preparation): JsonResponse
    {
        abort_unless($opportunity->user_id === $request->user()->id, 404);
        try {
            $claim = $preparation->claimForSigning($opportunity, $request->user());
            $attempt = $claim['attempt'];

            return response()->json(['order' => ['attempt_id' => $attempt->id, 'transaction' => $attempt->transaction_payload,
                'signing_claim_token' => $claim['signing_claim_token'], 'valid_for_ms' => $this->validity($attempt)]])->header('Cache-Control', 'no-store');
        } catch (EthereumPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException) {
            return $this->databaseFailure();
        }
    }

    public function transition(Request $request, TradeOpportunity $opportunity, string $action, EthereumOpportunityPreparationService $preparation): JsonResponse
    {
        abort_unless($opportunity->user_id === $request->user()->id, 404);
        $input = $request->validate(['signing_claim_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'rejection_code' => $action === 'rejected' ? ['required', 'integer', 'in:4001'] : ['prohibited']]);
        try {
            $attempt = $preparation->transitionSigning($opportunity, $request->user(), $input['signing_claim_token'], $action);

            return response()->json(['status' => $attempt->status, 'armed' => $attempt->signing_armed_at !== null,
                'valid_for_ms' => $this->validity($attempt)])->header('Cache-Control', 'no-store');
        } catch (EthereumPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException) {
            return $this->databaseFailure();
        }
    }

    private function validity(EthereumSwapAttempt $attempt): int
    {
        return max(0, min(10000, (int) now()->diffInMilliseconds($attempt->expires_at, false)));
    }

    private function databaseFailure(): JsonResponse
    {
        Log::warning('Ethereum signing claim database operation failed.');

        return response()->json(['message' => 'Signing handoff is unresolved. Refresh its status; do not send again.'], 503);
    }
}
