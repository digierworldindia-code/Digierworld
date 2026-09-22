/**
 * Database access for the request lifecycle.
 *
 * `app.prisma` is the raw client, used only where no dealer scope applies
 * (session lookup during authentication, audit writes).
 *
 * `request.db(fn)` is what route handlers use. It opens a transaction, publishes
 * the caller's dealer scope to PostgreSQL and runs the callback inside it, so
 * Row Level Security is in force for every statement the handler issues.
 */
import type { FastifyInstance, FastifyRequest } from 'fastify';
import fp from 'fastify-plugin';
import { getConfig } from '@colifees/config';
import { getPrisma, disconnectPrisma, withDbContext, type TransactionClient, type PrismaClient } from '@colifees/database';

declare module 'fastify' {
  interface FastifyInstance {
    prisma: PrismaClient;
  }
  interface FastifyRequest {
    /** Runs a unit of work inside this caller's database scope. */
    db: <T>(fn: (tx: TransactionClient) => Promise<T>) => Promise<T>;
    /** Runs a unit of work with staff-level visibility, for public endpoints. */
    publicDb: <T>(fn: (tx: TransactionClient) => Promise<T>) => Promise<T>;
  }
}

async function prismaPlugin(app: FastifyInstance): Promise<void> {
  const config = getConfig();
  const prisma = getPrisma({
    databaseUrl: config.env.DATABASE_URL,
    logQueries: false,
  });

  await prisma.$connect();
  app.decorate('prisma', prisma);

  // Declared up front so every request object has the same shape; the hook
  // below installs the real implementation.
  app.decorateRequest('db', undefined as unknown as FastifyRequest['db']);
  app.decorateRequest('publicDb', undefined as unknown as FastifyRequest['publicDb']);

  app.addHook('onRequest', async (request: FastifyRequest) => {
    request.db = <T>(fn: (tx: TransactionClient) => Promise<T>) =>
      withDbContext(
        prisma,
        {
          userId: request.auth?.userId ?? null,
          // The single place dealer scope is decided. It comes from the
          // authenticated session and from nowhere else — not a header, not a
          // query parameter, not a request body.
          dealerId: request.auth?.dealerId ?? null,
        },
        fn,
      );

    request.publicDb = <T>(fn: (tx: TransactionClient) => Promise<T>) =>
      withDbContext(prisma, { userId: null, dealerId: null }, fn);
  });

  app.addHook('onClose', async () => {
    await disconnectPrisma();
  });
}

export default fp(prismaPlugin, { name: 'colifees-prisma' });
