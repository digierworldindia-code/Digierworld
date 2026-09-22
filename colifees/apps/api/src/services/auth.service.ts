/**
 * Authentication flows.
 *
 * Design notes that matter for review:
 *
 *  - "no such account" and "wrong password" are indistinguishable to the
 *    caller, and take comparable time (an unknown email still pays for one
 *    Argon2 verification).
 *  - Lockout is per account *and* the failed-attempt table records the source
 *    address, so repeated failures across many accounts from one address are
 *    visible to whoever reads the security log.
 *  - Refresh tokens rotate on every use. Presenting a token that has already
 *    been rotated is treated as theft: the entire session family is revoked.
 *  - Changing a password, resetting one, or disabling MFA revokes every other
 *    session for that user.
 */
import { withStaffContext, type PrismaClient } from '@colifees/database';
import { getConfig } from '@colifees/config';
import {
  hashPassword,
  verifyPassword,
  needsRehash,
  dummyVerify,
  checkPasswordPolicy,
  randomToken,
  sha256Hex,
  signAccessToken,
  generateTotpSecret,
  verifyTotp,
  generateRecoveryCodes,
  buildOtpAuthUrl,
  encryptField,
  decryptField,
  permissionsForRoles,
  safeEqual,
} from '@colifees/auth';
import { errors } from '../lib/errors.js';
import { securityLog } from '../lib/logger.js';
import { recordAudit } from './audit.js';

export interface RequestMeta {
  ip: string;
  userAgent: string | null;
  requestId: string;
}

export interface IssuedSession {
  accessToken: string;
  refreshToken: string;
  csrfToken: string;
  user: {
    id: string;
    email: string;
    fullName: string;
    roles: string[];
    permissions: string[];
    dealerId: string | null;
    mfaEnabled: boolean;
    mustChangePassword: boolean;
    mustEnrolMfa: boolean;
  };
}

const PASSWORD_RESET_TTL_MS = 30 * 60 * 1000;

export class AuthService {
  constructor(private readonly prisma: PrismaClient) {}

