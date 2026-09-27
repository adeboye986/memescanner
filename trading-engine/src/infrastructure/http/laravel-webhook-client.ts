import { createHmac } from 'node:crypto';

import type { EngineConfig } from '../../config/env.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';

export interface WebhookDeliveryResult {
  readonly accepted: boolean;
  readonly status: number | null;
  readonly errorCode: string | null;
}

export class LaravelWebhookClient {
  public constructor(
    private readonly config: EngineConfig,
    private readonly fetchImplementation: typeof fetch = fetch,
  ) {}

  public async deliver(envelope: EventEnvelope): Promise<WebhookDeliveryResult> {
    const body = JSON.stringify(envelope);
    const timestamp = Math.floor(Date.now() / 1_000).toString();
    const path = this.config.laravelWebhookUrl.pathname
      + this.config.laravelWebhookUrl.search;
    const signature = createHmac(
      'sha256',
      this.config.laravelWebhookHmacSecret,
    )
      .update(`${timestamp}.POST.${path}.${body}`)
      .digest('hex');

    try {
      const response = await this.fetchImplementation(this.config.laravelWebhookUrl, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'x-correlation-id': envelope.correlation_id,
          'x-engine-event-id': envelope.event_id,
          'x-engine-signature': `v1=${signature}`,
          'x-engine-timestamp': timestamp,
          traceparent: envelope.traceparent,
        },
        body,
        signal: AbortSignal.timeout(this.config.laravelWebhookTimeoutMs),
      });
      await response.body?.cancel();

      if (response.ok) {
        return {
          accepted: true,
          status: response.status,
          errorCode: null,
        };
      }

      return {
        accepted: false,
        status: response.status,
        errorCode: `HTTP_${response.status}`,
      };
    } catch (error) {
      return {
        accepted: false,
        status: null,
        errorCode: error instanceof Error ? error.name : 'WEBHOOK_ERROR',
      };
    }
  }
}
