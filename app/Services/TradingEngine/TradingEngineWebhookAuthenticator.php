<?php

namespace App\Services\TradingEngine;

use App\Exceptions\TradingEngineWebhookException;
use Illuminate\Http\Request;

class TradingEngineWebhookAuthenticator
{
    /** @return array{raw_body: string, raw_body_sha256: string} */
    public function authenticate(Request $request): array
    {
        $secret = config('services.trading_engine.webhook_secret');
        $tolerance = config('services.trading_engine.webhook_timestamp_tolerance_seconds');

        if (config('services.trading_engine.enabled') !== true
            || ! is_string($secret)
            || strlen(trim($secret)) < 32
            || ! is_int($tolerance)
            || $tolerance < 1
            || $tolerance > 300) {
            throw new TradingEngineWebhookException(
                'WEBHOOK_CONFIGURATION_INVALID',
                'The trading engine webhook is unavailable.',
                503,
            );
        }

        if ($request->getMethod() !== 'POST') {
            $this->rejectAuthentication();
        }

        $timestampHeader = $request->header('X-Engine-Timestamp');
        $signatureHeader = $request->header('X-Engine-Signature');

        if (! is_string($timestampHeader)
            || preg_match('/^(0|[1-9][0-9]*)$/D', $timestampHeader) !== 1
            || ! is_string($signatureHeader)
            || preg_match('/^v1=[0-9a-f]{64}$/D', $signatureHeader) !== 1) {
            $this->rejectAuthentication();
        }

        $timestamp = filter_var($timestampHeader, FILTER_VALIDATE_INT);

        if ($timestamp === false || abs(now()->timestamp - $timestamp) > $tolerance) {
            throw new TradingEngineWebhookException(
                'WEBHOOK_REPLAY_REJECTED',
                'The trading engine webhook timestamp is outside the allowed window.',
                401,
            );
        }

        $rawBody = $request->getContent();
        $signingInput = $timestampHeader.'.POST.'.$request->getRequestUri().'.'.$rawBody;
        $expectedSignature = hash_hmac('sha256', $signingInput, trim($secret));

        if (! hash_equals($expectedSignature, substr($signatureHeader, 3))) {
            $this->rejectAuthentication();
        }

        return [
            'raw_body' => $rawBody,
            'raw_body_sha256' => hash('sha256', $rawBody),
        ];
    }

    private function rejectAuthentication(): never
    {
        throw new TradingEngineWebhookException(
            'WEBHOOK_AUTHENTICATION_FAILED',
            'The trading engine webhook could not be authenticated.',
            401,
        );
    }
}