  // -------------------------------------------------------------------------
  // Sign in
  // -------------------------------------------------------------------------
  async login(
    input: { email: string; password: string; totpCode?: string | undefined; recoveryCode?: string | undefined },
    meta: RequestMeta,
  ): Promise<IssuedSession> {
    const config = getConfig();
    const ipHash = sha256Hex(`${meta.ip}:${config.env.SIGNING_SECRET}`);

    // Staff scope: the join reaches `dealer_users` and `dealers`, both Row
    // Level Security protected. See withStaffContext for why sign-in is one of
    // the few places that legitimately needs it.
    const user = await withStaffContext(this.prisma, (tx) =>
      tx.user.findUnique({
        where: { email: input.email },
        include: {
          roles: { include: { role: true } },
          dealerLink: { include: { dealer: { select: { id: true, status: true, deletedAt: true } } } },
        },
      }),
    );

    if (!user || user.deletedAt) {
      // Spend comparable time so a missing account is not detectable by timing.
      await dummyVerify();
      await this.noteFailure(input.email, ipHash, meta.userAgent, 'unknown_account');
      throw errors.invalidCredentials('no such user');
    }

    if (user.lockedUntil && user.lockedUntil.getTime() > Date.now()) {
      const retryAfter = Math.ceil((user.lockedUntil.getTime() - Date.now()) / 1000);
      await this.noteFailure(input.email, ipHash, meta.userAgent, 'locked');
      throw errors.accountLocked(retryAfter);
    }

    const passwordOk = await verifyPassword(user.passwordHash, input.password);
    if (!passwordOk) {
      await this.registerFailedAttempt(user.id, input.email, ipHash, meta.userAgent);
      throw errors.invalidCredentials('bad password');
    }

    if (user.status !== 'ACTIVE') {
      await this.noteFailure(input.email, ipHash, meta.userAgent, `status_${user.status}`);
      securityLog('LOGIN_BLOCKED_INACTIVE', { userId: user.id, status: user.status });
      // Same message as a wrong password: an attacker learns nothing about
      // whether the account exists or merely awaits activation.
      throw errors.invalidCredentials(`user status ${user.status}`);
    }

    const roles = user.roles.map((r) => r.role.key as string);

    // A dealer user is only as usable as their dealer.
    if (user.dealerLink && (user.dealerLink.dealer.status !== 'ACTIVE' || user.dealerLink.dealer.deletedAt)) {
      securityLog('LOGIN_BLOCKED_DEALER_INACTIVE', { userId: user.id, dealerId: user.dealerLink.dealerId });
      throw errors.forbidden('dealer not active', 'This dealership account is not active. Please contact COLIFEES.');
    }

    // --- second factor ------------------------------------------------------
    let mfaSatisfied = false;
    if (user.mfaEnabled) {
      mfaSatisfied = await this.verifySecondFactor(user, input.totpCode, input.recoveryCode);
      if (!mfaSatisfied) {
        await this.noteFailure(input.email, ipHash, meta.userAgent, 'mfa_failed');
        throw input.totpCode || input.recoveryCode ? errors.mfaInvalid() : errors.mfaRequired();
      }
    }

    const mustEnrolMfa = !user.mfaEnabled && roles.some((r) => config.env.MFA_REQUIRED_ROLES.includes(r));

    const issued = await this.issueSession(user.id, roles, user.dealerLink?.dealerId ?? null, mfaSatisfied, meta);

    await this.prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await tx.user.update({
        where: { id: user.id },
        data: {
          failedLoginCount: 0,
          lockedUntil: null,
          lastLoginAt: new Date(),
          lastLoginIp: meta.ip.slice(0, 64),
          // Transparently upgrade a hash produced under weaker parameters.
          ...(needsRehash(user.passwordHash) ? { passwordHash: await hashPassword(input.password) } : {}),
        },
      });
      await recordAudit(
        tx,
        {
          userId: user.id,
          userEmail: user.email,
          roleKey: roles[0] ?? null,
          dealerId: user.dealerLink?.dealerId ?? null,
          ip: meta.ip,
          userAgent: meta.userAgent,
          requestId: meta.requestId,
        },
        { action: 'LOGIN', entity: 'user', entityId: user.id, newValue: { mfaSatisfied } },
      );
    });

    securityLog('LOGIN_SUCCESS', { userId: user.id, roles, mfaSatisfied }, 'info');

    return {
      ...issued,
      user: {
        id: user.id,
        email: user.email,
        fullName: user.fullName,
        roles,
        permissions: permissionsForRoles(roles) as string[],
        dealerId: user.dealerLink?.dealerId ?? null,
        mfaEnabled: user.mfaEnabled,
        mustChangePassword: user.mustChangePassword,
        mustEnrolMfa,
      },
    };
  }

  private async verifySecondFactor(
    user: { id: string; mfaSecretEncrypted: string | null; mfaRecoveryCodes: unknown },
    totpCode: string | undefined,
    recoveryCode: string | undefined,
  ): Promise<boolean> {
    const config = getConfig();

    if (totpCode && user.mfaSecretEncrypted) {
      const secret = decryptField(user.mfaSecretEncrypted, config.encryptionKey);
      return verifyTotp(secret, totpCode).valid;
    }

    if (recoveryCode) {
      const stored = Array.isArray(user.mfaRecoveryCodes) ? (user.mfaRecoveryCodes as string[]) : [];
      const offered = sha256Hex(recoveryCode.trim().toUpperCase());
      const match = stored.find((hash) => safeEqual(hash, offered));
      if (!match) return false;
      // Recovery codes are single use.
      await this.prisma.user.update({
        where: { id: user.id },
        data: { mfaRecoveryCodes: stored.filter((h) => h !== match) },
      });
      securityLog('MFA_RECOVERY_CODE_USED', { userId: user.id });
      return true;
    }

    return false;
  }

  private async registerFailedAttempt(
    userId: string,
    email: string,
    ipHash: string,
    userAgent: string | null,
  ): Promise<void> {
    const config = getConfig();
    const updated = await this.prisma.user.update({
      where: { id: userId },
      data: { failedLoginCount: { increment: 1 } },
      select: { failedLoginCount: true },
    });

    if (updated.failedLoginCount >= config.env.LOGIN_MAX_ATTEMPTS) {
      const lockedUntil = new Date(Date.now() + config.env.LOGIN_LOCKOUT_SECONDS * 1000);
      await this.prisma.user.update({
        where: { id: userId },
        data: { lockedUntil, failedLoginCount: 0 },
      });
      securityLog('ACCOUNT_LOCKED', { userId, attempts: updated.failedLoginCount, lockedUntil });
    }

    await this.noteFailure(email, ipHash, userAgent, 'bad_password');
  }

  private async noteFailure(
    email: string,
    ipHash: string,
    userAgent: string | null,
    reason: string,
  ): Promise<void> {
    await this.prisma.failedLoginAttempt.create({
      data: { email: email.slice(0, 255), ipHash, userAgent: userAgent?.slice(0, 400) ?? null, reason },
    });
    securityLog('LOGIN_FAILED', { email, reason });
  }

  // -------------------------------------------------------------------------
  // Session issue and rotation
  // -------------------------------------------------------------------------
  private async issueSession(
    userId: string,
    roles: string[],
    dealerId: string | null,
    mfaSatisfied: boolean,
    meta: RequestMeta,
  ): Promise<{ accessToken: string; refreshToken: string; csrfToken: string }> {
    const config = getConfig();
    const sessionToken = randomToken(32);
    const refreshToken = randomToken(32);
    const csrfToken = randomToken(24);

    const session = await this.prisma.session.create({
      data: {
        userId,
        tokenHash: sha256Hex(sessionToken),
        ip: meta.ip.slice(0, 64),
        userAgent: meta.userAgent?.slice(0, 400) ?? null,
        mfaSatisfied,
        absoluteExpiry: new Date(Date.now() + config.env.REFRESH_TOKEN_TTL_SECONDS * 1000),
      },
    });

    await this.prisma.refreshToken.create({
      data: {
        sessionId: session.id,
        tokenHash: sha256Hex(refreshToken),
        expiresAt: new Date(Date.now() + config.env.REFRESH_TOKEN_TTL_SECONDS * 1000),
      },
    });

    const accessToken = await signAccessToken(
      {
        sub: userId,
        sid: session.id,
        roles,
        perms: permissionsForRoles(roles) as string[],
        dealerId,
        mfa: mfaSatisfied,
      },
      config.env.AUTH_SECRET,
      config.env.ACCESS_TOKEN_TTL_SECONDS,
    );

    return { accessToken, refreshToken, csrfToken };
  }

  /**
   * Exchanges a refresh token for a new pair. Reuse of an already-rotated token
   * means either a replay or a stolen cookie; both are handled the same way, by
   * revoking every token in the family and ending the session.
   */
  async refresh(presentedToken: string, meta: RequestMeta): Promise<{ accessToken: string; csrfToken: string; refreshToken: string }> {
    const config = getConfig();
    const tokenHash = sha256Hex(presentedToken);

    const stored = await withStaffContext(this.prisma, (tx) =>
      tx.refreshToken.findUnique({
        where: { tokenHash },
        include: {
          session: {
            include: {
              user: {
                include: {
                  roles: { include: { role: true } },
                  dealerLink: true,
                },
              },
            },
          },
        },
      }),
    );

    if (!stored) throw errors.sessionExpired();

    if (stored.usedAt || stored.revokedAt) {
      await this.revokeSession(stored.sessionId, 'refresh token reuse detected');
      securityLog('REFRESH_TOKEN_REUSE', {
        sessionId: stored.sessionId,
        userId: stored.session.userId,
        ip: meta.ip,
      }, 'error');
      throw errors.sessionExpired();
    }

    if (stored.expiresAt.getTime() <= Date.now()) throw errors.sessionExpired();

    const session = stored.session;
    if (session.revokedAt || session.absoluteExpiry.getTime() <= Date.now()) throw errors.sessionExpired();

    const user = session.user;
    if (user.deletedAt || user.status !== 'ACTIVE') throw errors.sessionExpired();

    const roles = user.roles.map((r) => r.role.key as string);
    const newRefresh = randomToken(32);
    const csrfToken = randomToken(24);

    await this.prisma.$transaction(async (tx) => {
      const replacement = await tx.refreshToken.create({
        data: {
          sessionId: session.id,
          tokenHash: sha256Hex(newRefresh),
          expiresAt: new Date(Date.now() + config.env.REFRESH_TOKEN_TTL_SECONDS * 1000),
        },
      });
      await tx.refreshToken.update({
        where: { id: stored.id },
        data: { usedAt: new Date(), replacedById: replacement.id },
      });
      await tx.session.update({ where: { id: session.id }, data: { lastSeenAt: new Date() } });
    });

    const accessToken = await signAccessToken(
      {
        sub: user.id,
        sid: session.id,
        roles,
        perms: permissionsForRoles(roles) as string[],
        dealerId: user.dealerLink?.dealerId ?? null,
        mfa: session.mfaSatisfied,
      },
      config.env.AUTH_SECRET,
      config.env.ACCESS_TOKEN_TTL_SECONDS,
    );

    return { accessToken, refreshToken: newRefresh, csrfToken };
  }

  async logout(sessionId: string, meta: RequestMeta, actor: { userId: string; email: string }): Promise<void> {
    await this.revokeSession(sessionId, 'user signed out');
    await this.prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await recordAudit(
        tx,
        { userId: actor.userId, userEmail: actor.email, ip: meta.ip, userAgent: meta.userAgent, requestId: meta.requestId },
        { action: 'LOGOUT', entity: 'session', entityId: sessionId },
      );
    });
  }

  async revokeSession(sessionId: string, reason: string): Promise<void> {
    await this.prisma.$transaction([
      this.prisma.session.updateMany({
        where: { id: sessionId, revokedAt: null },
        data: { revokedAt: new Date(), revokedReason: reason.slice(0, 120) },
      }),
      this.prisma.refreshToken.updateMany({
        where: { sessionId, revokedAt: null },
        data: { revokedAt: new Date() },
      }),
    ]);
  }

  async revokeAllSessions(userId: string, reason: string, exceptSessionId?: string): Promise<number> {
    const sessions = await this.prisma.session.findMany({
      where: { userId, revokedAt: null, ...(exceptSessionId ? { id: { not: exceptSessionId } } : {}) },
      select: { id: true },
    });
    for (const session of sessions) {
      await this.revokeSession(session.id, reason);
    }
    return sessions.length;
  }

  // -------------------------------------------------------------------------
  // Password management
  // -------------------------------------------------------------------------
  async changePassword(
    userId: string,
    currentPassword: string,
    newPassword: string,
    sessionId: string,
    meta: RequestMeta,
  ): Promise<void> {
    const user = await this.prisma.user.findUniqueOrThrow({ where: { id: userId } });

    if (!(await verifyPassword(user.passwordHash, currentPassword))) {
      securityLog('PASSWORD_CHANGE_REJECTED', { userId, reason: 'current password wrong' });
      throw errors.invalidCredentials('current password did not match');
    }
    if (await verifyPassword(user.passwordHash, newPassword)) {
      throw errors.businessRule('Your new password must be different from your current one.');
    }

    const policy = checkPasswordPolicy(newPassword, [user.email, user.fullName, 'colifees']);
    if (!policy.ok) throw errors.validation({ newPassword: policy.problems });

    await this.prisma.$transaction(async (tx) => {
      await tx.user.update({
        where: { id: userId },
        data: {
          passwordHash: await hashPassword(newPassword),
          passwordChangedAt: new Date(),
          mustChangePassword: false,
        },
      });
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await recordAudit(
        tx,
        { userId, userEmail: user.email, ip: meta.ip, userAgent: meta.userAgent, requestId: meta.requestId },
        { action: 'PASSWORD_CHANGED', entity: 'user', entityId: userId },
      );
    });

    // Every other device is signed out: if the old password was compromised,
    // the attacker's session dies with the change.
    const revoked = await this.revokeAllSessions(userId, 'password changed', sessionId);
    securityLog('PASSWORD_CHANGED', { userId, otherSessionsRevoked: revoked }, 'info');
  }

  /**
   * Always reports success. Whether the address is registered is not something
   * an anonymous caller is entitled to learn.
   */
  async requestPasswordReset(email: string, meta: RequestMeta): Promise<string | null> {
    const user = await this.prisma.user.findUnique({ where: { email } });
    if (!user || user.deletedAt || user.status === 'DISABLED') {
      securityLog('PASSWORD_RESET_REQUESTED_UNKNOWN', { email }, 'info');
      return null;
    }

    // Outstanding tokens are invalidated so only the newest link works.
    await this.prisma.passwordResetToken.updateMany({
      where: { userId: user.id, usedAt: null },
      data: { usedAt: new Date() },
    });

    const token = randomToken(32);
    await this.prisma.passwordResetToken.create({
      data: {
        userId: user.id,
        tokenHash: sha256Hex(token),
        expiresAt: new Date(Date.now() + PASSWORD_RESET_TTL_MS),
        createdIp: meta.ip.slice(0, 64),
      },
    });

    securityLog('PASSWORD_RESET_REQUESTED', { userId: user.id }, 'info');
    return token;
  }

  async resetPassword(token: string, newPassword: string, meta: RequestMeta): Promise<void> {
    const record = await this.prisma.passwordResetToken.findUnique({
      where: { tokenHash: sha256Hex(token) },
      include: { user: true },
    });

    if (!record || record.usedAt || record.expiresAt.getTime() <= Date.now()) {
      securityLog('PASSWORD_RESET_INVALID_TOKEN', { present: Boolean(record) });
      throw errors.businessRule('That reset link is no longer valid. Request a new one.');
    }

    const policy = checkPasswordPolicy(newPassword, [record.user.email, record.user.fullName, 'colifees']);
    if (!policy.ok) throw errors.validation({ newPassword: policy.problems });

    await this.prisma.$transaction(async (tx) => {
      await tx.user.update({
        where: { id: record.userId },
        data: {
          passwordHash: await hashPassword(newPassword),
          passwordChangedAt: new Date(),
          mustChangePassword: false,
          failedLoginCount: 0,
          lockedUntil: null,
        },
      });
      // Single use.
      await tx.passwordResetToken.update({ where: { id: record.id }, data: { usedAt: new Date() } });
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await recordAudit(
        tx,
        { userId: record.userId, userEmail: record.user.email, ip: meta.ip, userAgent: meta.userAgent, requestId: meta.requestId },
        { action: 'PASSWORD_RESET', entity: 'user', entityId: record.userId },
      );
    });

    const revoked = await this.revokeAllSessions(record.userId, 'password reset');
    securityLog('PASSWORD_RESET_COMPLETED', { userId: record.userId, sessionsRevoked: revoked }, 'info');
  }

  // -------------------------------------------------------------------------
  // Two-factor authentication
  // -------------------------------------------------------------------------
  async startMfaEnrolment(userId: string, password: string): Promise<{ secret: string; otpauthUrl: string }> {
    const config = getConfig();
    const user = await this.prisma.user.findUniqueOrThrow({ where: { id: userId } });
    if (!(await verifyPassword(user.passwordHash, password))) {
      throw errors.invalidCredentials('password check failed before MFA enrolment');
    }

    const secret = generateTotpSecret();
    // Stored encrypted and not yet enabled: an interrupted enrolment cannot
    // lock anyone out.
    await this.prisma.user.update({
      where: { id: userId },
      data: { mfaSecretEncrypted: encryptField(secret, config.encryptionKey), mfaEnabled: false },
    });

    return { secret, otpauthUrl: buildOtpAuthUrl({ secret, accountName: user.email }) };
  }

  async confirmMfaEnrolment(userId: string, totpCode: string, meta: RequestMeta): Promise<{ recoveryCodes: string[] }> {
    const config = getConfig();
    const user = await this.prisma.user.findUniqueOrThrow({ where: { id: userId } });
    if (!user.mfaSecretEncrypted) throw errors.businessRule('Start two-factor setup before confirming it.');

    const secret = decryptField(user.mfaSecretEncrypted, config.encryptionKey);
    if (!verifyTotp(secret, totpCode).valid) throw errors.mfaInvalid();

    const recoveryCodes = generateRecoveryCodes();
    await this.prisma.$transaction(async (tx) => {
      await tx.user.update({
        where: { id: userId },
        data: {
          mfaEnabled: true,
          mfaEnrolledAt: new Date(),
          // Only hashes are kept; the plaintext list is shown once and never again.
          mfaRecoveryCodes: recoveryCodes.map((c) => sha256Hex(c)),
        },
      });
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await recordAudit(
        tx,
        { userId, userEmail: user.email, ip: meta.ip, userAgent: meta.userAgent, requestId: meta.requestId },
        { action: 'MFA_ENABLED', entity: 'user', entityId: userId },
      );
    });

    securityLog('MFA_ENABLED', { userId }, 'info');
    return { recoveryCodes };
  }

  async disableMfa(userId: string, password: string, totpCode: string, meta: RequestMeta): Promise<void> {
    const config = getConfig();
    const user = await this.prisma.user.findUniqueOrThrow({ where: { id: userId } });
    if (!(await verifyPassword(user.passwordHash, password))) throw errors.invalidCredentials('password check failed');
    if (!user.mfaSecretEncrypted) throw errors.businessRule('Two-factor authentication is not enabled.');
    if (!verifyTotp(decryptField(user.mfaSecretEncrypted, config.encryptionKey), totpCode).valid) {
      throw errors.mfaInvalid();
    }

    await this.prisma.$transaction(async (tx) => {
      await tx.user.update({
        where: { id: userId },
        data: { mfaEnabled: false, mfaSecretEncrypted: null, mfaRecoveryCodes: undefined, mfaEnrolledAt: null },
      });
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await recordAudit(
        tx,
        { userId, userEmail: user.email, ip: meta.ip, userAgent: meta.userAgent, requestId: meta.requestId },
        { action: 'MFA_DISABLED', entity: 'user', entityId: userId },
      );
    });

    await this.revokeAllSessions(userId, 'two-factor authentication disabled');
    securityLog('MFA_DISABLED', { userId });
  }
}
