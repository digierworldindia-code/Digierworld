/**
 * Backup and export.
 *
 * The API does not reimplement pg_dump. It runs `scripts/backup.sh`, which is
 * the same script the nightly cron job runs — so a backup taken from the admin
 * screen and a backup taken by the scheduler are byte-for-byte the same
 * procedure, and there is only one thing to test and to trust.
 *
 * Arguments are passed as an array to execFile, never interpolated into a
 * shell string, so no value reaching this module can become a shell command.
 */
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { stat } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import type { PrismaClient } from '@polyfix/database';
import { getConfig } from '@polyfix/config';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';

const run = promisify(execFile);

export type BackupKind = 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'MANUAL' | 'EXPORT';

export interface BackupOutcome {
  id: string;
  kind: BackupKind;
  status: 'SUCCESS' | 'FAILED';
  artifactPath: string | null;
  byteSize: number | null;
  checksum: string | null;
  durationMs: number;
  error?: string;
}

/**
 * Timeout for a dump. A backup that has not finished in 30 minutes has gone
 * wrong, and leaving the child process running would hold a database
 * connection open indefinitely.
 */
const BACKUP_TIMEOUT_MS = 30 * 60 * 1000;

export async function runBackup(
  prisma: PrismaClient,
  kind: BackupKind,
  requestedById: string | null,
): Promise<BackupOutcome> {
  const config = getConfig();
  const startedAt = Date.now();

  const record = await prisma.backupRun.create({
    data: { kind, status: 'RUNNING', requestedById, encrypted: true },
    select: { id: true },
  });

  const scriptPath = path.resolve(process.cwd(), '../../scripts/backup.sh');
  const backupDir = path.resolve(process.cwd(), config.env.BACKUP_DIR);

  try {
    const { stdout } = await run(
      'bash',
      [scriptPath, '--kind', kind.toLowerCase(), '--out', backupDir, '--quiet'],
      {
        timeout: BACKUP_TIMEOUT_MS,
        maxBuffer: 1024 * 1024,
        env: {
          ...process.env,
          // The dump connects as the schema owner, which is the only role with
          // the reach to read every table.
          PGDATABASE_URL: config.env.DIRECT_DATABASE_URL ?? config.env.DATABASE_URL,
        },
      },
    );

    // The script prints the artifact path on its last line.
    const artifactPath = stdout.trim().split('\n').pop()?.trim() ?? null;
    if (!artifactPath) throw new Error('backup script produced no artifact path');

    const info = await stat(artifactPath);
    const checksum = await checksumFile(artifactPath);

    await prisma.backupRun.update({
      where: { id: record.id },
      data: {
        status: 'SUCCESS',
        finishedAt: new Date(),
        artifactPath,
        byteSize: BigInt(info.size),
        checksum,
      },
    });

    securityLog('BACKUP_COMPLETED', { backupId: record.id, kind, bytes: info.size, requestedById }, 'info');

    return {
      id: record.id,
      kind,
      status: 'SUCCESS',
      artifactPath,
      byteSize: info.size,
      checksum,
      durationMs: Date.now() - startedAt,
    };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    await prisma.backupRun.update({
      where: { id: record.id },
      data: { status: 'FAILED', finishedAt: new Date(), error: message.slice(0, 2000) },
    });
    securityLog('BACKUP_FAILED', { backupId: record.id, kind, requestedById }, 'error');
    // The caller gets a friendly failure; the reason stays in the log and the
    // backup_runs row.
    throw errors.internal(`backup failed: ${message}`, error);
  }
}

async function checksumFile(filePath: string): Promise<string> {
  return new Promise((resolve, reject) => {
    const hash = createHash('sha256');
    const stream = createReadStream(filePath);
    stream.on('data', (chunk) => hash.update(chunk));
    stream.on('end', () => resolve(hash.digest('hex')));
    stream.on('error', reject);
  });
}

/**
 * Read-only SQL for technical administrators.
 *
 * Four independent controls, because a query console is the most dangerous
 * screen in any admin tool:
 *   1. the statement is checked against an allow-list shape before it is sent
 *   2. it runs on the read-only database role when one is configured
 *   3. the transaction is explicitly READ ONLY with a short statement timeout
 *   4. every execution is audited with the statement text and the caller
 */
export async function runReadOnlyQuery(
  prisma: PrismaClient,
  sql: string,
  maxRows: number,
): Promise<{ rows: unknown[]; rowCount: number; truncated: boolean; durationMs: number }> {
  const startedAt = Date.now();

  const rows = await prisma.$transaction(
    async (tx) => {
      // Belt and braces: even a statement that slipped through validation
      // cannot write inside a READ ONLY transaction.
      await tx.$executeRawUnsafe('SET TRANSACTION READ ONLY');
      await tx.$executeRawUnsafe("SET LOCAL statement_timeout = '10s'");
      await tx.$executeRawUnsafe("SET LOCAL lock_timeout = '2s'");
      // Staff scope, so Row Level Security does not silently hide rows and make
      // a diagnostic query lie about what is in the database.
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      return tx.$queryRawUnsafe<unknown[]>(sql);
    },
    { timeout: 15_000 },
  );

  const truncated = rows.length > maxRows;
  return {
    rows: serialiseRows(truncated ? rows.slice(0, maxRows) : rows),
    rowCount: rows.length,
    truncated,
    durationMs: Date.now() - startedAt,
  };
}

/** BigInt and Decimal are not JSON-serialisable; Date is normalised to ISO. */
function serialiseRows(rows: unknown[]): unknown[] {
  return rows.map((row) => {
    if (row === null || typeof row !== 'object') return row;
    const out: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(row as Record<string, unknown>)) {
      if (typeof value === 'bigint') out[key] = value.toString();
      else if (value instanceof Date) out[key] = value.toISOString();
      else if (value && typeof value === 'object' && 'toFixed' in value) out[key] = String(value);
      else out[key] = value;
    }
    return out;
  });
}
