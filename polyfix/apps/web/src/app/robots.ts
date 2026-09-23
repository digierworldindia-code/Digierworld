import type { MetadataRoute } from 'next';
import { SITE } from '@/lib/site';
import { getSite } from '@/lib/api';

/**
 * robots.txt
 *
 * Two jobs. Open the marketing pages to crawlers, and keep them out of
 * anything that identifies an individual product or person: the QR
 * verification address, and the private console on its own subdomain.
 *
 * Disallow is a request, not a control. The private console enforces its own
 * exclusion with a `noindex` header on every response and, more to the point,
 * refuses to serve anything without a session.
 */
export const revalidate = 3600;

export default async function robots(): Promise<MetadataRoute.Robots> {
  const site = await getSite();
  const indexingAllowed = site.settings['seo.robots_allow_indexing'] !== false;

  if (!indexingAllowed) {
    // A staging deployment sets this off, and the whole site closes.
    return {
      rules: [{ userAgent: '*', disallow: '/' }],
      host: SITE.url,
    };
  }

  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: [
          '/api/',
          // Carries a token identifying one person's mattress.
          '/warranty/verify',
          '/warranty?q=',
          // Filtered dealer views are the same content in a different order.
          '/dealers?',
        ],
      },
      {
        // Crawlers that ignore crawl-delay get a slower lane rather than a ban.
        userAgent: ['AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot'],
        allow: '/',
        crawlDelay: 10,
      },
    ],
    sitemap: `${SITE.url}/sitemap.xml`,
    host: SITE.url,
  };
}
