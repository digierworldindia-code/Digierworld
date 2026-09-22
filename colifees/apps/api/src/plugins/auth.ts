/**
 * Authentication and authorization.
 *
 * Every protected route goes through `authenticate`, which does four things in
 * order and gives up at the first failure:
 *
 *   1. verifies the signed access token from the HttpOnly cookie
 *   2. loads the session row and checks it is neither revoked nor timed out
 *   3. rebuilds roles, permissions and dealer scope *from the database*, so a
 *      role change or a dealer unlink takes effect on the next request rather
 *      than when the token happens to expire
 *   4. records the session as seen, for idle-timeout accounting
 *
 * Step 3 is the reason the token's own claims are treated as a hint rather than
 * as authority.
 */
import type { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import fp from 'fastify-plugin';
import { getConfig } from '@colifees/config';
import { verifyAccessToken, permissionsForRoles, MFA_GATED_PERMISSIONS, type PermissionKey } from '@colifees/auth';
import { withStaffContext } from '@colifees/database';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';
import { ACCESS_TOKEN_COOKIE } from '../lib/cookies.js';

export interface AuthContext {
  userId: string;
  email: string;
  sessionId: string;
  roles: string[];
  permissions: string[];
  /** Non-null only for dealer-bound users. This is the RLS scope key. */
  dealerId: string | null;
  mfaEnabled: boolean;
  mfaSatisfied: boolean;
  mustChangePassword: boolean;
}

declare module 'fastify' {
  interface FastifyRequest {
    auth?: AuthContext;
  }
  interface FastifyInstance {
    authenticate: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    requirePermission: (
      ...permissions: PermissionKey[]
    ) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    requireAnyPermission: (
      ...permissions: PermissionKey[]
    ) => (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
    requireStaff: (request: FastifyRequest, reply: FastifyReply) => Promise<void>;
  }
}

async function authPlugin(app: FastifyInstance): Promise<void> {
  const config = getConfig();

  app.decorate('authenticate', async function authenticate(request: FastifyRequest): Promise<void> {
    const token = request.cookies[ACCESS_TOKEN_COOKIE];
    if (!token) throw errors.unauthenticated('no access token cookie');

    const claims = await verifyAccessToken(token, config.env.AUTH_SECRET);
    if (!claims) throw errors.unauthenticated('access token failed verification');

    // The session row is the authority on whether this token is still usable.
    //
    // Read under staff scope: this query joins `dealer_users`, which is one of
    // the Row Level Security protected tables. Authentication is the step that
    // *determines* the caller's scope, so it cannot already be operating inside
    // it — read it unscoped and the dealer link comes back empty, which would
    // quietly downgrade every dealer to "no dealer" and break their portal.
    const session = await withStaffContext(app.prisma, (tx) =>
      tx.session.findUnique({
      where: { id: claims.sid },
      select: {
        id: true,
        userId: true,
        revokedAt: true,
        absoluteExpiry: true,
        lastSeenAt: true,
        mfaSatisfied: true,
        user: {
          select: {
            id: true,
            email: true,
            status: true,
            deletedAt: true,
            mfaEnabled: true,
            mustChangePassword: true,
            roles: { select: { role: { select: { key: true } } } },
            dealerLink: { select: { dealerId: true } },
          },
        },
      },
      }),
    );

    if (!session || session.revokedAt) {
      securityLog('SESSION_REJECTED', { sessionId: claims.sid, reason: 'revoked or missing' });
      throw errors.sessionExpired();
    }
    if (session.absoluteExpiry.getTime() <= Date.now()) {
      throw errors.sessionExpired();
    }

    const idleLimitMs = config.env.SESSION_IDLE_TIMEOUT_SECONDS * 1000;
    if (Date.now() - session.lastSeenAt.getTime() > idleLimitMs) {
      await app.prisma.session.update({
        where: { id: session.id },
        data: { revokedAt: new Date(), revokedReason: 'idle timeout' },
      });
      securityLog('SESSION_IDLE_TIMEOUT', { sessionId: session.id, userId: session.userId }, 'info');
      throw errors.sessionExpired();
    }

    const user = session.user;
    if (!user || user.deletedAt || user.status !== 'ACTIVE') {
      securityLog('SESSION_REJECTED', { sessionId: session.id, reason: 'user not active' });
      throw errors.sessionExpired();
    }

    const roles = user.roles.map((r) => r.role.key as string);
    const permissions = permissionsForRoles(roles) as string[];

    request.auth = {
      userId: user.id,
      email: user.email,
      sessionId: session.id,
      roles,
      permissions,
      // Read from the database, not from the token: unlinking a dealer user
      // takes effect immediately.
      dealerId: user.dealerLink?.dealerId ?? null,
      mfaEnabled: user.mfaEnabled,
      mfaSatisfied: session.mfaSatisfied,
      mustChangePassword: user.mustChangePassword,
    };

    // Throttled so an active session does not write on every single request.
    if (Date.now() - session.lastSeenAt.getTime() > 60_000) {
      await app.prisma.session.update({ where: { id: session.id }, data: { lastSeenAt: new Date() } });
    }
  });

  /**
   * Requires every listed permission. MFA-gated permissions additionally
   * require that this session actually completed a second factor.
   */
  app.decorate('requirePermission', function requirePermission(...required: PermissionKey[]) {
    return async function check(request: FastifyRequest, reply: FastifyReply): Promise<void> {
      if (!request.auth) await app.authenticate(request, reply);
      const auth = request.auth;
      if (!auth) throw errors.unauthenticated('authenticate did not populate a context');

      // A user who must change their password can do exactly that and nothing else.
      if (auth.mustChangePassword && !request.url.startsWith('/auth/')) {
        throw errors.passwordChangeRequired();
      }

      const missing = required.filter((p) => !auth.permissions.includes(p));
      if (missing.length > 0) {
        securityLog('AUTHORIZATION_DENIED', {
          userId: auth.userId,
          path: request.url,
          method: request.method,
          missing,
        });
        throw errors.forbidden(`missing permissions: ${missing.join(', ')}`);
      }

      const needsMfa = required.filter((p) => MFA_GATED_PERMISSIONS.includes(p));
      if (needsMfa.length > 0 && !(auth.mfaEnabled && auth.mfaSatisfied)) {
        securityLog('MFA_GATE_BLOCKED', { userId: auth.userId, path: request.url, needsMfa });
        throw errors.forbidden(
          `${needsMfa.join(', ')} requires two-factor authentication`,
          'This action requires two-factor authentication. Enable it in your profile, then sign in again.',
        );
      }
    };
  });

  /**
   * Gate for the whole staff console.
   *
   * Permissions alone are not sufficient here. Some permissions are shared
   * between staff and dealers by design — a dealer legitimately holds
   * `dashboard:view` and `mattress:read` for their own portal — so a staff
   * route guarded only by such a permission would admit a dealer. Row Level
   * Security would still confine what they saw, but they have no business on a
   * staff endpoint at all, and the response shape assumes an unrestricted view.
   *
   * A user bound to a dealer is therefore refused across the entire /ops tree,
   * regardless of what else they hold.
   */
  app.decorate('requireStaff', async function requireStaff(request: FastifyRequest, reply: FastifyReply): Promise<void> {
    if (!request.auth) await app.authenticate(request, reply);
    const auth = request.auth;
    if (!auth) throw errors.unauthenticated('authenticate did not populate a context');

    if (auth.dealerId !== null) {
      securityLog('DEALER_ACCESSED_STAFF_ROUTE', {
        userId: auth.userId,
        dealerId: auth.dealerId,
        path: request.url,
        method: request.method,
      });
      throw errors.forbidden('dealer-bound user on a staff route', 'This area is not available to dealer accounts.');
    }
  });

  /** Requires at least one of the listed permissions. */
  app.decorate('requireAnyPermission', function requireAnyPermission(...accepted: PermissionKey[]) {
    return async function check(request: FastifyRequest, reply: FastifyReply): Promise<void> {
      if (!request.auth) await app.authenticate(request, reply);
      const auth = request.auth;
      if (!auth) throw errors.unauthenticated('authenticate did not populate a context');
      if (auth.mustChangePassword && !request.url.startsWith('/auth/')) {
        throw errors.passwordChangeRequired();
      }
      if (!accepted.some((p) => auth.permissions.includes(p))) {
        securityLog('AUTHORIZATION_DENIED', {
          userId: auth.userId,
          path: request.url,
          method: request.method,
          accepted,
        });
        throw errors.forbidden(`none of: ${accepted.join(', ')}`);
      }
    };
  });
}

export default fp(authPlugin, { name: 'colifees-auth' });
