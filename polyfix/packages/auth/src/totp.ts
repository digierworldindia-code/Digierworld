/**
 * TOTP (RFC 6238) for optional two-factor authentication.
 *
 * Implemented directly on node:crypto rather than pulling a dependency: the
 * algorithm is small, and keeping it here means the code that touches MFA
 * secrets is auditable in one place.
 */
import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import { BRAND } from '@polyfix/brand';

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const DIGITS = 6;
const PERIOD_SECONDS = 30;
/** Accept the previous and next window to tolerate clock drift. */
const DEFAULT_WINDOW = 1;

export function generateTotpSecret(bytes = 20): string {
  return base32Encode(randomBytes(bytes));
}

export function buildOtpAuthUrl(params: {
  secret: string;
  accountName: string;
  issuer?: string;
}): string {
  // The issuer is the label a person sees in their authenticator app.
  //
  // Changing it does NOT invalidate anyone's existing enrolment: the issuer is
  // not part of the TOTP calculation, only of the enrolment URL. People who
  // enrolled under the previous name keep working and will see the old label
  // until they re-enrol.
  const issuer = params.issuer ?? BRAND.shortName;
  const label = encodeURIComponent(`${issuer}:${params.accountName}`);
  const query = new URLSearchParams({
    secret: params.secret,
    issuer,
    algorithm: 'SHA1',
    digits: String(DIGITS),
    period: String(PERIOD_SECONDS),
  });
  return `otpauth://totp/${label}?${query.toString()}`;
}

export function generateTotp(secret: string, atMs: number = Date.now()): string {
  return computeCode(secret, Math.floor(atMs / 1000 / PERIOD_SECONDS));
}

/**
 * Verifies a user-supplied code. Returns the matched counter so the caller can
 * persist it and reject replay of the same code inside its validity window.
 */
export function verifyTotp(
  secret: string,
  code: string,
  options: { atMs?: number; window?: number; lastUsedCounter?: number } = {},
): { valid: boolean; counter?: number } {
  const cleaned = code.replace(/\D/g, '');
  if (cleaned.length !== DIGITS) return { valid: false };
  const window = options.window ?? DEFAULT_WINDOW;
  const current = Math.floor((options.atMs ?? Date.now()) / 1000 / PERIOD_SECONDS);

  for (let offset = -window; offset <= window; offset += 1) {
    const counter = current + offset;
    if (options.lastUsedCounter !== undefined && counter <= options.lastUsedCounter) continue;
    const expected = computeCode(secret, counter);
    if (constantTimeEquals(expected, cleaned)) return { valid: true, counter };
  }
  return { valid: false };
}

/** Single-use recovery codes, shown once at enrolment and stored hashed. */
export function generateRecoveryCodes(count = 10): string[] {
  return Array.from({ length: count }, () => {
    const raw = base32Encode(randomBytes(10)).slice(0, 10);
    return `${raw.slice(0, 5)}-${raw.slice(5, 10)}`;
  });
}

function computeCode(secret: string, counter: number): string {
  const key = base32Decode(secret);
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(counter));
  const digest = createHmac('sha1', key).update(buf).digest();
  const offset = digest[digest.length - 1]! & 0x0f;
  const binary =
    ((digest[offset]! & 0x7f) << 24) |
    ((digest[offset + 1]! & 0xff) << 16) |
    ((digest[offset + 2]! & 0xff) << 8) |
    (digest[offset + 3]! & 0xff);
  return String(binary % 10 ** DIGITS).padStart(DIGITS, '0');
}

function constantTimeEquals(a: string, b: string): boolean {
  const bufA = Buffer.from(a);
  const bufB = Buffer.from(b);
  if (bufA.length !== bufB.length) return false;
  return timingSafeEqual(bufA, bufB);
}

export function base32Encode(buffer: Buffer): string {
  let bits = 0;
  let value = 0;
  let output = '';
  for (const byte of buffer) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5) {
      output += BASE32_ALPHABET[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
  }
  if (bits > 0) output += BASE32_ALPHABET[(value << (5 - bits)) & 31];
  return output;
}

export function base32Decode(input: string): Buffer {
  const cleaned = input.replace(/=+$/, '').toUpperCase().replace(/\s/g, '');
  let bits = 0;
  let value = 0;
  const bytes: number[] = [];
  for (const char of cleaned) {
    const index = BASE32_ALPHABET.indexOf(char);
    if (index === -1) throw new Error('invalid base32 character in TOTP secret');
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }
  return Buffer.from(bytes);
}
