import type { Metadata } from 'next';
import Link from 'next/link';
import { getProducts, getPage } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { ProductCard } from '@/components/product-card';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema } from '@/lib/structured-data';
import { absoluteUrl } from '@/lib/site';

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  return buildMetadata({
    seo: null,
    fallbackTitle: 'Mattresses — Hybrid, Memory Foam, Latex & Orthopaedic',
    fallbackDescription:
      'Compare the COLIFEES mattress range by construction, firmness rating, materials and warranty term. Five sizes, sold through appointed dealers.',
    path: '/mattresses',
  });
}

const TRAIL = [
  { name: 'Home', path: '/' },
  { name: 'Mattresses', path: '/mattresses' },
];

export default async function MattressesPage() {
  const [{ products }, page] = await Promise.all([getProducts(), getPage('home')]);
  void page;

  // Grouped by construction so the listing reads as a considered range rather
  // than an undifferentiated grid.
  const categories = [...new Set(products.map((product) => product.category))];

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={TRAIL} />
      </div>

      <section className="section-tight">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">The range</p>
            <h1>COLIFEES mattresses</h1>
            <p className="lede">
              Five constructions covering firm through medium-soft. Every model lists its materials,
              densities and firmness rating, so you can compare them on the same terms rather than on
              adjectives.
            </p>
          </div>

          {products.length === 0 ? (
            <p className="notice notice-neutral">
              The catalogue is being updated. Please check back shortly, or{' '}
              <Link href="/contact">contact us</Link> and we will help.
            </p>
          ) : (
            <div className="stack-lg">
              {categories.map((category) => {
                const inCategory = products.filter((product) => product.category === category);
                return (
                  <section key={category} aria-labelledby={`cat-${category.toLowerCase().replace(/\s+/g, '-')}`}>
                    <h2
                      id={`cat-${category.toLowerCase().replace(/\s+/g, '-')}`}
                      style={{ fontSize: 'var(--step-2)', marginBottom: 'var(--space-5)' }}
                    >
                      {category}
                    </h2>
                    <div className="grid grid-3">
                      {inCategory.map((product, index) => (
                        <ProductCard key={product.slug} product={product} priority={index === 0 && category === categories[0]} />
                      ))}
                    </div>
                  </section>
                );
              })}
            </div>
          )}
        </div>
      </section>

      {/* A short buying guide: useful to a reader, and the kind of content that
          earns a page its place in search results for "how to choose". */}
      <section className="section section-sand">
        <div className="container">
          <div className="section-head">
            <h2>Choosing a firmness</h2>
            <p className="lede">Firmness is personal. As a starting point, match it to how you sleep.</p>
          </div>

          <div className="grid grid-3">
            <div className="card stack">
              <h3>Side sleepers</h3>
              <p className="muted">
                The shoulder and hip carry most of the load, so a surface with more give usually feels
                better. Look at medium-soft to medium: Serenity or Latex Natura.
              </p>
            </div>
            <div className="card stack">
              <h3>Back sleepers</h3>
              <p className="muted">
                The lumbar curve needs filling without the hips dropping. Medium-firm tends to suit:
                Aurea Hybrid, or Dual Comfort on its firmer face.
              </p>
            </div>
            <div className="card stack">
              <h3>Front sleepers</h3>
              <p className="muted">
                A softer mattress lets the hips sink and the lower back arch. Firmer is usually
                better: Orthocore Support.
              </p>
            </div>
          </div>

          <p className="muted mt-7" style={{ maxWidth: 'var(--measure)' }}>
            These are general guides, not medical advice. If you have a specific back or joint
            condition, speak to your doctor, and try the mattress at a dealer before you decide.
          </p>

          <p className="mt-6">
            <Link href="/dealers" className="btn btn-primary">Find a dealer to try them</Link>
          </p>
        </div>
      </section>

      <JsonLd schema={breadcrumbSchema(TRAIL)} />
      <JsonLd
        schema={{
          '@context': 'https://schema.org',
          '@type': 'CollectionPage',
          name: 'COLIFEES mattresses',
          url: absoluteUrl('/mattresses'),
          description:
            'The COLIFEES mattress range: hybrid, memory foam, natural latex, orthopaedic and dual firmness.',
          hasPart: products.map((product) => ({
            '@type': 'Product',
            name: product.name,
            url: absoluteUrl(`/mattresses/${product.slug}`),
          })),
        }}
      />
    </>
  );
}
