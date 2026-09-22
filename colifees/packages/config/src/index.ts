/**
 * Central, validated configuration.
 *
 * Every secret the platform uses enters the process here and nowhere else.
 * The module fails fast at boot when a required value is missing or weak, so a
 * misconfigured deployment never starts and silently falls back to a default.
 *
 * Nothing in this file is safe to import from browser code. The public website
 * and the console read only `NEXT_PUBLIC_*` values, which are declared
 * separately in each Next.js app.
 */
import { z } from 'zod';

const MIN_SECRET_LENGTH = 32;

const secret = (name: string) =>
  z
    .string({ required_error: `${name} is required` })
    .min(MIN_SECRET_LENGTH, `${name} must be at least ${MIN_SECRET_LENGTH} characters`)
    .refine((v) => !/^CHANGE_ME/i.test(v), `${name} still holds the placeholder value from .env.example`);

const bool = (defaultValue: boolean) =>
  z
    .enum(['true', 'false', '1', '0', ''])
    .optional()
    .transform((v) => (v === undefined || v === '' ? defaultValue : v === 'true' || v === '1'));

const int = (defaultValue: number, min = 0) =>
  z
    .string()
    .optional()
    .transform((v) => (v === undefined || v === '' ? defaultValue : Number(v)))
    .pipe(z.number().int().min(min));

const csv = z
  .string()
  .optional()
  .transform((v) =>
    (v ?? '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean),
  );

const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  APP_ENV: z.enum(['development', 'test', 'staging', 'production']).default('development'),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace']).default('info'),
  PORT: int(4000, 1),
  HOST: z.string().default('127.0.0.1'),

  PUBLIC_WEB_ORIGIN: z.string().url().default('http://localhost:3000'),
  PRIVATE_APP_ORIGIN: z.string().url().default('http://localhost:3001'),
  API_PUBLIC_URL: z.string().url().default('http://localhost:4000'),

  DATABASE_URL: z.string().min(1, 'DATABASE_URL is required'),
  DIRECT_DATABASE_URL: z.string().optional(),

  AUTH_SECRET: secret('AUTH_SECRET'),
  SIGNING_SECRET: secret('SIGNING_SECRET'),
  ENCRYPTION_KEY: z
    .string({ required_error: 'ENCRYPTION_KEY is required' })
    .refine((v) => {
      try {
        return Buffer.from(v, 'base64').length === 32;
      } catch {
        return false;
      }
    }, 'ENCRYPTION_KEY must be exactly 32 bytes, base64 encoded (openssl rand -base64 32)'),

  ACCESS_TOKEN_TTL_SECONDS: int(900, 60),
  REFRESH_TOKEN_TTL_SECONDS: int(2_592_000, 300),
  SESSION_IDLE_TIMEOUT_SECONDS: int(3_600, 300),
  COOKIE_DOMAIN: z.string().optional().transform((v) => (v && v.length > 0 ? v : undefined)),
  LOGIN_MAX_ATTEMPTS: int(5, 1),
  LOGIN_LOCKOUT_SECONDS: int(900, 30),
  MFA_REQUIRED_ROLES: csv,

  STORAGE_DRIVER: z.enum(['local', 's3']).default('local'),
  STORAGE_LOCAL_PATH: z.string().default('./storage'),
  STORAGE_ENDPOINT: z.string().optional(),
  STORAGE_REGION: z.string().optional(),
  STORAGE_BUCKET: z.string().optional(),
  STORAGE_ACCESS_KEY: z.string().optional(),
  STORAGE_SECRET_KEY: z.string().optional(),
  STORAGE_FORCE_PATH_STYLE: bool(false),
  MEDIA_SIGNED_URL_TTL_SECONDS: int(300, 30),
  MAX_UPLOAD_BYTES: int(8_388_608, 1_024),
  MAX_UPLOADS_PER_CLAIM: int(8, 1),

  SMTP_HOST: z.string().optional(),
  SMTP_PORT: int(587, 1),
  SMTP_SECURE: bool(false),
  SMTP_USER: z.string().optional(),
  SMTP_PASSWORD: z.string().optional(),
  MAIL_FROM: z.string().default('COLIFEES <no-reply@colifees.com>'),
  MAIL_SUPPORT_TO: z.string().optional(),

  WHATSAPP_API_URL: z.string().optional(),
  WHATSAPP_PHONE_NUMBER_ID: z.string().optional(),
  WHATSAPP_API_KEY: z.string().optional(),

  INTERNAL_API_TOKEN: z.string().optional(),

  RATE_LIMIT_GLOBAL_PER_MINUTE: int(300, 10),
  RATE_LIMIT_LOGIN_PER_MINUTE: int(5, 1),
  RATE_LIMIT_PUBLIC_FORM_PER_HOUR: int(5, 1),
  RATE_LIMIT_VERIFY_PER_MINUTE: int(20, 1),
  TRUST_PROXY: bool(false),

  BACKUP_DIR: z.string().default('./backups'),
});

export type AppEnv = z.infer<typeof envSchema>;

let cached: AppConfig | null = null;

export interface AppConfig {
  env: AppEnv;
  isProduction: boolean;
  isDevelopment: boolean;
  isTest: boolean;
  /** Origins allowed to send credentialed cross-site requests to the API. */
  allowedOrigins: string[];
  cookie: {
    domain: string | undefined;
    secure: boolean;
    sameSite: 'strict' | 'lax';
  };
  encryptionKey: Buffer;
}

function build(raw: NodeJS.ProcessEnv): AppConfig {
  const parsed = envSchema.safeParse(raw);
  if (!parsed.success) {
    const issues = parsed.error.issues
      .map((i) => `  - ${i.path.join('.') || '(root)'}: ${i.message}`)
      .join('\n');
    // Deliberately prints only variable NAMES and messages, never values.
    throw new Error(`Invalid environment configuration:\n${issues}`);
  }
  const env = parsed.data;
  const isProduction = env.APP_ENV === 'production';

  if (isProduction) {
    if (env.AUTH_SECRET === env.SIGNING_SECRET) {
      throw new Error('AUTH_SECRET and SIGNING_SECRET must be different values in production');
    }
    if (!/sslmode=(require|verify-ca|verify-full)/.test(env.DATABASE_URL)) {
      throw new Error(
        'Production DATABASE_URL must enable TLS (append ?sslmode=verify-full&sslrootcert=...)',
      );
    }
    if (env.STORAGE_DRIVER === 'local') {
      throw new Error('STORAGE_DRIVER=local is not permitted in production; use an S3-compatible private bucket');
    }
  }

  return {
    env,
    isProduction,
    isDevelopment: env.APP_ENV === 'development',
    isTest: env.APP_ENV === 'test',
    allowedOrigins: [env.PUBLIC_WEB_ORIGIN, env.PRIVATE_APP_ORIGIN],
    cookie: {
      domain: env.COOKIE_DOMAIN,
      secure: isProduction || env.APP_ENV === 'staging',
      sameSite: 'strict',
    },
    encryptionKey: Buffer.from(env.ENCRYPTION_KEY, 'base64'),
  };
}

export function getConfig(): AppConfig {
  if (!cached) cached = build(process.env);
  return cached;
}

/** Test helper — rebuilds configuration from an explicit environment map. */
export function buildConfigFrom(raw: NodeJS.ProcessEnv): AppConfig {
  return build(raw);
}

export function resetConfigCache(): void {
  cached = null;
}
