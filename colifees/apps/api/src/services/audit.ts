/**
 * Audit trail.
 *
 * Audit rows are written inside the same transaction as the change they
 * describe. If the business write rolls back, so does its audit row; if the
 * audit write fails, the business change does not happen. There is no code path
 * that records one without the other.
 *
 * The chain hashes (prev_hash, row_hash) are computed by a database trigger,
 * not here — see migration 0002. That means a bug or a compromise in this
 * process cannot produce a self-consistent forged history.
 */
import type { Prisma, TransactionClient } from '@colifees/database';
import type { FastifyRequest } from 'fastify';

export interface AuditInput {
  action: string;
  entity: string;
  entityId?: string | null;
  previousValue?: unknown;
  newValue?: unknown;
  reason?: string | null;
}

export interface AuditActor {
  userId?: string | null;
  userEmail?: string | null;
  roleKey?: string | null;
  dealerId?: string | null;
  ip?: string | null;
  userAgent?: string | null;
  requestId?: string | null;
}

/** Fields that must never reach the audit trail even as a "previous value". */
const SENSITIVE_KEYS = new Set([
  'passwordHash',
  'password',
  'newPassword',
  'currentPassword',
  'mfaSecretEncrypted',
  'mfaRecoveryCodes',
  'tokenHash',
  'phoneEncrypted',
  'emailEncrypted',
  'token',
  'refreshToken',
  'accessToken',
]);

/**
 * Strips credential material and truncates long values. An audit record should
 * say what changed, not become a second copy of the data.
 */
export function sanitiseForAudit(value: unknown, depth = 0): unknown {
  if (value === null || value === undefined) return value;
  if (depth > 4) return '[truncated]';
  if (value instanceof Date) return value.toISOString();
  if (Array.isArray(value)) return value.slice(0, 50).map((v) => sanitiseForAudit(v, depth + 1));
  if (typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
      if (SENSITIVE_KEYS.has(key)) {
        out[key] = '[redacted]';
        continue;
      }
      out[key] = sanitiseForAudit(item, depth + 1);
    }
    return out;
  }
  if (typeof value === 'string' && value.length > 2000) return `${value.slice(0, 2000)}…[truncated]`;
  if (typeof value === 'bigint') return value.toString();
  return value;
}

export async function recordAudit(
  tx: TransactionClient,
  actor: AuditActor,
  input: AuditInput,
): Promise<void> {
  const data: Prisma.AuditLogUncheckedCreateInput = {
    action: input.action,
    entity: input.entity,
    entityId: input.entityId ?? null,
    userId: actor.userId ?? null,
    userEmail: actor.userEmail ?? null,
    roleKey: actor.roleKey ?? null,
    dealerId: actor.dealerId ?? null,
    ip: actor.ip ?? null,
    userAgent: actor.userAgent?.slice(0, 400) ?? null,
    requestId: actor.requestId ?? null,
    reason: input.reason ?? null,
  };

  // Assigned only when present: a JSON column will not accept an explicit
  // `undefined`, and a null would wrongly claim "the value was empty".
  if (input.previousValue !== undefined) {
    data.previousValue = sanitiseForAudit(input.previousValue) as Prisma.InputJsonValue;
  }
  if (input.newValue !== undefined) {
    data.newValue = sanitiseForAudit(input.newValue) as Prisma.InputJsonValue;
  }

  await tx.auditLog.create({ data });
}

/**
 * Point-in-time snapshot for entities where silent manipulation would matter.
 * Sits alongside the audit row and answers "what did this record look like
 * before?" without replaying the whole trail.
 */
export async function recordVersion(
  tx: TransactionClient,
  entity: string,
  entityId: string,
  data: unknown,
  changedById: string | null,
  reason?: string | null,
): Promise<void> {
  const latest = await tx.recordVersion.findFirst({
    where: { entity, entityId },
    orderBy: { version: 'desc' },
    select: { version: true },
  });
  await tx.recordVersion.create({
    data: {
      entity,
      entityId,
      version: (latest?.version ?? 0) + 1,
      data: sanitiseForAudit(data) as Prisma.InputJsonValue,
      changedById,
      reason: reason ?? null,
    },
  });
}

/** Builds an actor descriptor from the request, for handlers to pass along. */
export function actorFrom(request: FastifyRequest): AuditActor {
  return {
    userId: request.auth?.userId ?? null,
    userEmail: request.auth?.email ?? null,
    roleKey: request.auth?.roles?.[0] ?? null,
    dealerId: request.auth?.dealerId ?? null,
    ip: request.ip,
    userAgent: typeof request.headers['user-agent'] === 'string' ? request.headers['user-agent'] : null,
    requestId: request.id,
  };
}
