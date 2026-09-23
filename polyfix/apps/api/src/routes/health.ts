/**
 * Health endpoints.
 *
 * `/health` answers "is the process up" and is safe for a load balancer to poll
 * frequently. `/health/ready` also checks the database. Neither reveals
 * version numbers, hostnames, connection strings or dependency details — a
 * health endpoint is a reconnaissance target.
 */
import type { FastifyInstance } from 'fastify';

export async function registerHealthRoutes(app: FastifyInstance): Promise<void> {
  app.get('/health', { config: { rateLimit: { max: 120, timeWindow: '1 minute' } } }, async () => ({
    status: 'ok',
  }));

  app.get('/health/ready', { config: { rateLimit: { max: 60, timeWindow: '1 minute' } } }, async (_request, reply) => {
    try {
      await app.prisma.$queryRaw`SELECT 1`;
      return { status: 'ready' };
    } catch (error) {
      app.log.error({ err: error }, 'readiness check failed');
      return reply.status(503).send({ status: 'unavailable' });
    }
  });
}
