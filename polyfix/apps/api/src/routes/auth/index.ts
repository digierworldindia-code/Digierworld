/**
 * Authentication endpoints.
 *
 * Rate limits here are per source address and much tighter than the global
 * default — these are the endpoints an attacker will hammer.
 */
import type { FastifyInstance } from 'fastify';
import {
  loginSchema,
  changePasswordSchema,
  forgotPasswordSchema,
  resetPasswordSchema,
  mfaEnrolStartSchema,
  mfaEnrolConfirmSchema,
  mfaDisableSchema,
} from '@polyfix/validation';
import { randomToken } from '@polyfix/auth';
import { withStaffContext } from '@polyfix/database';
import { AuthService, type RequestMeta } from '../../services/auth.service.js';
import { parse, noStore } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import {
  setAuthCookies,
  clearAuthCookies,
  REFRESH_TOKEN_COOKIE,
  CSRF_COOKIE,
} from '../../lib/cookies.js';
import { getConfig } from '@polyfix/config';
import { BRAND } from '@polyfix/brand';

export async function registerAuthRoutes(app: FastifyInstance): Promise<void> {
  const service = new AuthService(app.prisma);
  const config = getConfig();

  const meta = (request: Parameters<typeof toMeta>[0]): RequestMeta => toMeta(request);

  // -------------------------------------------------------------------------
  app.post(
    '/login',
    {
      config: {
        // Deliberately strict: a handful of attempts a minute from one address
        // is far more than a person needs and far less than a guessing attack
        // wants. Configurable so an operator can tighten it further.
        rateLimit: { max: config.env.RATE_LIMIT_LOGIN_PER_MINUTE, timeWindow: '1 minute' },
      },
    },
    async (request, reply) => {
      const input = parse(loginSchema, request.body);
      const result = await service.login(input, meta(request));

      setAuthCookies(reply, {
        accessToken: result.accessToken,
        refreshToken: result.refreshToken,
        csrfToken: result.csrfToken,
      });

      noStore(reply);
      return {
        user: result.user,
        // The console reads this to decide where to send the user next.
        next: result.user.mustChangePassword
          ? 'CHANGE_PASSWORD'
          : result.user.mustEnrolMfa
            ? 'ENROL_MFA'
            : result.user.dealerId
              ? 'DEALER_HOME'
              : 'CONSOLE_HOME',
      };
    },
  );

  // -------------------------------------------------------------------------
  app.post(
    '/refresh',
    { config: { rateLimit: { max: 30, timeWindow: '1 minute' } } },
    async (request, reply) => {
      const presented = request.cookies[REFRESH_TOKEN_COOKIE];
      if (!presented) throw errors.sessionExpired();

      const rotated = await service.refresh(presented, meta(request));
      setAuthCookies(reply, {
        accessToken: rotated.accessToken,
        refreshToken: rotated.refreshToken,
        csrfToken: rotated.csrfToken,
      });
      noStore(reply);
      return { refreshed: true };
    },
  );

  // -------------------------------------------------------------------------
  app.post('/logout', { preHandler: app.authenticate }, async (request, reply) => {
    const auth = request.auth!;
    await service.logout(auth.sessionId, meta(request), { userId: auth.userId, email: auth.email });
    clearAuthCookies(reply);
    noStore(reply);
    return { signedOut: true };
  });

  // -------------------------------------------------------------------------
  app.get('/me', { preHandler: app.authenticate }, async (request, reply) => {
    const auth = request.auth!;
    const user = await app.prisma.user.findUniqueOrThrow({
      where: { id: auth.userId },
      select: {
        id: true,
        email: true,
        fullName: true,
        phone: true,
        mfaEnabled: true,
        mustChangePassword: true,
        lastLoginAt: true,
        dealerLink: { select: { dealer: { select: { id: true, code: true, businessName: true, city: true, state: true } } } },
      },
    });

    noStore(reply);
    // Built field by field; the row is never spread.
    return {
      user: {
        id: user.id,
        email: user.email,
        fullName: user.fullName,
        phone: user.phone,
        roles: auth.roles,
        permissions: auth.permissions,
        mfaEnabled: user.mfaEnabled,
        mfaSatisfied: auth.mfaSatisfied,
        mustChangePassword: user.mustChangePassword,
        lastLoginAt: user.lastLoginAt,
        dealer: user.dealerLink?.dealer ?? null,
      },
      csrfToken: request.cookies[CSRF_COOKIE] ?? null,
    };
  });

  // -------------------------------------------------------------------------
  app.post(
    '/change-password',
    { preHandler: app.authenticate, config: { rateLimit: { max: 5, timeWindow: '5 minutes' } } },
    async (request, reply) => {
      const auth = request.auth!;
      const input = parse(changePasswordSchema, request.body);
      await service.changePassword(auth.userId, input.currentPassword, input.newPassword, auth.sessionId, meta(request));
      noStore(reply);
      return { changed: true, otherSessionsSignedOut: true };
    },
  );

  // -------------------------------------------------------------------------
  app.post(
    '/forgot-password',
    { config: { rateLimit: { max: 3, timeWindow: '15 minutes' } } },
    async (request, reply) => {
      const input = parse(forgotPasswordSchema, request.body);
      const token = await service.requestPasswordReset(input.email, meta(request));

      if (token) {
        // In production this is handed to the mail service. It is never logged
        // and never returned in the response, so possession of the reset link
        // stays with the mailbox owner.
        //
        // Staff scope: `notifications` carries a dealer-isolation policy, and a
        // row with no dealer cannot be written from an unscoped connection.
        await withStaffContext(app.prisma, (tx) =>
          tx.notification.create({
            data: {
              channel: 'EMAIL',
              type: 'PASSWORD_RESET',
              title: `Reset your ${BRAND.shortName} password`,
              body: `${config.env.PRIVATE_APP_ORIGIN}/reset-password?token=${token}`,
              entity: 'user',
            },
          }),
        );
      }

      noStore(reply);
      // The same answer either way: whether an address is registered is not
      // something an anonymous caller gets to find out.
      return {
        message: 'If that email address has an account, a reset link is on its way. The link is valid for 30 minutes.',
      };
    },
  );

  // -------------------------------------------------------------------------
  app.post(
    '/reset-password',
    { config: { rateLimit: { max: 5, timeWindow: '15 minutes' } } },
    async (request, reply) => {
      const input = parse(resetPasswordSchema, request.body);
      await service.resetPassword(input.token, input.newPassword, meta(request));
      clearAuthCookies(reply);
      noStore(reply);
      return { reset: true };
    },
  );

  // -------------------------------------------------------------------------
  // Two-factor authentication
  // -------------------------------------------------------------------------
  app.post(
    '/mfa/start',
    { preHandler: app.authenticate, config: { rateLimit: { max: 5, timeWindow: '5 minutes' } } },
    async (request, reply) => {
      const auth = request.auth!;
      const input = parse(mfaEnrolStartSchema, request.body);
      const result = await service.startMfaEnrolment(auth.userId, input.password);
      noStore(reply);
      return result;
    },
  );

  app.post(
    '/mfa/confirm',
    { preHandler: app.authenticate, config: { rateLimit: { max: 10, timeWindow: '5 minutes' } } },
    async (request, reply) => {
      const auth = request.auth!;
      const input = parse(mfaEnrolConfirmSchema, request.body);
      const result = await service.confirmMfaEnrolment(auth.userId, input.totpCode, meta(request));
      noStore(reply);
      return {
        enabled: true,
        recoveryCodes: result.recoveryCodes,
        warning: 'These recovery codes are shown once. Store them somewhere safe — each one works a single time.',
      };
    },
  );

  app.post(
    '/mfa/disable',
    { preHandler: app.authenticate, config: { rateLimit: { max: 5, timeWindow: '15 minutes' } } },
    async (request, reply) => {
      const auth = request.auth!;
      const input = parse(mfaDisableSchema, request.body);
      await service.disableMfa(auth.userId, input.password, input.totpCode, meta(request));
      clearAuthCookies(reply);
      noStore(reply);
      return { disabled: true, signedOut: true };
    },
  );

  // -------------------------------------------------------------------------
  app.get('/sessions', { preHandler: app.authenticate }, async (request, reply) => {
    const auth = request.auth!;
    const sessions = await app.prisma.session.findMany({
      where: { userId: auth.userId, revokedAt: null },
      orderBy: { lastSeenAt: 'desc' },
      take: 20,
      select: { id: true, ip: true, userAgent: true, createdAt: true, lastSeenAt: true, mfaSatisfied: true },
    });
    noStore(reply);
    return {
      sessions: sessions.map((s) => ({
        id: s.id,
        current: s.id === auth.sessionId,
        ip: s.ip,
        device: s.userAgent?.slice(0, 120) ?? null,
        signedInAt: s.createdAt,
        lastSeenAt: s.lastSeenAt,
        twoFactor: s.mfaSatisfied,
      })),
    };
  });

  app.post('/sessions/revoke-others', { preHandler: app.authenticate }, async (request, reply) => {
    const auth = request.auth!;
    const count = await service.revokeAllSessions(auth.userId, 'revoked by user', auth.sessionId);
    noStore(reply);
    return { revoked: count };
  });
}

function toMeta(request: { ip: string; headers: Record<string, unknown>; id: string }): RequestMeta {
  const ua = request.headers['user-agent'];
  return {
    ip: request.ip,
    userAgent: typeof ua === 'string' ? ua : null,
    requestId: request.id,
  };
}

// Kept for parity with other modules that build a token for a response.
export { randomToken };
