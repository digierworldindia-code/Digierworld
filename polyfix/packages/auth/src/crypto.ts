/**
 * Symmetric crypto helpers used across the platform.
 *
 *  - `encryptField` / `decryptField`  AES-256-GCM column encryption for data
 *    that must be readable by the application but not by anyone who obtains a
 *    raw database dump (customer phone numbers, TOTP secrets).
 *  - `blindIndex`                      keyed HMAC used as a searchable
 *    surrogate: lets the system detect "same phone number again" without
 *    storing a plaintext phone directory.
 *  - `sha256Hex` / `randomToken`       session, refresh and reset tokens.
 *  - `signPayload` / `verifySignature` detached HMAC signatures for QR codes
 *    and short-lived media URLs.
 */
import {
  createCipheriv,
  createDecipheriv,
  createHash,
  createHmac,
  randomBytes,
  timingSafeEqual,
} from 'node:crypto';

const ALGORITHM = 'aes-256-gcm';
const IV_BYTES = 12;
const VERSION = 'v1';

export function encryptField(plaintext: string, key: Buffer): string {
  if (key.length !== 32) throw new Error('encryption key must be 32 bytes');
  const iv = randomBytes(IV_BYTES);
  const cipher = createCipheriv(ALGORITHM, key, iv);
  const ciphertext = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return [VERSION, iv.toString('base64url'), ciphertext.toString('base64url'), tag.toString('base64url')].join('.');
}

export function decryptField(encoded: string, key: Buffer): string {
  const parts = encoded.split('.');
  if (parts.length !== 4 || parts[0] !== VERSION) {
    throw new Error('unrecognised ciphertext format');
  }
  const [, ivPart, dataPart, tagPart] = parts as [string, string, string, string];
  const decipher = createDecipheriv(ALGORITHM, key, Buffer.from(ivPart, 'base64url'));
  decipher.setAuthTag(Buffer.from(tagPart, 'base64url'));
  return Buffer.concat([decipher.update(Buffer.from(dataPart, 'base64url')), decipher.final()]).toString('utf8');
}

/** Deterministic keyed hash for equality lookups on encrypted columns. */
export function blindIndex(value: string, key: Buffer | string): string {
  return createHmac('sha256', key).update(normaliseForIndex(value)).digest('hex');
}

export function normaliseForIndex(value: string): string {
  return value.trim().toLowerCase().replace(/\s+/g, ' ');
}

/** Normalises an Indian mobile number to its last 10 digits for blind indexing. */
export function normalisePhone(value: string): string {
  const digits = value.replace(/\D/g, '');
  return digits.length > 10 ? digits.slice(-10) : digits;
}

export function sha256Hex(value: string | Buffer): string {
  return createHash('sha256').update(value).digest('hex');
}

/** 256 bits of entropy, URL-safe. Used for session, refresh and reset tokens. */
export function randomToken(bytes = 32): string {
  return randomBytes(bytes).toString('base64url');
}

export function signPayload(payload: string, secret: string): string {
  return createHmac('sha256', secret).update(payload).digest('base64url');
}

export function verifySignature(payload: string, signature: string, secret: string): boolean {
  const expected = signPayload(payload, secret);
  return safeEqual(expected, signature);
}

/** Length-independent constant-time string comparison. */
export function safeEqual(a: string, b: string): boolean {
  const bufA = createHash('sha256').update(a).digest();
  const bufB = createHash('sha256').update(b).digest();
  return timingSafeEqual(bufA, bufB);
}
