/**
 * Transport-level protections applied to every request.
 *
 * Split out from the route layer so that adding a route cannot accidentally
 * opt out of them.
 */
import type { FastifyInstance } from 'fastify';
import helmet from '@fastify/helmet';
import cors from '@fastify/cors';
import cookie from '@fastify/cookie';
import rateLimit from '@fastify/rate-limit';
import { getConfig } from '@colifees/config';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';

export async function registerSecurity(app: FastifyInstance): Promise<void> {
  const config = getConfig();

  // --- response headers ----------------------------------------------------
  // The API serves JSON only, so its own CSP can be maximally restrictive: it
  // never needs to load a script, a style or a frame. The two Next.js
  // applications set their own policies, which are necessarily looser.
  await app.register(helmet, {
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'none'"],
        frameAncestors: ["'none'"],
        baseUri: ["'none'"],
        formAction: ["'none'"],
        // Media is delivered as a signed, short-lived download from this API.
        imgSrc: ["'none'"],
        scriptSrc: ["'none'"],
        styleSrc: ["'none'"],
        connectSrc: ["'none'"],
        objectSrc: ["'none'"],
      },
    },
    crossOriginResourcePolicy: { policy: 'same-site' },
    crossOriginOpenerPolicy: { policy: 'same-origin' },
    crossOriginEmbedderPolicy: false,
    referrerPolicy: { policy: 'no-referrer' },
    // HSTS is only meaningful over TLS and only set where TLS is terminated
    // in front of this service.
    hsts: config.isProduction
      ? { maxAge: 63_072_000, includeSubDomains: true, preload: true }
      : false,
    xFrameOptions: { action: 'deny' },
    noSniff: true,
    permittedCrossDomainPolicies: { permittedPolicies: 'none' },
  });

  app.addHook('onSend', async (_request, reply, payload) => {
    reply.header(
      'Permissions-Policy',
      'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
    );
    // Nothing this API returns should ever be cached by a shared cache.
    if (!reply.hasHeader('Cache-Control')) {
      reply.header('Cache-Control', 'no-store');
    }
    reply.removeHeader('X-Powered-By');
    return payload;
  });

  // --- cross-origin --------------------------------------------------------
  // An explicit allow-list. `origin: true` would reflect any origin, which with
  // credentials enabled is equivalent to having no CORS policy at all.
  await app.register(cors, {
    origin(origin, callback) {
      if (!origin) {
        // Same-origin, server-to-server and health checks send no Origin.
        callback(null, true);
        return;
      }
      if (config.allowedOrigins.includes(origin)) {
        callback(null, true);
        return;
      }
      securityLog('CORS_REJECTED', { origin }, 'warn');
      callback(null, false);
    },
    credentials: true,
    methods: ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['content-type', 'x-csrf-token', 'x-internal-token', 'x-request-id'],
    exposedHeaders: ['x-request-id'],
    maxAge: 600,
  });

  // --- cookies -------------------------------------------------------------
  await app.register(cookie, {
    secret: config.env.AUTH_SECRET,
    parseOptions: {
      httpOnly: true,
      sameSite: config.cookie.sameSite,
      secure: config.cookie.secure,
      path: '/',
      ...(config.cookie.domain ? { domain: config.cookie.domain } : {}),
    },
  });

  // --- global rate limit ---------------------------------------------------
  // A backstop across the whole surface. Sensitive routes add their own,
  // tighter limits on top of this one.
  await app.register(rateLimit, {
    global: true,
    max: config.env.RATE_LIMIT_GLOBAL_PER_MINUTE,
    timeWindow: '1 minute',
    // Only trust a forwarded client address when explicitly configured to sit
    // behind a proxy; otherwise anyone could rotate the header to reset limits.
    keyGenerator(request) {
      return clientAddress(request.ip, request.headers['x-forwarded-for'], config.env.TRUST_PROXY);
    },
    // @fastify/rate-limit throws whatever this returns, so it is shaped as an
    // error the central handler recognises (statusCode at the top level) rather
    // than as a ready-made response body. Returning the bare response shape
    // here produced a 500 instead of a 429, which both hid the limit from
    // clients and logged every throttled request as an unhandled failure.
    errorResponseBuilder(_request, context) {
      const retryAfter = Math.ceil(Number(context.ttl) / 1000) || 60;
      const error = errors.rateLimited(retryAfter);
      return {
        statusCode: 429,
        code: error.code,
        error: 'Too Many Requests',
        message: error.publicMessage,
        retryAfter,
      };
    },
    onExceeding(request) {
      securityLog('RATE_LIMIT_APPROACHING', { path: request.url, method: request.method }, 'info');
    },
    onExceeded(request) {
      securityLog('RATE_LIMIT_EXCEEDED', { path: request.url, method: request.method });
    },
  });
}

export function clientAddress(
  socketIp: string,
  forwardedFor: string | string[] | undefined,
  trustProxy: boolean,
): string {
  if (!trustProxy || !forwardedFor) return socketIp;
  const raw = Array.isArray(forwardedFor) ? forwardedFor[0] : forwardedFor;
  const first = raw?.split(',')[0]?.trim();
  return first && first.length > 0 ? first : socketIp;
}
