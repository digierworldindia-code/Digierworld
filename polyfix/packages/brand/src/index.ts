/**
 * Brand configuration — the single source of truth for who this company is.
 *
 * WHY THIS FILE EXISTS
 * Before it, the company name was written out in 143 places across the API,
 * the public website, the console, the seed data and the SVG assets. Renaming
 * the business meant finding every one of them. Now there is one file.
 *
 * WHERE EACH KIND OF VALUE BELONGS
 *
 *   here                    Facts that are fixed for a deployment and needed at
 *                           build time: the name, the short name, the legal
 *                           entity, the registered location, the serial prefix.
 *
 *   environment variables   Values that differ between development, staging and
 *                           production: NEXT_PUBLIC_SITE_URL, NEXT_PUBLIC_APP_URL.
 *                           They override the defaults below.
 *
 *   system_settings table   Values an administrator changes at runtime without a
 *                           deploy: support phone, support email, postal
 *                           address, social links. Edited in the console under
 *                           Administration → Settings, and served to the website
 *                           through the public content API.
 *
 * So: put a value here only if it is structural. If a non-technical person
 * should be able to change it on a Tuesday afternoon, it belongs in
 * system_settings instead.
 *
 * This module deliberately has no dependencies and reads no environment at
 * import time, so it is safe in a browser bundle, in a server process, in a
 * seed script and in a test.
 */

export interface BrandLocation {
  /** One-line form, used in the footer and in contact blocks. */
  line: string;
  locality: string;
  region: string;
  country: string;
  countryCode: string;
}

export interface Brand {
  /** Full trading name, as it should appear to customers. */
  name: string;
  /** Short form for tight spaces: a sidebar, a badge, a mobile header. */
  shortName: string;
  /** Registered entity name, for invoices and legal pages. */
  legalName: string;
  /** Wordmark split so the two halves can be styled differently. */
  wordmark: { lead: string; trail: string };
  /** Registered business location. NOT a customer or dealer address. */
  location: BrandLocation;
  /** Default hosts. Environment variables override these per deployment. */
  web: { defaultSiteUrl: string; defaultAppUrl: string; locale: string };
  /** Prefix on every mattress serial number. See the note below before changing. */
  serialPrefix: string;
}

export const BRAND: Brand = {
  name: 'POLYFIX MATTRESS',
  shortName: 'POLYFIX',
  legalName: 'POLYFIX MATTRESS',

  wordmark: { lead: 'POLY', trail: 'FIX' },

  location: {
    line: 'Manesar, Noranpur Chowk, Haryana, India',
    locality: 'Manesar',
    region: 'Haryana',
    country: 'India',
    countryCode: 'IN',
  },

  web: {
    defaultSiteUrl: 'https://polyfixmattress.com',
    defaultAppUrl: 'https://app.polyfixmattress.com',
    locale: 'en_IN',
  },

  /**
   * Serial number prefix.
   *
   * Deliberately still 'CLF'. Every mattress already manufactured carries this
   * prefix on a physical law label and in `mattresses.serial_number`, which is
   * protected by a CHECK constraint and referenced by warranty records, claims
   * and QR codes. Changing it here would not change those units; it would only
   * create a second serial format alongside the first.
   *
   * If the business decides new production should carry a POLYFIX prefix, that
   * is a deliberate, dated change and needs all four of these together:
   *   1. a migration widening mattresses_serial_format_chk to accept both
   *   2. a matching update to `serialNumber` in packages/validation
   *   3. an update to colifees_next_serial() in the database
   *   4. a cut-over date, so the two formats are explainable years later
   * See docs/11-brand-configuration.md.
   */
  serialPrefix: 'CLF',
};

/** `POLYFIX MATTRESS — Premium Mattresses` style joining, without double dashes. */
export function brandSuffix(text: string): string {
  return `${text} | ${BRAND.name}`;
}

/** Product names are stored brand-prefixed, e.g. "POLYFIX Aurea Hybrid". */
export function brandedProductName(model: string): string {
  return `${BRAND.shortName} ${model}`;
}
