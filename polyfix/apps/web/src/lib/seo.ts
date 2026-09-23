import type { Metadata } from 'next';
import { SITE, absoluteUrl } from './site';
import type { SeoPayload } from './api';

/**
 * Builds page metadata.
 *
 * Every field an administrator can edit in the console (title, description,
 * canonical, social image, robots) flows through here, so changing SEO copy is
 * a CMS action rather than a code change. Local defaults are used only when the
 * content service has nothing to say.
 */
export function buildMetadata(options: {
  seo: SeoPayload | null;
  fallbackTitle: string;
  fallbackDescription: string;
  path: string;
  imagePath?: string;
  type?: 'website' | 'article';
}): Metadata {
  const { seo, fallbackTitle, fallbackDescription, path } = options;

  const title = seo?.title ?? fallbackTitle;
  const description = seo?.description ?? fallbackDescription;
  const canonical = absoluteUrl(seo?.canonicalPath ?? path);
  const image = absoluteUrl(seo?.ogImageUrl ?? options.imagePath ?? '/images/og/polyfix-default.svg');

  return {
    // `absolute` stops the root layout's "%s | BRAND" template from appending
    // the brand a second time. Titles here are complete: either an
    // administrator wrote the whole thing in the SEO screen, or the page passed
    // a fallback that already reads as a finished title. Without this, every
    // product page rendered "... | POLYFIX | POLYFIX MATTRESS".
    title: { absolute: title },
    description,
    ...(seo?.keywords && seo.keywords.length > 0 ? { keywords: seo.keywords } : {}),
    alternates: { canonical },
    robots: {
      index: seo?.robotsIndex ?? true,
      follow: seo?.robotsFollow ?? true,
      googleBot: {
        index: seo?.robotsIndex ?? true,
        follow: seo?.robotsFollow ?? true,
        'max-image-preview': 'large',
        'max-snippet': -1,
        'max-video-preview': -1,
      },
    },
    openGraph: {
      type: options.type ?? 'website',
      siteName: SITE.name,
      title: seo?.ogTitle ?? title,
      description: seo?.ogDescription ?? description,
      url: canonical,
      locale: SITE.locale,
      images: [{ url: image, width: 1200, height: 630, alt: seo?.ogTitle ?? title }],
    },
    twitter: {
      card: (seo?.twitterCard as 'summary' | 'summary_large_image') ?? 'summary_large_image',
      title: seo?.ogTitle ?? title,
      description: seo?.ogDescription ?? description,
      images: [image],
    },
  };
}

/**
 * Metadata for pages that must never be indexed: anything showing a result
 * about a specific product someone owns, or a page with no standalone value.
 */
export function noIndexMetadata(title: string, description: string): Metadata {
  return {
    // `absolute` stops the root layout's "%s | BRAND" template from appending
    // the brand a second time. Titles here are complete: either an
    // administrator wrote the whole thing in the SEO screen, or the page passed
    // a fallback that already reads as a finished title. Without this, every
    // product page rendered "... | POLYFIX | POLYFIX MATTRESS".
    title: { absolute: title },
    description,
    robots: { index: false, follow: false, nocache: true, googleBot: { index: false, follow: false } },
  };
}
