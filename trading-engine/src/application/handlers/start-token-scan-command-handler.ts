import type { Kysely } from 'kysely';

import type { StartTokenScanCommand } from '../commands/start-token-scan-command.js';
import type { TokenScanCommandResponse } from '../../contracts/http/token-scan-command.schema.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { TokenScanRepository } from '../../infrastructure/database/repositories/token-scan-repository.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

export class StartTokenScanCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly scans: TokenScanRepository,
    private readonly enabled: boolean,
    private readonly allowedUserIds: readonly string[],
    private readonly createId: () => EngineId = newEngineId,
  ) {}

  public async execute(command: StartTokenScanCommand): Promise<TokenScanCommandResponse> {
    if (!this.enabled) {
      throw new ApplicationError('SCANNER_DISABLED', 'Token scanning is disabled', 503, false);
    }

    const userId = command.body.subject.control_plane_user_id;

    if (!this.allowedUserIds.includes(userId)) {
      throw new ApplicationError('SCANNER_SUBJECT_DENIED', 'The user is outside the scanner rollout boundary', 403, false);
    }

    const requestHash = hashCanonicalJson({ command: 'token-scan.start', body: command.body });

    return this.database.transaction().execute(async (transaction) => {
      const operationId = this.createId();
      const inserted = await this.commandInbox.insert(transaction, {
        id: operationId,
        idempotencyKey: command.idempotencyKey,
        authJti: command.authJti,
        subject: command.subject,
        commandName: 'token-scan.start',
        requestHash,
        correlationId: command.correlationId,
        traceparent: command.traceparent,
      });

      if (inserted === undefined) {
        const existing = await this.commandInbox.findByIdempotencyKey(transaction, command.idempotencyKey);

        if (existing?.request_hash !== requestHash
          || existing.subject !== command.subject
          || existing.command_name !== 'token-scan.start') {
          throw new ApplicationError('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for another request', 409, false);
        }

        if (existing.status !== 'succeeded' || !isTokenScanResponse(existing.response)) {
          throw new ApplicationError('COMMAND_IN_PROGRESS', 'The command is still being processed', 409, true);
        }

        return { ...(existing.response as unknown as TokenScanCommandResponse), duplicate: true };
      }

      const scanExecutionId = this.createId();
      await this.scans.insertExecution(transaction, {
        id: scanExecutionId,
        command: command.body,
        requestSha256: hashCanonicalJson(command.body),
        idempotencyKey: command.idempotencyKey,
        correlationId: command.correlationId,
        traceparent: command.traceparent,
      });
      const response: TokenScanCommandResponse = {
        operationId,
        scanExecutionId,
        status: 'accepted',
        duplicate: false,
      };
      await this.commandInbox.complete(transaction, operationId, response);

      return response;
    });
  }
}

function isTokenScanResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return typeof response['operationId'] === 'string'
    && typeof response['scanExecutionId'] === 'string'
    && response['status'] === 'accepted'
    && typeof response['duplicate'] === 'boolean';
}
