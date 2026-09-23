/**
 * Short-lived access tokens.
 *
 * The access token carries only what authorization needs: subject, session id,
 * role keys, permission keys and dealer scope. It never carries personal data,
 * and it is delivered to the browser exclusively in an HttpOnly cookie, so it
 * is not reachable from JavaScript.
 */
import { SignJWT, jwtVerify, type JWTPayload } from 'jose';

/**
 * Issuer and audience are protocol identifiers, not display copy, so they are
 * fixed strings rather than derived from the brand module — a future rename
 * must not silently invalidate tokens.
 *
 * DEPLOYMENT NOTE: these values changed with the POLYFIX rename. Access tokens
 * issued under the previous identifiers fail verification, so the first deploy
 * carrying this change signs every active session out once. Refresh tokens are
 * opaque and database-backed, so nothing is lost; people sign in again and
 * carry on. Expect a burst of sign-ins immediately after release.
 */
const ISSUER = 'polyfix.auth';
const AUDIENCE = 'polyfix.api';

export interface AccessTokenClaims {
  /** user id */
  sub: string;
  /** session id — lets a single session be revoked server-side */
  sid: string;
  roles: string[];
  perms: string[];
  /** dealer id when the user is bound to a dealer, otherwise null */
  dealerId: string | null;
  mfa: boolean;
}

export async function signAccessToken(
  claims: AccessTokenClaims,
  secret: string,
  ttlSeconds: number,
): Promise<string> {
  const key = new TextEncoder().encode(secret);
  const now = Math.floor(Date.now() / 1000);
  return new SignJWT({ ...claims } as unknown as JWTPayload)
    .setProtectedHeader({ alg: 'HS256', typ: 'JWT' })
    .setIssuer(ISSUER)
    .setAudience(AUDIENCE)
    .setSubject(claims.sub)
    .setIssuedAt(now)
    .setNotBefore(now)
    .setExpirationTime(now + ttlSeconds)
    .sign(key);
}

export async function verifyAccessToken(
  token: string,
  secret: string,
): Promise<AccessTokenClaims | null> {
  try {
    const key = new TextEncoder().encode(secret);
    const { payload } = await jwtVerify(token, key, {
      issuer: ISSUER,
      audience: AUDIENCE,
      algorithms: ['HS256'],
      clockTolerance: 5,
    });
    if (typeof payload.sub !== 'string' || typeof payload.sid !== 'string') return null;
    return {
      sub: payload.sub,
      sid: payload.sid,
      roles: Array.isArray(payload.roles) ? (payload.roles as string[]) : [],
      perms: Array.isArray(payload.perms) ? (payload.perms as string[]) : [],
      dealerId: typeof payload.dealerId === 'string' ? payload.dealerId : null,
      mfa: payload.mfa === true,
    };
  } catch {
    return null;
  }
}
