import type { MetadataRoute } from 'next';
import { getSitemapEntries, getProducts } from '@/lib/api';
import { absoluteUrl } from '@/lib/site';

/**
 * XML sitemap.
 *
 * Built from the SEO records an administrator controls, so a page marked
 * noindex or excluded from the sitemap in the console disappears from here too.
 * Nothing under the private console is ever listed: this application does not
 * know those URLs exist.
 */
export const revalidate = 3600;

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [{ entries }, { products }] = await Promise.all([getSitemapEntries(), getProducts()]);

  if (entries.length > 0) {
    return entries.map((entry) => ({
      url: absoluteUrl(entry.path),
      lastModified: new Date(entry.lastModified),
      changeFrequency: entry.changefreq as MetadataRoute.Sitemap[number]['changeFrequency'],
      priority: entry.priority,
    }));
  }

  // Fallback when the content service is unreachable at build time: the static
  // routes are known here, so the sitemap is never empty.
  const now = new Date();
  const staticRoutes: MetadataRoute.Sitemap = [
    { url: absoluteUrl('/'), lastModified: now, changeFrequency: 'weekly', priority: 1 },
    { url: absoluteUrl('/mattresses'), lastModified: now, changeFrequency: 'weekly', priority: 0.9 },
    { url: absoluteUrl('/warranty'), lastModified: now, changeFrequency: 'monthly', priority: 0.9 },
    { url: absoluteUrl('/dealers'), lastModified: now, changeFrequency: 'weekly', priority: 0.8 },
    { url: absoluteUrl('/why-colifees'), lastModified: now, changeFrequency: 'monthly', priority: 0.6 },
    { url: absoluteUrl('/about'), lastModified: now, changeFrequency: 'monthly', priority: 0.7 },
    { url: absoluteUrl('/contact'), lastModified: now, changeFrequency: 'yearly', priority: 0.6 },
  ];

  return [
    ...staticRoutes,
    ...products.map((product) => ({
      url: absoluteUrl(`/mattresses/${product.slug}`),
      lastModified: now,
      changeFrequency: 'monthly' as const,
      priority: 0.8,
    })),
  ];
}
