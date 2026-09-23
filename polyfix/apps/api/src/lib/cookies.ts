/**
 * Cookie contract.
 *
 * Three cookies, each with the narrowest settings that still let the console
 * work:
 *
 *   clf_at    access token   HttpOnly, path=/          — unreadable from JS
 *   clf_rt    refresh token  HttpOnly, path=/auth      — only sent to /auth/*
 *   clf_csrf  CSRF token     readable, path=/          — deliberately not HttpOnly
 *
 * The CSRF cookie is the one value the browser is *meant* to read: the console
 * copies it into an `x-csrf-token` header, and the server checks the two match.
 * It carries no authority on its own, so exposing it to script costs nothing.
 */
import type { FastifyReply } from 'fastify';
import { getConfig } from '@polyfix/config';

export const ACCESS_TOKEN_COOKIE = 'clf_at';
export const REFRESH_TOKEN_COOKIE = 'clf_rt';
export const CSRF_COOKIE = 'clf_csrf';
export const CSRF_HEADER = 'x-csrf-token';

/** Refresh tokens are scoped to the auth routes that actually need them. */
export const REFRESH_COOKIE_PATH = '/auth';

function baseOptions() {
  const config = getConfig();
  return {
    httpOnly: true,
    secure: config.cookie.secure,
    sameSite: config.cookie.sameSite,
    ...(config.cookie.domain ? { domain: config.cookie.domain } : {}),
  } as const;
}

export function setAuthCookies(
  reply: FastifyReply,
  tokens: { accessToken: string; refreshToken: string; csrfToken: string },
): void {
  const config = getConfig();
  const base = baseOptions();

  reply.setCookie(ACCESS_TOKEN_COOKIE, tokens.accessToken, {
    ...base,
    path: '/',
    maxAge: config.env.ACCESS_TOKEN_TTL_SECONDS,
  });

  reply.setCookie(REFRESH_TOKEN_COOKIE, tokens.refreshToken, {
    ...base,
    path: REFRESH_COOKIE_PATH,
    maxAge: config.env.REFRESH_TOKEN_TTL_SECONDS,
  });

  reply.setCookie(CSRF_COOKIE, tokens.csrfToken, {
    ...base,
    httpOnly: false,
    path: '/',
    maxAge: config.env.REFRESH_TOKEN_TTL_SECONDS,
  });
}

export function clearAuthCookies(reply: FastifyReply): void {
  const base = baseOptions();
  reply.clearCookie(ACCESS_TOKEN_COOKIE, { ...base, path: '/' });
  reply.clearCookie(REFRESH_TOKEN_COOKIE, { ...base, path: REFRESH_COOKIE_PATH });
  reply.clearCookie(CSRF_COOKIE, { ...base, httpOnly: false, path: '/' });
}
