/**
 * CSRF protection for cookie-authenticated requests.
 *
 * The session travels in a cookie, which a browser attaches automatically — so
 * a state-changing request needs a second factor the attacker's page cannot
 * read or set. Three checks, all of which must pass:
 *
 *   1. SameSite=Strict on the session cookies (set in cookies.ts)
 *   2. a double-submit token: the `clf_csrf` cookie must equal the
 *      `x-csrf-token` header, compared in constant time
 *   3. the Origin header, when present, must be an allowed origin
 *
 * Safe methods are exempt because they must not change state. Routes that are
 * not cookie-authenticated (public forms, the internal server-to-server
 * channel) are exempt and rely on rate limiting and their own token instead.
 */
import type { FastifyInstance, FastifyRequest } from 'fastify';
import fp from 'fastify-plugin';
import { getConfig } from '@polyfix/config';
import { safeEqual } from '@polyfix/auth';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';
import { CSRF_COOKIE, CSRF_HEADER, ACCESS_TOKEN_COOKIE } from '../lib/cookies.js';

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

/** Routes that authenticate by something other than the session cookie. */
const EXEMPT_PREFIXES = ['/public/', '/health', '/auth/login', '/auth/forgot-password', '/auth/reset-password'];

async function csrfPlugin(app: FastifyInstance): Promise<void> {
  const config = getConfig();

  app.addHook('onRequest', async (request: FastifyRequest) => {
    if (SAFE_METHODS.has(request.method)) return;
    if (EXEMPT_PREFIXES.some((prefix) => request.url.startsWith(prefix))) return;

    // No session cookie means no cookie-driven authority to abuse.
    if (!request.cookies[ACCESS_TOKEN_COOKIE]) return;

    const origin = request.headers.origin;
    if (origin && !config.allowedOrigins.includes(origin)) {
      securityLog('CSRF_ORIGIN_REJECTED', { origin, path: request.url });
      throw errors.forbidden('origin not allowed', 'That request could not be verified. Please reload the page and try again.');
    }

    const cookieToken = request.cookies[CSRF_COOKIE];
    const headerToken = request.headers[CSRF_HEADER];
    const headerValue = Array.isArray(headerToken) ? headerToken[0] : headerToken;

    if (!cookieToken || !headerValue || !safeEqual(cookieToken, headerValue)) {
      securityLog('CSRF_TOKEN_MISMATCH', {
        path: request.url,
        method: request.method,
        hasCookie: Boolean(cookieToken),
        hasHeader: Boolean(headerValue),
      });
      throw errors.forbidden(
        'csrf token missing or mismatched',
        'That request could not be verified. Please reload the page and try again.',
      );
    }
  });
}

export default fp(csrfPlugin, { name: 'polyfix-csrf' });
