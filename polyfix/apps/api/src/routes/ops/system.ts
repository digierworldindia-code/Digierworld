/**
 * Administration and technical tools.
 *
 * Everything in this module is reachable only by a holder of an MFA-gated
 * permission (see MFA_GATED_PERMISSIONS in @polyfix/auth). Holding the role is
 * not enough: the current session must have completed a second factor.
 */
import type { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { auditListQuery, readOnlySqlSchema, updateSettingSchema, pagination } from '@polyfix/validation';
import { parse, noStore, paginate, skipTake } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { securityLog } from '../../lib/logger.js';
import { actorFrom, recordAudit } from '../../services/audit.js';
import { runBackup, runReadOnlyQuery } from '../../services/backup.service.js';

export async function registerSystemRoutes(app: FastifyInstance): Promise<void> {
  // =========================================================================
  // Audit trail — readable, never writable, never deletable
  // =========================================================================
  app.get('/audit', { preHandler: app.requirePermission('audit:read') }, async (request, reply) => {
    const query = parse(auditListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where = {
        ...(query.action ? { action: query.action } : {}),
        ...(query.entity ? { entity: query.entity } : {}),
        ...(query.entityId ? { entityId: query.entityId } : {}),
        ...(query.userId ? { userId: query.userId } : {}),
        ...(query.from || query.to
          ? {
              occurredAt: {
                ...(query.from ? { gte: new Date(query.from) } : {}),
                ...(query.to ? { lte: new Date(query.to) } : {}),
              },
            }
          : {}),
      };
      const [items, total] = await Promise.all([
        tx.auditLog.findMany({
          where,
          orderBy: { occurredAt: 'desc' },
          skip,
          take,
          select: {
            id: true, occurredAt: true, userEmail: true, roleKey: true, action: true,
            entity: true, entityId: true, ip: true, previousValue: true, newValue: true,
            reason: true, requestId: true,
          },
        }),
        tx.auditLog.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((a) => ({
        id: a.id.toString(),
        occurredAt: a.occurredAt,
        user: a.userEmail,
        role: a.roleKey,
        action: a.action,
        entity: a.entity,
        entityId: a.entityId,
        ip: a.ip,
        previousValue: a.previousValue,
        newValue: a.newValue,
        reason: a.reason,
        requestId: a.requestId,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  /**
   * Re-walks the audit hash chain. An empty `problems` array means the trail is
   * internally consistent; entries mean a row was altered outside the
   * application, for example by restoring a doctored dump.
   */
  app.get('/audit/verify', { preHandler: app.requirePermission('audit:read') }, async (request, reply) => {
    const fromId = parse(z.coerce.number().int().min(0).default(0), (request.query as { fromId?: string })?.fromId ?? 0);

    const problems = await app.prisma.$queryRaw<{ id: bigint; occurred_at: Date; problem: string }[]>`
      SELECT * FROM colifees_verify_audit_chain(${BigInt(fromId)}::bigint, 100000)
    `;
    const total = await app.prisma.auditLog.count();

    await app.prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await recordAudit(tx, actorFrom(request), {
        action: 'AUDIT_CHAIN_VERIFIED',
        entity: 'audit_logs',
        newValue: { entriesChecked: total, problemsFound: problems.length },
      });
    });

    if (problems.length > 0) {
      securityLog('AUDIT_CHAIN_BROKEN', { problemCount: problems.length, firstId: problems[0]?.id?.toString() }, 'error');
    }

    noStore(reply);
    return {
      entriesChecked: total,
      intact: problems.length === 0,
      problems: problems.map((p) => ({ id: p.id.toString(), occurredAt: p.occurred_at, problem: p.problem })),
    };
  });

  // =========================================================================
  // Record history
  // =========================================================================
  app.get<{ Params: { entity: string; id: string } }>(
    '/history/:entity/:id',
    { preHandler: app.requirePermission('audit:read') },
    async (request, reply) => {
      const entity = parse(z.string().trim().max(60).regex(/^[a-z_]+$/), request.params.entity);
      const entityId = parse(z.string().trim().max(64), request.params.id);

      const versions = await request.db((tx) =>
        tx.recordVersion.findMany({
          where: { entity, entityId },
          orderBy: { version: 'desc' },
          take: 50,
          select: { version: true, data: true, changedAt: true, reason: true, changedById: true },
        }),
      );

      noStore(reply);
      return { entity, entityId, versions };
    },
  );

  // =========================================================================
  // Settings
  // =========================================================================
  app.get('/settings', { preHandler: app.requirePermission('system:settings:read') }, async (request, reply) => {
    const settings = await request.db((tx) =>
      tx.systemSetting.findMany({
        // A setting flagged secret is never returned by any read endpoint, not
        // even to a super admin. Secrets belong in the secret manager.
        where: { isSecret: false },
        orderBy: [{ category: 'asc' }, { key: 'asc' }],
        select: { key: true, value: true, description: true, category: true, updatedAt: true },
      }),
    );
    noStore(reply);
    return { settings };
  });

  app.put<{ Params: { key: string } }>(
    '/settings/:key',
    { preHandler: app.requirePermission('system:settings:write') },
    async (request, reply) => {
      const key = parse(z.string().trim().min(1).max(80).regex(/^[a-z0-9_.]+$/), request.params.key);
      const input = parse(updateSettingSchema, request.body);

      const setting = await request.db(async (tx) => {
        const before = await tx.systemSetting.findUnique({ where: { key } });
        if (!before) throw errors.notFound('setting');
        if (before.isSecret) {
          throw errors.forbidden('secret settings are not editable through the API', 'That value is managed in the secret store, not here.');
        }

        const record = await tx.systemSetting.update({
          where: { key },
          data: { value: input.value as never, updatedById: request.auth?.userId ?? null },
          select: { key: true, value: true, category: true },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'SETTING_CHANGED',
          entity: 'system_setting',
          entityId: key,
          previousValue: { value: before.value },
          newValue: { value: input.value },
        });

        return record;
      });

      securityLog('SETTING_CHANGED', { key, by: request.auth?.userId }, 'info');
      noStore(reply);
      return setting;
    },
  );

  // =========================================================================
  // Health
  // =========================================================================
  app.get('/health', { preHandler: app.requirePermission('system:health') }, async (request, reply) => {
    const [dbInfo, migrations, counts, lastBackup, rlsTables, policies] = await Promise.all([
      app.prisma.$queryRaw<{ version: string; size: string; connections: bigint }[]>`
        SELECT
          current_setting('server_version') AS version,
          pg_size_pretty(pg_database_size(current_database())) AS size,
          (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()) AS connections
      `,
      app.prisma.$queryRaw<{ migration_name: string; finished_at: Date | null }[]>`
        SELECT migration_name, finished_at
        FROM _prisma_migrations
        ORDER BY finished_at DESC NULLS FIRST
        LIMIT 5
      `,
      app.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
        const [mattresses, dealers, claims, audits] = await Promise.all([
          tx.mattress.count(),
          tx.dealer.count(),
          tx.warrantyClaim.count(),
          tx.auditLog.count(),
        ]);
        return { mattresses, dealers, claims, audits };
      }),
      app.prisma.backupRun.findFirst({
        where: { status: 'SUCCESS' },
        orderBy: { startedAt: 'desc' },
        select: { kind: true, startedAt: true, finishedAt: true, byteSize: true, offsiteCopied: true },
      }),
      // A dealer-scoped table without Row Level Security is a finding, so it is
      // surfaced here rather than left to a periodic audit.
      app.prisma.$queryRaw<{ relname: string }[]>`
        SELECT DISTINCT c.relname
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN information_schema.columns col
          ON col.table_name = c.relname AND col.table_schema = n.nspname
        WHERE n.nspname = 'public' AND c.relkind = 'r'
          AND col.column_name IN ('dealer_id', 'current_dealer_id')
          AND NOT c.relrowsecurity
      `,
      app.prisma.$queryRaw<{ count: bigint }[]>`
        SELECT count(*)::bigint AS count FROM pg_policies WHERE schemaname = 'public'
      `,
    ]);

    const info = dbInfo[0];
    const unprotected = rlsTables.map((t) => t.relname);

    noStore(reply);
    return {
      database: {
        // Major version only: a precise patch level is reconnaissance material.
        version: info?.version?.split('.')[0] ?? 'unknown',
        size: info?.size ?? 'unknown',
        activeConnections: Number(info?.connections ?? 0),
      },
      migrations: {
        applied: migrations.map((m) => ({ name: m.migration_name, appliedAt: m.finished_at })),
        pending: migrations.filter((m) => m.finished_at === null).length,
      },
      records: counts,
      lastBackup: lastBackup
        ? {
            kind: lastBackup.kind,
            startedAt: lastBackup.startedAt,
            finishedAt: lastBackup.finishedAt,
            bytes: lastBackup.byteSize ? Number(lastBackup.byteSize) : null,
            offsiteCopied: lastBackup.offsiteCopied,
            ageHours: Math.floor((Date.now() - lastBackup.startedAt.getTime()) / 3_600_000),
          }
        : null,
      rowLevelSecurity: {
        policyCount: Number(policies[0]?.count ?? 0),
        dealerTablesWithoutRls: unprotected,
        healthy: unprotected.length === 0,
      },
      warnings: [
        ...(unprotected.length > 0
          ? [`Row Level Security is not enabled on: ${unprotected.join(', ')}. Re-run migration 0002.`]
          : []),
        ...(!lastBackup ? ['No successful backup has been recorded yet.'] : []),
        ...(lastBackup && Date.now() - lastBackup.startedAt.getTime() > 36 * 3_600_000
          ? ['The most recent successful backup is more than 36 hours old.']
          : []),
        ...(lastBackup && !lastBackup.offsiteCopied
          ? ['The most recent backup has not been copied off this server.']
          : []),
      ],
    };
  });

  // =========================================================================
  // Backups
  // =========================================================================
  app.get('/backups', { preHandler: app.requirePermission('system:backup') }, async (request, reply) => {
    const query = parse(pagination, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const [items, total] = await Promise.all([
      app.prisma.backupRun.findMany({
        orderBy: { startedAt: 'desc' },
        skip,
        take,
        select: {
          id: true, kind: true, status: true, startedAt: true, finishedAt: true,
          byteSize: true, checksum: true, encrypted: true, offsiteCopied: true,
          requestedBy: { select: { fullName: true, email: true } },
        },
      }),
      app.prisma.backupRun.count(),
    ]);

    noStore(reply);
    return paginate(
      items.map((b) => ({
        id: b.id,
        kind: b.kind,
        status: b.status,
        startedAt: b.startedAt,
        finishedAt: b.finishedAt,
        bytes: b.byteSize ? Number(b.byteSize) : null,
        checksum: b.checksum,
        encrypted: b.encrypted,
        offsiteCopied: b.offsiteCopied,
        // The filesystem path is not returned: it tells a reader where dumps
        // live on the host, which is not information a browser needs.
        requestedBy: b.requestedBy?.fullName ?? 'Scheduled job',
      })),
      total,
      query.page,
      query.pageSize,
    );
  });

  app.post(
    '/backups',
    {
      preHandler: app.requirePermission('system:backup'),
      config: { rateLimit: { max: 3, timeWindow: '1 hour' } },
    },
    async (request, reply) => {
      const input = parse(z.object({ kind: z.enum(['MANUAL', 'EXPORT']).default('MANUAL') }), request.body ?? {});

      securityLog('BACKUP_REQUESTED', { by: request.auth?.userId, kind: input.kind }, 'info');

      await app.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
        await recordAudit(tx, actorFrom(request), {
          action: 'BACKUP_REQUESTED',
          entity: 'backup_run',
          newValue: { kind: input.kind },
        });
      });

      const outcome = await runBackup(app.prisma, input.kind, request.auth?.userId ?? null);

      noStore(reply);
      return {
        id: outcome.id,
        kind: outcome.kind,
        status: outcome.status,
        bytes: outcome.byteSize,
        checksum: outcome.checksum,
        durationMs: outcome.durationMs,
        note: 'The dump is encrypted at rest. Copy it off this server before relying on it — a backup stored only beside the database is not a backup.',
      };
    },
  );

  // =========================================================================
  // Read-only SQL console
  // =========================================================================
  app.post(
    '/query',
    {
      preHandler: app.requirePermission('system:sql:read'),
      config: { rateLimit: { max: 20, timeWindow: '5 minutes' } },
    },
    async (request, reply) => {
      const input = parse(readOnlySqlSchema, request.body);

      // Audited before execution, so an attempt is recorded even if the query
      // fails or times out.
      await app.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
        await recordAudit(tx, actorFrom(request), {
          action: 'SQL_CONSOLE_QUERY',
          entity: 'database',
          newValue: { sql: input.sql, maxRows: input.maxRows },
        });
      });
      securityLog('SQL_CONSOLE_QUERY', { by: request.auth?.userId, sqlLength: input.sql.length });

      try {
        const result = await runReadOnlyQuery(app.prisma, input.sql, input.maxRows);
        noStore(reply);
        return {
          ...result,
          readOnly: true,
          note: result.truncated ? `Showing the first ${input.maxRows} rows.` : undefined,
        };
      } catch (error) {
        // The database's own message is useful to a technical administrator and
        // is safe here: reaching this endpoint already requires the highest
        // permission in the system plus a second factor.
        const message = error instanceof Error ? error.message : String(error);
        request.log.warn({ err: error, userId: request.auth?.userId }, 'sql console query failed');
        throw errors.businessRule(`The query could not be run: ${message.split('\n')[0]}`);
      }
    },
  );
}
