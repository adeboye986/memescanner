import { createHmac, timingSafeEqual } from 'node:crypto';
import {
  createServer,
  type IncomingHttpHeaders,
  type Server,
} from 'node:http';

import type { EventEnvelope } from '../../src/contracts/events/event-envelope.schema.js';

export interface ReceivedWebhook {
  readonly envelope: EventEnvelope;
  readonly headers: IncomingHttpHeaders;
  readonly duplicate: boolean;
}

export class FakeLaravelReceiver {
  private server: Server | undefined;
  private failuresRemaining = 0;
  private readonly eventIds = new Set<string>();
  public readonly requests: ReceivedWebhook[] = [];

  public constructor(private readonly secret: string) {}

  public failNext(count: number): void {
    this.failuresRemaining = count;
  }

  public async start(): Promise<URL> {
    if (this.server !== undefined) {
      throw new Error('Fake Laravel receiver is already running');
    }

    this.server = createServer((request, response) => {
      const chunks: Buffer[] = [];

      request.on('data', (chunk: Buffer) => {
        chunks.push(chunk);
      });
      request.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf8');
        const timestamp = request.headers['x-engine-timestamp'];
        const signature = request.headers['x-engine-signature'];
        const path = request.url ?? '/';

        if (
          typeof timestamp !== 'string'
          || typeof signature !== 'string'
          || !this.signatureIsValid(timestamp, path, body, signature)
        ) {
          response.writeHead(401, { 'content-type': 'application/json' });
          response.end(JSON.stringify({ error: 'invalid signature' }));
          return;
        }

        if (Math.abs(Date.now() / 1_000 - Number(timestamp)) > 60) {
          response.writeHead(401, { 'content-type': 'application/json' });
          response.end(JSON.stringify({ error: 'stale signature' }));
          return;
        }

        if (this.failuresRemaining > 0) {
          this.failuresRemaining -= 1;
          response.writeHead(503, { 'content-type': 'application/json' });
          response.end(JSON.stringify({ error: 'synthetic failure' }));
          return;
        }

        const envelope = JSON.parse(body) as EventEnvelope;
        const duplicate = this.eventIds.has(envelope.event_id);
        this.eventIds.add(envelope.event_id);
        this.requests.push({
          envelope,
          headers: request.headers,
          duplicate,
        });

        response.writeHead(200, { 'content-type': 'application/json' });
        response.end(JSON.stringify({ accepted: true, duplicate }));
      });
    });

    await new Promise<void>((resolve, reject) => {
      this.server?.once('error', reject);
      this.server?.listen(0, '127.0.0.1', resolve);
    });
    const address = this.server.address();

    if (address === null || typeof address === 'string') {
      throw new Error('Fake receiver did not bind an IP port');
    }

    return new URL(`http://127.0.0.1:${address.port}/internal/trading-engine/events`);
  }

  public async stop(): Promise<void> {
    const server = this.server;
    this.server = undefined;

    if (server === undefined) {
      return;
    }

    await new Promise<void>((resolve, reject) => {
      server.close((error) => {
        if (error !== undefined) {
          reject(error);
          return;
        }

        resolve();
      });
    });
  }

  private signatureIsValid(
    timestamp: string,
    path: string,
    body: string,
    suppliedSignature: string,
  ): boolean {
    const expected = Buffer.from(
      createHmac('sha256', this.secret)
        .update(`${timestamp}.POST.${path}.${body}`)
        .digest('hex'),
      'utf8',
    );
    const supplied = Buffer.from(suppliedSignature.replace(/^v1=/, ''), 'utf8');

    return expected.length === supplied.length && timingSafeEqual(expected, supplied);
  }
}
