/**
 * Process entry point.
 *
 * Binds to HOST, which defaults to 127.0.0.1. In production the service listens
 * on a private interface and is reached only through the reverse proxy — it is
 * never published directly to the internet, and neither is the database it
 * talks to.
 */
import { getConfig } from '@polyfix/config';
import { buildApp } from './app.js';
import { getLogger } from './lib/logger.js';
import { BRAND } from '@polyfix/brand';

async function start(): Promise<void> {
  const config = getConfig();
  const logger = getLogger();
  const app = await buildApp();

  const shutdown = async (signal: string): Promise<void> => {
    logger.info({ signal }, 'shutting down');
    // Stops accepting new connections, finishes in-flight requests, then closes
    // the database pool through the onClose hook.
    await app.close();
    process.exit(0);
  };

  process.on('SIGTERM', () => void shutdown('SIGTERM'));
  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('unhandledRejection', (reason) => {
    logger.error({ err: reason }, 'unhandled promise rejection');
  });
  process.on('uncaughtException', (error) => {
    logger.fatal({ err: error }, 'uncaught exception, exiting');
    process.exit(1);
  });

  await app.listen({ port: config.env.PORT, host: config.env.HOST });
  logger.info(
    { port: config.env.PORT, host: config.env.HOST, env: config.env.APP_ENV },
    `${BRAND.name} API listening`,
  );
}

start().catch((error: unknown) => {
  // Configuration failures happen before the logger exists.
  process.stderr.write(`Failed to start API: ${error instanceof Error ? error.message : String(error)}\n`);
  process.exit(1);
});
