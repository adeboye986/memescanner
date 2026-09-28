<?php

namespace App\Http\Middleware;

use App\Exceptions\TradingEngineWebhookException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTradingEngineWebhookBodyLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredLimit = config('services.trading_engine.webhook_body_max_bytes');

        if (! is_int($configuredLimit) || $configuredLimit < 1024 || $configuredLimit > 1048576) {
            throw new TradingEngineWebhookException(
                'WEBHOOK_CONFIGURATION_INVALID',
                'The trading engine webhook is unavailable.',
                503,
            );
        }

        $contentLength = $request->server('CONTENT_LENGTH');

        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $configuredLimit) {
            $this->rejectOversizedBody();
        }

        if (strlen($request->getContent()) > $configuredLimit) {
            $this->rejectOversizedBody();
        }

        return $next($request);
    }

    private function rejectOversizedBody(): never
    {
        throw new TradingEngineWebhookException(
            'WEBHOOK_BODY_TOO_LARGE',
            'The trading engine webhook body is too large.',
            413,
        );
    }
}
