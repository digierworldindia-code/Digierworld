/**
 * Shared field-level validators.
 *
 * These are the *server's* rules. The console and the website reuse them for
 * instant feedback, but nothing a browser reports is ever taken on trust: every
 * request is parsed against these schemas again inside the API before it
 * reaches a service.
 */
import { z } from 'zod';

/** Trims, then rejects anything left empty — avoids " " passing a min(1). */
export const trimmed = (max: number) => z.string().trim().min(1).max(max);

export const uuid = z.string().uuid('must be a valid identifier');

export const email = z
  .string()
  .trim()
  .toLowerCase()
  .min(5)
  .max(255)
  .regex(/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i, 'must be a valid email address');

/** Indian mobile numbers, optionally with a +91 country code. */
export const phone = z
  .string()
  .trim()
  .regex(/^(\+?91[- ]?)?[6-9]\d{9}$/, 'must be a valid 10-digit Indian mobile number');

export const pincode = z.string().trim().regex(/^[1-9][0-9]{5}$/, 'must be a valid 6-digit PIN code');

export const gstNumber = z
  .string()
  .trim()
  .toUpperCase()
  .regex(/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/, 'must be a valid 15-character GSTIN');

/**
 * A mattress serial. Accepts the label's spacing and casing, normalises to the
 * canonical form. The value is only ever used as a lookup key — never
 * interpolated into SQL.
 *
 * The CLF prefix predates the POLYFIX MATTRESS name and is retained
 * deliberately: it is printed on every label already in the field. See
 * BRAND.serialPrefix in @polyfix/brand for the reasoning and the change path.
 */
export const serialNumber = z
  .string()
  .trim()
  .toUpperCase()
  .transform((v) => v.replace(/[\s-]/g, ''))
  .pipe(z.string().regex(/^CLF\d{8,12}$/, 'must be a valid serial number, for example CLF26000001'));

/** Opaque QR token. Length-bounded and character-restricted before any lookup. */
export const qrToken = z
  .string()
  .trim()
  .regex(/^[A-Za-z0-9_-]{22,64}$/, 'not a valid product QR code');

export const claimNumber = z
  .string()
  .trim()
  .toUpperCase()
  .regex(/^CLM\d{8,12}$/, 'must be a valid claim number');

export const slug = z
  .string()
  .trim()
  .toLowerCase()
  .min(2)
  .max(120)
  .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'may contain lowercase letters, numbers and hyphens only');

export const money = z
  .union([z.number(), z.string()])
  .transform((v) => (typeof v === 'string' ? Number(v) : v))
  .pipe(z.number().finite().nonnegative().max(10_000_000));

export const isoDate = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'must be a date in YYYY-MM-DD form')
  .refine((v) => !Number.isNaN(Date.parse(v)), 'must be a real date');

export const isoDateTime = z
  .string()
  .datetime({ offset: true })
  .or(z.string().regex(/^\d{4}-\d{2}-\d{2}$/));

/**
 * Free text destined for storage and later display. Control characters are
 * stripped; escaping for a particular output context happens at render time.
 *
 * The minimum length is a parameter rather than something callers chain on
 * afterwards: `.min()` applied to the transformed schema would type-check as a
 * plain string method and quietly widen the inferred type to `unknown`.
 */
export const safeText = (max: number, options: { min?: number; minMessage?: string } = {}) =>
  z
    .string()
    .trim()
    .min(options.min ?? 0, options.minMessage)
    .max(max)
    // eslint-disable-next-line no-control-regex
    .transform((v) => v.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, ''));

/** A reason string is mandatory wherever a record is deleted or voided. */
export const reason = z
  .string()
  .trim()
  .min(10, 'give a reason of at least 10 characters so the audit trail is meaningful')
  .max(1000);

export const pagination = z.object({
  page: z.coerce.number().int().min(1).max(10_000).default(1),
  pageSize: z.coerce.number().int().min(1).max(100).default(25),
});

export const sortDirection = z.enum(['asc', 'desc']).default('desc');

/**
 * Search text used in LIKE/trigram lookups.
 *
 * LIKE wildcards are removed so a caller cannot turn a search box into a full
 * table scan, and a term that consists only of wildcards collapses to
 * `undefined` rather than to an empty string — an empty `contains` matches
 * every row, which would quietly hand back the whole table.
 */
export const searchQuery = z
  .string()
  .trim()
  .max(80)
  .transform((v) => {
    const cleaned = v.replace(/[%_\\]/g, '').trim();
    return cleaned.length > 0 ? cleaned : undefined;
  })
  .optional();

export type Pagination = z.infer<typeof pagination>;
