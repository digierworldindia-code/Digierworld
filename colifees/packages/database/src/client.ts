/**
 * Prisma client factory.
 *
 * The only process that ever constructs this is the API server. Neither
 * Next.js application imports it: the public website and the console reach the
 * database exclusively through the API over the private network, so a
 * compromised front-end never holds a database connection.
 */
import { PrismaClient, Prisma } from '@prisma/client';

// Re-exported wholesale so consumers get both the runtime `Prisma` namespace
// (Decimal, InputJsonValue, error classes) and every generated model type.
export * from '@prisma/client';

export interface CreateClientOptions {
  databaseUrl?: string;
  /** Emit query timings to the logger. Never enabled in production. */
  logQueries?: boolean;
}

export function createPrismaClient(options: CreateClientOptions = {}): PrismaClient {
  return new PrismaClient({
    datasources: options.databaseUrl ? { db: { url: options.databaseUrl } } : undefined,
    // `error` and `warn` carry no parameter values; `query` is opt-in and
    // development-only because bound parameters can contain personal data.
    log: options.logQueries
      ? [{ emit: 'event', level: 'query' }, 'warn', 'error']
      : ['warn', 'error'],
    errorFormat: 'minimal',
  });
}

let singleton: PrismaClient | undefined;

/** Reuses one pool per process; safe to call from anywhere in the API. */
export function getPrisma(options: CreateClientOptions = {}): PrismaClient {
  if (!singleton) singleton = createPrismaClient(options);
  return singleton;
}

export async function disconnectPrisma(): Promise<void> {
  if (singleton) {
    await singleton.$disconnect();
    singleton = undefined;
  }
}

/**
 * Prisma error codes the API maps to friendly messages. Raw Prisma errors are
 * never sent to a client.
 */
export const PRISMA_ERROR = {
  UNIQUE_VIOLATION: 'P2002',
  FOREIGN_KEY_VIOLATION: 'P2003',
  RECORD_NOT_FOUND: 'P2025',
} as const;

export function isPrismaKnownError(error: unknown): error is Prisma.PrismaClientKnownRequestError {
  return error instanceof Prisma.PrismaClientKnownRequestError;
}
