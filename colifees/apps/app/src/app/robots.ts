import type { MetadataRoute } from 'next';

/**
 * The private console is closed to every crawler, unconditionally.
 *
 * This is the polite half of the answer. The enforcing half is that nothing
 * here renders without a valid session, and every response carries an
 * X-Robots-Tag noindex header regardless of what a crawler does with this file.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: [{ userAgent: '*', disallow: '/' }],
  };
}
