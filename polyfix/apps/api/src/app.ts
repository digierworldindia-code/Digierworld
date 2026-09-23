/**
 * API composition.
 *
 * Order matters here. Security headers and CORS come first so that even a
 * request that is about to be rejected gets the right response headers;
 * database context and authentication decorate the request before any route
 * handler can run; and the error handler is registered last so it catches
 * everything above it.
 */
import Fastify, { type FastifyInstance } from 'fastify';
import multipart from '@fastify/multipart';
import { randomUUID } from 'node:crypto';
import { getConfig } from '@polyfix/config';
import { getLogger, securityLog } from './lib/logger.js';
import { AppError, errors, isAppError } from './lib/errors.js';
import { registerSecurity, clientAddress } from './plugins/security.js';
import prismaPlugin from './plugins/prisma.js';
import authPlugin from './plugins/auth.js';
import csrfPlugin from './plugins/csrf.js';
import { registerHealthRoutes } from './routes/health.js';
import { registerAuthRoutes } from './routes/auth/index.js';
import { registerPublicRoutes } from './routes/public/index.js';
import { registerOpsRoutes } from './routes/ops/index.js';
import { registerDealerRoutes } from './routes/dealer/index.js';
import { registerMediaRoutes } from './routes/media.js';

export async function buildApp(): Promise<FastifyInstance> {
  const config = getConfig();
  const logger = getLogger();

  // The cast reconciles pino's concrete Logger with Fastify's structural
  // FastifyBaseLogger. They are compatible at runtime; the generics are not.
  const app = Fastify({
    loggerInstance: logger,
    // Requests are correlated by an id that appears in every log line and in
    // every error response, so a user can quote it to support and an operator
    // can find the exact request.
    genReqId: (request) => {
      const supplied = request.headers['x-request-id'];
      const value = Array.isArray(supplied) ? supplied[0] : supplied;
      return value && /^[A-Za-z0-9-]{8,64}$/.test(value) ? value : randomUUID();
    },
    requestIdHeader: false,
    trustProxy: config.env.TRUST_PROXY,
    // A JSON body larger than this is refused before it is parsed.
    bodyLimit: 1_048_576,
    ajv: { customOptions: { removeAdditional: 'all', coerceTypes: false } },
  }) as unknown as FastifyInstance;

  await registerSecurity(app);

  // File uploads. Limits are enforced by the parser, not checked afterwards,
  // so an oversized file is cut off rather than buffered in full.
  await app.register(multipart, {
    limits: {
      fileSize: config.env.MAX_UPLOAD_BYTES,
      files: config.env.MAX_UPLOADS_PER_CLAIM,
      fields: 10,
      fieldSize: 4_096,
      headerPairs: 100,
    },
  });

  await app.register(prismaPlugin);
  await app.register(authPlugin);
  await app.register(csrfPlugin);

  app.addHook('onSend', async (request, reply, payload) => {
    reply.header('x-request-id', request.id);
    return payload;
  });

  // --- error handling ------------------------------------------------------
  // Registered BEFORE the route plugins, and this order is load-bearing.
  // Fastify copies the parent's error handler into each encapsulated child
  // context at the moment that context is created. A handler installed after
  // `register()` therefore never applies to those routes: they keep Fastify's
  // default handler, which serialises the raw error message straight to the
  // caller. That would leak internals on any unexpected failure.
  // --- not found -----------------------------------------------------------
  app.setNotFoundHandler(
    {
      preHandler: app.rateLimit({ max: 30, timeWindow: '1 minute' }),
    },
    (request, reply) => {
      reply.status(404).send({
        error: { code: 'NOT_FOUND', message: 'That endpoint does not exist.', requestId: request.id },
      });
    },
  );

  // --- errors --------------------------------------------------------------
  // The single place a failure becomes a response. Everything that is not an
  // AppError is logged in full and reported as a generic failure, so a stack
  // trace, a SQL fragment or a Prisma message can never reach a caller.
  app.setErrorHandler((error, request, reply) => {
    const appError = normaliseError(error);

    if (appError.securityEvent) {
      securityLog(appError.securityEvent, {
        requestId: request.id,
        path: request.url,
        method: request.method,
        userId: request.auth?.userId ?? null,
        ip: clientAddress(request.ip, request.headers['x-forwarded-for'], config.env.TRUST_PROXY),
      });
    }

    if (appError.statusCode >= 500) {
      request.log.error(
        { err: error, requestId: request.id, path: request.url, userId: request.auth?.userId ?? null },
        appError.internal ?? 'unhandled error',
      );
    } else {
      request.log.info(
        { code: appError.code, requestId: request.id, path: request.url, detail: appError.internal },
        'request rejected',
      );
    }

    reply.status(appError.statusCode).send({
      error: {
        code: appError.code,
        message: appError.publicMessage,
        ...(appError.details ? { details: appError.details } : {}),
        requestId: request.id,
      },
    });
  });


  // --- routes --------------------------------------------------------------
  await app.register(registerHealthRoutes);
  await app.register(registerAuthRoutes, { prefix: '/auth' });
  await app.register(registerPublicRoutes, { prefix: '/public' });
  await app.register(registerOpsRoutes, { prefix: '/ops' });
  await app.register(registerDealerRoutes, { prefix: '/dealer' });
  await app.register(registerMediaRoutes, { prefix: '/media' });

  return app;
}

function normaliseError(error: unknown): AppError {
  if (isAppError(error)) return error;

  const candidate = error as { statusCode?: number; code?: string; message?: string };

  // Fastify and its plugins raise these before a handler is reached.
  if (candidate?.code === 'FST_ERR_CTP_BODY_TOO_LARGE' || candidate?.statusCode === 413) {
    return errors.payloadTooLarge('That request or file was larger than the limit.');
  }
  if (candidate?.code === 'FST_REQ_FILE_TOO_LARGE') {
    return errors.payloadTooLarge('That file is larger than the upload limit.');
  }
  if (candidate?.code === 'FST_ERR_CTP_INVALID_MEDIA_TYPE' || candidate?.statusCode === 415) {
    return errors.unsupportedMedia('That content type is not accepted on this endpoint.');
  }
  if (candidate?.code === 'FST_ERR_VALIDATION' || candidate?.statusCode === 400) {
    return errors.validation({ _: ['The request could not be understood.'] });
  }
  if (candidate?.statusCode === 429) {
    const retryAfter = (error as { retryAfter?: number })?.retryAfter;
    return errors.rateLimited(typeof retryAfter === 'number' ? retryAfter : 60);
  }

  // Prisma failures are mapped to business language. The original code is kept
  // for the log only.
  const prismaCode = (error as { code?: string })?.code;
  if (typeof prismaCode === 'string' && prismaCode.startsWith('P')) {
    switch (prismaCode) {
      case 'P2002':
        return errors.conflict('That record already exists.', `prisma ${prismaCode}`);
      case 'P2003':
        return errors.businessRule('That change would break a link to another record.', `prisma ${prismaCode}`);
      case 'P2025':
        return errors.notFound('record', `prisma ${prismaCode}`);
      default:
        return errors.internal(`prisma error ${prismaCode}`, error);
    }
  }

  return errors.internal(candidate?.message ?? 'unhandled error', error);
}
