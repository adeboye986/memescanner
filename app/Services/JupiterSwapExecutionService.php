<?php

namespace App\Services;

use App\Models\SolanaSwapAttempt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class JupiterSwapExecutionService
{
    public function __construct(private RemoteSolanaTransactionValidator $validator) {}

    /** @return array{status: string, signature: ?string, error_code: ?string, error_message: ?string} */
    public function execute(SolanaSwapAttempt $attempt, string $signedTransaction, string $wallet): array
    {
        if (! $this->isCanonicalBase64($signedTransaction)) {
            throw new RuntimeException('The wallet returned an invalid signed transaction.');
        }

        $validation = $this->validator->compareSigned(
            $attempt->prepared_transaction,
            $signedTransaction,
            $wallet,
        );

        if (($validation['message_hash'] ?? null) !== $attempt->message_hash
            || ($validation['expected_wallet_is_required_signer'] ?? false) !== true
            || ($validation['expected_wallet_is_fee_payer'] ?? false) !== true
            || ($validation['expected_wallet_signature_present'] ?? false) !== true) {
            throw new RuntimeException('The signed transaction failed validation.');
        }

        $baseUrl = rtrim((string) config('services.jupiter.swap_v2_base_url'), '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('Jupiter Swap V2 requires HTTPS.');
        }

        try {
            $request = Http::baseUrl($baseUrl)->acceptJson()->asJson()->connectTimeout(3)->timeout(20);
            $apiKey = trim((string) config('services.jupiter.api_key'));
            if ($apiKey !== '') {
                $request = $request->withHeaders(['x-api-key' => $apiKey]);
            }
            $response = $request->post('/execute', [
                'signedTransaction' => $signedTransaction,
                'requestId' => $attempt->request_id,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The signed swap could not be submitted. Check transaction history before retrying.', previous: $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('The signed swap could not be submitted. Check transaction history before retrying.');
        }

        $data = $response->json();
        $signature = is_string($data['signature'] ?? null) && $data['signature'] !== '' ? $data['signature'] : null;
        $code = is_string($data['code'] ?? null) ? $data['code'] : (is_string($data['errorCode'] ?? null) ? $data['errorCode'] : null);
        $error = is_string($data['error'] ?? null) ? $data['error'] : (is_string($data['errorMessage'] ?? null) ? $data['errorMessage'] : null);
        $status = ($data['status'] ?? null) === 'Success' && $signature ? 'submitted' : 'failed';

        return ['status' => $status, 'signature' => $signature, 'error_code' => $code, 'error_message' => $error];
    }

    private function isCanonicalBase64(string $value): bool
    {
        $decoded = base64_decode($value, true);

        return $decoded !== false && $decoded !== '' && base64_encode($decoded) === $value;
    }
}
