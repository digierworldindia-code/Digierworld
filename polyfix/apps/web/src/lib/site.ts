/**
 * Site-wide constants and helpers shared by every page.
 *
 * The company name, short name and location come from @polyfix/brand and are
 * not restated here. What this module adds is website-specific: resolved URLs
 * (environment first, brand default second), the navigation shape, and
 * formatting helpers.
 *
 * Values a marketing team changes without a deploy — phone, email, postal
 * address, social links — live in the system_settings table and arrive through
 * the public content API.
 */
import { BRAND } from '@polyfix/brand';

export const SITE = {
  name: process.env.NEXT_PUBLIC_SITE_NAME ?? BRAND.name,
  shortName: BRAND.shortName,
  url: (process.env.NEXT_PUBLIC_SITE_URL ?? BRAND.web.defaultSiteUrl).replace(/\/$/, ''),
  appUrl: (process.env.NEXT_PUBLIC_APP_URL ?? BRAND.web.defaultAppUrl).replace(/\/$/, ''),
  description: `${BRAND.name} manufactures premium mattresses in hybrid, memory foam, latex and orthopaedic ranges. Every unit carries a serial number you can verify online.`,
  location: BRAND.location,
  locale: BRAND.web.locale,
} as const;

export const NAV = [
  { href: '/', label: 'Home' },
  { href: '/mattresses', label: 'Mattresses' },
  { href: '/why-polyfix', label: `Why ${BRAND.shortName}` },
  { href: '/warranty', label: 'Warranty' },
  { href: '/dealers', label: 'Dealers' },
  { href: '/about', label: 'About' },
  { href: '/contact', label: 'Contact' },
] as const;

export function absoluteUrl(path: string): string {
  return `${SITE.url}${path.startsWith('/') ? path : `/${path}`}`;
}

export function formatPrice(value: number | null | undefined): string {
  if (value === null || value === undefined) return '';
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(value);
}

export function formatDate(value: string | Date | null | undefined): string {
  if (!value) return '';
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
}
