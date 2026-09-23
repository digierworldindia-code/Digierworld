/**
 * Structured logging.
 *
 * Redaction is configured centrally and aggressively. A log line is the easiest
 * place to leak a credential, so the rule here is: if a field name looks like
 * it could carry a secret or personal data, it is removed before serialisation
 * rather than trusted to the caller to omit.
 */
import { pino, type Logger } from 'pino';
import { getConfig } from '@polyfix/config';

const REDACT_PATHS = [
  'req.headers.authorization',
  'req.headers.cookie',
  'req.headers["x-csrf-token"]',
  'req.headers["x-internal-token"]',
  'res.headers["set-cookie"]',
  'password',
  'newPassword',
  'currentPassword',
  '*.password',
  '*.newPassword',
  '*.currentPassword',
  'passwordHash',
  '*.passwordHash',
  'token',
  '*.token',
  'refreshToken',
  '*.refreshToken',
  'accessToken',
  '*.accessToken',
  'totpCode',
  '*.totpCode',
  'recoveryCode',
  '*.recoveryCode',
  'mfaSecret',
  'mfaSecretEncrypted',
  '*.mfaSecretEncrypted',
  'secret',
  '*.secret',
  'DATABASE_URL',
  'AUTH_SECRET',
  'ENCRYPTION_KEY',
  'SIGNING_SECRET',
  'phoneEncrypted',
  '*.phoneEncrypted',
  'emailEncrypted',
  '*.emailEncrypted',
];

let instance: Logger | undefined;

export function getLogger(): Logger {
  if (instance) return instance;
  const config = getConfig();

  instance = pino({
    level: config.env.LOG_LEVEL,
    redact: { paths: REDACT_PATHS, censor: '[redacted]' },
    base: { service: 'polyfix-api', env: config.env.APP_ENV },
    timestamp: pino.stdTimeFunctions.isoTime,
    formatters: {
      level: (label) => ({ level: label }),
    },
    // Development gets readable output; production emits newline-delimited JSON
    // for a log shipper, with no pretty-printing dependency in the runtime path.
    ...(config.isDevelopment
      ? {
          transport: {
            target: 'pino/file',
            options: { destination: 1 },
          },
        }
      : {}),
  });

  return instance;
}

/**
 * A dedicated channel for events a security reviewer will look for:
 * authentication outcomes, authorization denials, rate limiting, privilege
 * changes and anything touching audit or backup tooling.
 */
export function securityLog(
  event: string,
  fields: Record<string, unknown>,
  level: 'info' | 'warn' | 'error' = 'warn',
): void {
  getLogger()[level]({ securityEvent: event, ...fields }, `security.${event}`);
}
