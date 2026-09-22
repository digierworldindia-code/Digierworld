/**
 * Site-wide constants and helpers shared by every page.
 *
 * Values that a marketing team may want to change without a deploy live in the
 * CMS and arrive through the API. What stays here is structural: URLs, the
 * navigation shape, and formatting helpers.
 */

export const SITE = {
  name: process.env.NEXT_PUBLIC_SITE_NAME ?? 'COLIFEES',
  url: (process.env.NEXT_PUBLIC_SITE_URL ?? 'https://colifees.com').replace(/\/$/, ''),
  appUrl: (process.env.NEXT_PUBLIC_APP_URL ?? 'https://app.colifees.com').replace(/\/$/, ''),
  description:
    'COLIFEES manufactures premium mattresses in hybrid, memory foam, latex and orthopaedic ranges. Every unit carries a serial number you can verify online.',
  locale: 'en_IN',
} as const;

export const NAV = [
  { href: '/', label: 'Home' },
  { href: '/mattresses', label: 'Mattresses' },
  { href: '/why-colifees', label: 'Why COLIFEES' },
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
