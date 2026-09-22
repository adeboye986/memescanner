<?php

namespace App\Http\Controllers;

use App\Exceptions\EthereumPreparationException;
use App\Models\TradeOpportunity;
use App\Services\EthereumOpportunityPreparationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrepareEthereumOpportunityController extends Controller
{
    public function __invoke(Request $request, TradeOpportunity $opportunity, EthereumOpportunityPreparationService $preparation): JsonResponse
    {
        abort_unless($opportunity->user_id === $request->user()->id, 404);
        try {
            $attempt = $preparation->prepare($opportunity, $request->user());
        } catch (EthereumPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException $exception) {
            Log::warning('Ethereum swap database operation failed.');

            return response()->json(['message' => 'Preparation could not be saved. Refresh the opportunity before retrying.'], 503);
        }

        return response()->json(['order' => ['attempt_id' => $attempt->id, 'transaction' => $attempt->transaction_payload,
            'expires_at' => $attempt->expires_at->toIso8601String()]]);
    }
}
