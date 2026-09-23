/**
 * Schema.org structured data.
 *
 * Every helper returns a plain object that a page serialises into a
 * `application/ld+json` script. Two rules are applied throughout:
 *
 *  - only claims the business can substantiate. No aggregateRating, no review
 *    counts, no awards, because inventing them is both dishonest and, under
 *    Google's policy, grounds for a manual action.
 *  - no personal data. Structured data is published to the open web.
 */
import { BRAND } from '@polyfix/brand';
import { SITE, absoluteUrl } from './site';
import type { DealerSummary, ProductDetail } from './api';

export function organizationSchema(settings: Record<string, unknown> = {}) {
  const phone = typeof settings['company.support_phone'] === 'string' ? settings['company.support_phone'] : undefined;
  const email = typeof settings['company.support_email'] === 'string' ? settings['company.support_email'] : undefined;
  const socials = ['social.instagram', 'social.facebook', 'social.linkedin', 'social.youtube']
    .map((key) => settings[key])
    .filter((value): value is string => typeof value === 'string' && value.length > 0);

  return {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    '@id': `${SITE.url}/#organization`,
    name: SITE.name,
    url: SITE.url,
    description: SITE.description,
    legalName: BRAND.legalName,
    logo: { '@type': 'ImageObject', url: absoluteUrl('/images/polyfix-logo.svg'), width: 512, height: 512 },
    // The registered business location. Dealer addresses are published
    // separately in the dealer list schema and are never merged into this one.
    address: {
      '@type': 'PostalAddress',
      streetAddress: BRAND.location.line,
      addressLocality: BRAND.location.locality,
      addressRegion: BRAND.location.region,
      addressCountry: BRAND.location.countryCode,
    },
    ...(socials.length > 0 ? { sameAs: socials } : {}),
    ...(phone || email
      ? {
          contactPoint: [
            {
              '@type': 'ContactPoint',
              contactType: 'customer support',
              ...(phone ? { telephone: phone } : {}),
              ...(email ? { email } : {}),
              areaServed: 'IN',
              availableLanguage: ['en', 'hi'],
            },
          ],
        }
      : {}),
  };
}

export function websiteSchema() {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    '@id': `${SITE.url}/#website`,
    url: SITE.url,
    name: SITE.name,
    description: SITE.description,
    publisher: { '@id': `${SITE.url}/#organization` },
    inLanguage: 'en-IN',
  };
}

export function breadcrumbSchema(trail: { name: string; path: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: trail.map((crumb, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: crumb.name,
      item: absoluteUrl(crumb.path),
    })),
  };
}

export function productSchema(product: ProductDetail) {
  const prices = product.sizes.map((size) => size.mrp).filter((price) => price > 0);

  return {
    '@context': 'https://schema.org',
    '@type': 'Product',
    '@id': absoluteUrl(`/mattresses/${product.slug}#product`),
    name: product.name,
    description: product.shortDescription,
    category: `Mattresses > ${product.category}`,
    brand: { '@type': 'Brand', name: SITE.name },
    manufacturer: { '@id': `${SITE.url}/#organization` },
    url: absoluteUrl(`/mattresses/${product.slug}`),
    image: product.gallery.map((image) => absoluteUrl(image.src)),
    // Warranty is a fact of the product, so it is published as one.
    hasMerchantReturnPolicy: undefined,
    additionalProperty: [
      { '@type': 'PropertyValue', name: 'Comfort level', value: product.comfortLevel },
      { '@type': 'PropertyValue', name: 'Firmness (1 soft – 10 firm)', value: String(product.firmnessScore) },
      { '@type': 'PropertyValue', name: 'Warranty', value: `${product.warrantyYears} years` },
      ...Object.entries(product.specifications).map(([name, value]) => ({
        '@type': 'PropertyValue',
        name,
        value: String(value),
      })),
    ],
    ...(prices.length > 0
      ? {
          offers: {
            '@type': 'AggregateOffer',
            priceCurrency: 'INR',
            lowPrice: Math.min(...prices),
            highPrice: Math.max(...prices),
            offerCount: prices.length,
            // Sold through appointed dealers, not online, and the schema says so.
            availability: 'https://schema.org/InStoreOnly',
            seller: { '@id': `${SITE.url}/#organization` },
          },
        }
      : {}),
  };
}

export function faqSchema(faqs: { question: string; answer: string }[]) {
  if (faqs.length === 0) return null;
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((faq) => ({
      '@type': 'Question',
      name: faq.question,
      acceptedAnswer: { '@type': 'Answer', text: faq.answer },
    })),
  };
}

export function dealerListSchema(dealers: DealerSummary[]) {
  if (dealers.length === 0) return null;
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: `${BRAND.shortName} dealer network`,
    numberOfItems: dealers.length,
    itemListElement: dealers.slice(0, 50).map((dealer, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      item: {
        '@type': 'FurnitureStore',
        name: dealer.businessName,
        telephone: dealer.phone,
        address: {
          '@type': 'PostalAddress',
          streetAddress: dealer.address,
          addressLocality: dealer.city,
          addressRegion: dealer.state,
          postalCode: dealer.pincode,
          addressCountry: 'IN',
        },
        ...(dealer.latitude !== null && dealer.longitude !== null
          ? { geo: { '@type': 'GeoCoordinates', latitude: dealer.latitude, longitude: dealer.longitude } }
          : {}),
      },
    })),
  };
}

/**
 * Serialises a schema object for embedding.
 *
 * `<` is escaped so a value containing `</script>` cannot break out of the
 * script element — the one injection route a JSON-LD block actually has.
 */
export function jsonLd(schema: unknown): string {
  return JSON.stringify(schema).replace(/</g, '\\u003c');
}
