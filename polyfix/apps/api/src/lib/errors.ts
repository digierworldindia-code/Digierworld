/**
 * Error taxonomy.
 *
 * Two audiences, two levels of detail:
 *   - the caller gets a short, friendly sentence and a stable machine code
 *   - the log gets the cause, the stack and the request id
 *
 * Nothing a caller receives ever contains a stack trace, a SQL fragment, a
 * Prisma error, a file path or an internal identifier they did not already
 * supply. That rule is enforced in one place: the Fastify error handler reads
 * `publicMessage`, and anything that is not an AppError becomes a generic
 * "Something went wrong" with a request id to quote to support.
 */

export type ErrorCode =
  | 'VALIDATION_FAILED'
  | 'UNAUTHENTICATED'
  | 'INVALID_CREDENTIALS'
  | 'ACCOUNT_LOCKED'
  | 'MFA_REQUIRED'
  | 'MFA_INVALID'
  | 'PASSWORD_CHANGE_REQUIRED'
  | 'SESSION_EXPIRED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'CONFLICT'
  | 'BUSINESS_RULE'
  | 'RATE_LIMITED'
  | 'PAYLOAD_TOO_LARGE'
  | 'UNSUPPORTED_MEDIA'
  | 'INTERNAL';

export interface AppErrorOptions {
  code: ErrorCode;
  statusCode: number;
  publicMessage: string;
  /** Field-level detail. Safe to return; never contains system internals. */
  details?: Record<string, string[]> | undefined;
  /** Written to the log only. */
  internal?: string | undefined;
  cause?: unknown;
  /** Set for events the security log should highlight. */
  securityEvent?: string | undefined;
}

export class AppError extends Error {
  readonly code: ErrorCode;
  readonly statusCode: number;
  readonly publicMessage: string;
  readonly details: Record<string, string[]> | undefined;
  readonly internal: string | undefined;
  readonly securityEvent: string | undefined;

  constructor(options: AppErrorOptions) {
    super(options.internal ?? options.publicMessage, { cause: options.cause });
    this.name = 'AppError';
    this.code = options.code;
    this.statusCode = options.statusCode;
    this.publicMessage = options.publicMessage;
    this.details = options.details;
    this.internal = options.internal;
    this.securityEvent = options.securityEvent;
  }
}

export const errors = {
  validation(details: Record<string, string[]>, publicMessage = 'Please check the highlighted fields and try again.') {
    return new AppError({ code: 'VALIDATION_FAILED', statusCode: 422, publicMessage, details });
  },

  unauthenticated(internal?: string) {
    return new AppError({
      code: 'UNAUTHENTICATED',
      statusCode: 401,
      publicMessage: 'Please sign in to continue.',
      internal,
    });
  },

  /**
   * Deliberately identical for "no such account" and "wrong password" so the
   * endpoint cannot be used to discover which email addresses are registered.
   */
  invalidCredentials(internal?: string) {
    return new AppError({
      code: 'INVALID_CREDENTIALS',
      statusCode: 401,
      publicMessage: 'That email address and password combination was not recognised.',
      internal,
      securityEvent: 'LOGIN_FAILED',
    });
  },

  accountLocked(retryAfterSeconds: number) {
    return new AppError({
      code: 'ACCOUNT_LOCKED',
      statusCode: 423,
      publicMessage: `Too many failed attempts. Try again in ${Math.ceil(retryAfterSeconds / 60)} minutes, or reset your password.`,
      securityEvent: 'ACCOUNT_LOCKED',
    });
  },

  mfaRequired() {
    return new AppError({
      code: 'MFA_REQUIRED',
      statusCode: 401,
      publicMessage: 'Enter the 6-digit code from your authenticator app.',
    });
  },

  mfaInvalid() {
    return new AppError({
      code: 'MFA_INVALID',
      statusCode: 401,
      publicMessage: 'That code was not valid. Codes expire every 30 seconds — try the current one.',
      securityEvent: 'MFA_FAILED',
    });
  },

  passwordChangeRequired() {
    return new AppError({
      code: 'PASSWORD_CHANGE_REQUIRED',
      statusCode: 403,
      publicMessage: 'You must set a new password before continuing.',
    });
  },

  sessionExpired() {
    return new AppError({
      code: 'SESSION_EXPIRED',
      statusCode: 401,
      publicMessage: 'Your session has ended. Please sign in again.',
    });
  },

  forbidden(internal?: string, publicMessage = 'You do not have permission to do that.') {
    return new AppError({
      code: 'FORBIDDEN',
      statusCode: 403,
      publicMessage,
      internal,
      securityEvent: 'AUTHORIZATION_DENIED',
    });
  },

  /**
   * Used for both "does not exist" and "exists but is not yours". Returning 404
   * rather than 403 for another dealer's record means an identifier cannot be
   * probed to confirm that it exists.
   */
  notFound(what = 'record', internal?: string) {
    return new AppError({
      code: 'NOT_FOUND',
      statusCode: 404,
      publicMessage: `That ${what} could not be found.`,
      internal,
    });
  },

  conflict(publicMessage: string, internal?: string) {
    return new AppError({ code: 'CONFLICT', statusCode: 409, publicMessage, internal });
  },

  businessRule(publicMessage: string, internal?: string) {
    return new AppError({ code: 'BUSINESS_RULE', statusCode: 422, publicMessage, internal });
  },

  rateLimited(retryAfterSeconds: number) {
    return new AppError({
      code: 'RATE_LIMITED',
      statusCode: 429,
      publicMessage: `Too many requests. Please wait ${retryAfterSeconds} seconds and try again.`,
      securityEvent: 'RATE_LIMITED',
    });
  },

  payloadTooLarge(publicMessage = 'That file is too large.') {
    return new AppError({ code: 'PAYLOAD_TOO_LARGE', statusCode: 413, publicMessage });
  },

  unsupportedMedia(publicMessage: string) {
    return new AppError({ code: 'UNSUPPORTED_MEDIA', statusCode: 415, publicMessage });
  },

  internal(internal: string, cause?: unknown) {
    return new AppError({
      code: 'INTERNAL',
      statusCode: 500,
      publicMessage: 'Something went wrong. Please try again, or contact your administrator if it keeps happening.',
      internal,
      cause,
    });
  },
};

export function isAppError(error: unknown): error is AppError {
  return error instanceof AppError;
}
