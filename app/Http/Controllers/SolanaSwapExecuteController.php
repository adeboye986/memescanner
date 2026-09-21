<?php

namespace App\Http\Controllers;

use App\Models\SolanaSwapAttempt;
use App\Services\JupiterSwapExecutionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SolanaSwapExecuteController extends Controller
{
    public function __invoke(Request $request, JupiterSwapExecutionService $executions): JsonResponse
    {
        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
            'signed_transaction' => ['required', 'string', 'max:100000'],
        ]);

        try {
            $attempt = DB::transaction(function () use ($request, $validated): SolanaSwapAttempt {
                $attempt = SolanaSwapAttempt::query()
                    ->whereKey($validated['attempt_id'])
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($attempt->status !== 'prepared' || $attempt->expires_at->isPast()) {
                    throw new RuntimeException('This swap order has expired or was already submitted.');
                }

                $wallet = $attempt->connectedWallet;
                if (! $wallet || $wallet->disconnected_at || ! $wallet->verified_at) {
                    throw new RuntimeException('The verified wallet is no longer active.');
                }

                $attempt->update([
                    'status' => 'submitting',
                    'submitted_at' => now(),
                ]);

                return $attempt;
            });

            try {
                $validation = $executions->validateSigned(
                    $attempt,
                    $validated['signed_transaction'],
                    $attempt->connectedWallet->address,
                );
            } catch (RuntimeException $exception) {
                SolanaSwapAttempt::query()
                    ->whereKey($attempt->id)
                    ->where('status', 'submitting')
                    ->whereNull('transaction_signature')
                    ->update([
                        'status' => 'prepared',
                        'submitted_at' => null,
                        'updated_at' => now(),
                    ]);

                throw $exception;
            }

            $attempt->update([
                'transaction_signature' => $validation['transaction_signature'],
            ]);

            $result = $executions->execute(
                $attempt,
                $validated['signed_transaction'],
            );

            $attempt->update([
                'status' => $result['status'],
                'provider_error_code' => $result['error_code'],
                'provider_error_message' => $result['error_message'],
            ]);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['swap' => $result]);
    }
}
