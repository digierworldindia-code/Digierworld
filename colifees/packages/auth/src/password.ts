/**
 * Password hashing — Argon2id.
 *
 * Parameters follow the OWASP Password Storage Cheat Sheet recommendation for
 * Argon2id (19 MiB memory, 2 iterations, parallelism 1). They are recorded in
 * the encoded hash itself, so raising them later re-hashes users transparently
 * on their next successful login via `needsRehash`.
 */
import { hash, verify } from '@node-rs/argon2';

/**
 * Argon2id. The value is written as a literal rather than imported from the
 * library's `Algorithm` enum, which is an ambient const enum and therefore
 * unavailable under isolatedModules. 0 = Argon2d, 1 = Argon2i, 2 = Argon2id.
 */
const ARGON2ID = 2;

const ARGON2_OPTIONS = {
  algorithm: ARGON2ID,
  memoryCost: 19_456, // KiB (19 MiB)
  timeCost: 2,
  parallelism: 1,
} as const;

/** Minimum policy enforced on every password the platform accepts. */
export const PASSWORD_POLICY = {
  minLength: 12,
  maxLength: 128,
  requireLower: true,
  requireUpper: true,
  requireDigit: true,
  requireSymbol: true,
} as const;

export interface PasswordCheckResult {
  ok: boolean;
  problems: string[];
}

/**
 * Server-side password strength check. The browser may show the same rules for
 * convenience, but this function is the only one whose verdict counts.
 */
export function checkPasswordPolicy(password: string, context: string[] = []): PasswordCheckResult {
  const problems: string[] = [];
  if (password.length < PASSWORD_POLICY.minLength) {
    problems.push(`must be at least ${PASSWORD_POLICY.minLength} characters`);
  }
  if (password.length > PASSWORD_POLICY.maxLength) {
    problems.push(`must be at most ${PASSWORD_POLICY.maxLength} characters`);
  }
  if (PASSWORD_POLICY.requireLower && !/[a-z]/.test(password)) problems.push('must contain a lowercase letter');
  if (PASSWORD_POLICY.requireUpper && !/[A-Z]/.test(password)) problems.push('must contain an uppercase letter');
  if (PASSWORD_POLICY.requireDigit && !/[0-9]/.test(password)) problems.push('must contain a digit');
  if (PASSWORD_POLICY.requireSymbol && !/[^A-Za-z0-9]/.test(password)) {
    problems.push('must contain a symbol');
  }
  const lowered = password.toLowerCase();
  for (const piece of context) {
    const token = piece?.toLowerCase().trim();
    if (token && token.length >= 4 && lowered.includes(token)) {
      problems.push('must not contain your name, email or company name');
      break;
    }
  }
  for (const banned of COMMON_PASSWORD_FRAGMENTS) {
    if (lowered.includes(banned)) {
      problems.push('must not contain a common or predictable word');
      break;
    }
  }
  return { ok: problems.length === 0, problems };
}

const COMMON_PASSWORD_FRAGMENTS = [
  'password',
  'qwerty',
  '12345678',
  'letmein',
  'admin123',
  'welcome1',
  'iloveyou',
  'colifees123',
  'mattress123',
];

export async function hashPassword(password: string): Promise<string> {
  return hash(password, ARGON2_OPTIONS);
}

/**
 * Constant-time verification. Returns false for malformed stored hashes rather
 * than throwing, so a corrupted row cannot be distinguished from a wrong
 * password by timing or error shape.
 */
export async function verifyPassword(storedHash: string, password: string): Promise<boolean> {
  try {
    return await verify(storedHash, password, ARGON2_OPTIONS);
  } catch {
    return false;
  }
}

/** True when a stored hash was produced with weaker parameters than current policy. */
export function needsRehash(storedHash: string): boolean {
  const m = /\$argon2id\$v=19\$m=(\d+),t=(\d+),p=(\d+)\$/.exec(storedHash);
  if (!m) return true;
  const [, memory, time, parallelism] = m;
  return (
    Number(memory) < ARGON2_OPTIONS.memoryCost ||
    Number(time) < ARGON2_OPTIONS.timeCost ||
    Number(parallelism) < ARGON2_OPTIONS.parallelism
  );
}

/**
 * Burns roughly the same CPU as a real verification. Called when an account
 * does not exist so that "unknown email" and "wrong password" take the same
 * time and cannot be told apart by an attacker enumerating accounts.
 */
export async function dummyVerify(): Promise<void> {
  await verify(DUMMY_HASH, 'not-the-password-8f2a', ARGON2_OPTIONS).catch(() => false);
}

const DUMMY_HASH =
  '$argon2id$v=19$m=19456,t=2,p=1$c29tZS1maXhlZC1zYWx0MTI$Q5tzaeQPkHKPKnGPvpCJz8b3n9C7rLAN6b3bKq5w0mA';
