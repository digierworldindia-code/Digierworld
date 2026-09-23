import type { Metadata, Viewport } from 'next';
import { Fraunces, Inter } from 'next/font/google';
import { SiteHeader } from '@/components/site-header';
import { SiteFooter } from '@/components/site-footer';
import { JsonLd } from '@/components/json-ld';
import { Analytics } from '@/components/analytics';
import { getSite } from '@/lib/api';
import { SITE } from '@/lib/site';
import { organizationSchema, websiteSchema } from '@/lib/structured-data';
import './globals.css';
import { BRAND } from '@polyfix/brand';

/**
 * Fonts are self-hosted by next/font: the files are served from this origin,
 * so there is no third-party request on first paint, no layout shift from a
 * late swap, and no referrer leaked to a font CDN.
 */
const display = Fraunces({
  subsets: ['latin'],
  // A variable font loaded as one file covering the whole weight range, rather
  // than three static cuts: fewer requests and fewer bytes.
  weight: 'variable',
  variable: '--font-display-loaded',
  display: 'swap',
});

const body = Inter({
  subsets: ['latin'],
  weight: 'variable',
  variable: '--font-body-loaded',
  display: 'swap',
});

export const metadata: Metadata = {
  metadataBase: new URL(SITE.url),
  title: {
    default: `${BRAND.name} — Premium Mattresses & Sleep Solutions`,
    template: `%s | ${SITE.name}`,
  },
  description: SITE.description,
  applicationName: SITE.name,
  formatDetection: { telephone: false },
  ...(process.env.NEXT_PUBLIC_GSC_VERIFICATION
    ? { verification: { google: process.env.NEXT_PUBLIC_GSC_VERIFICATION } }
    : {}),
};

export const viewport: Viewport = {
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#fbf9f5' },
    { media: '(prefers-color-scheme: dark)', color: '#16120e' },
  ],
  width: 'device-width',
  initialScale: 1,
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const site = await getSite();

  return (
    <html lang="en-IN" className={`${display.variable} ${body.variable}`}>
      <body>
        {/* First stop for a keyboard user on every page. */}
        <a className="skip-link" href="#main">Skip to content</a>

        <SiteHeader />
        <main id="main">{children}</main>
        <SiteFooter settings={site.settings} />

        <JsonLd schema={organizationSchema(site.settings)} />
        <JsonLd schema={websiteSchema()} />
        <Analytics />
      </body>
    </html>
  );
}
