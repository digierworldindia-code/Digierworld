import type { Metadata } from 'next';
import Image from 'next/image';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getProduct, getProducts } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { formatPrice } from '@/lib/site';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { FaqList } from '@/components/faq-list';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema, faqSchema, productSchema } from '@/lib/structured-data';
import { BRAND } from '@polyfix/brand';

export const revalidate = 300;

/**
 * Pre-renders a page per product at build time, so a crawler and a first-time
 * visitor both get static HTML. New products appear on the next revalidation
 * rather than requiring a deploy.
 */
export async function generateStaticParams() {
  const { products } = await getProducts();
  return products.map((product) => ({ slug: product.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await getProduct(slug);

  if (!data) {
    return { title: 'Mattress not found', robots: { index: false, follow: true } };
  }

  const image = data.product.gallery[0]?.src;
  return buildMetadata({
    seo: data.seo,
    fallbackTitle: `${data.product.name} — ${data.product.category} Mattress`,
    fallbackDescription: data.product.shortDescription,
    path: `/mattresses/${slug}`,
    ...(image ? { imagePath: image } : {}),
  });
}

export default async function ProductPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const data = await getProduct(slug);

  // A slug that does not resolve returns a real 404 rather than an empty page,
  // so a removed product stops being indexed instead of becoming thin content.
  if (!data) notFound();

  const { product } = data;
  const { products } = await getProducts();
  const related = products.filter((item) => item.slug !== slug).slice(0, 3);
  const lowest = product.sizes.reduce<number | null>(
    (min, size) => (min === null ? size.mrp : Math.min(min, size.mrp)),
    null,
  );

  const trail = [
    { name: 'Home', path: '/' },
    { name: 'Mattresses', path: '/mattresses' },
    { name: product.name, path: `/mattresses/${slug}` },
  ];

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={trail} />
      </div>

      {/* ---------- overview ---------- */}
      <section className="section-tight">
        <div className="container hero__grid">
          <div className="stack">
            <p className="eyebrow">{product.category}</p>
            <h1>{product.name}</h1>
            {product.tagline ? <p className="lede">{product.tagline}</p> : null}
            <p>{product.shortDescription}</p>

            <div className="cluster mt-6">
              <span className="badge badge-neutral">{product.comfortLevel}</span>
              <span className="badge badge-neutral">Firmness {product.firmnessScore}/10</span>
              <span className="badge badge-positive">{product.warrantyYears} year warranty</span>
            </div>

            {lowest !== null ? (
              <p style={{ marginTop: 'var(--space-5)' }}>
                <span className="muted" style={{ fontSize: 'var(--step--1)' }}>Recommended retail from </span>
                <strong style={{ fontSize: 'var(--step-2)', fontFamily: 'var(--font-display)' }}>{formatPrice(lowest)}</strong>
              </p>
            ) : null}

            <div className="cluster mt-6">
              <Link href="/dealers" className="btn btn-primary">Find a dealer</Link>
              <Link href="/warranty" className="btn btn-secondary">Verify a mattress</Link>
            </div>
          </div>

          <div className="hero__media">
            {product.gallery[0] ? (
              <Image
                src={product.gallery[0].src}
                alt={product.gallery[0].alt}
                width={product.gallery[0].width}
                height={product.gallery[0].height}
                priority
                fetchPriority="high"
                sizes="(max-width: 62rem) 100vw, 50vw"
                style={{ width: '100%', height: 'auto' }}
              />
            ) : null}
          </div>
        </div>
      </section>

      {/* ---------- highlights ---------- */}
      {product.highlights.length > 0 ? (
        <section className="section-tight section-sand reveal">
          <div className="container">
            <div className="section-head">
              <h2>{product.headline ?? 'Why this one'}</h2>
              {product.subheadline ? <p className="lede">{product.subheadline}</p> : null}
            </div>
            <div className="grid grid-3">
              {product.highlights.map((highlight) => (
                <div className="card stack" key={highlight.title}>
                  <h3>{highlight.title}</h3>
                  <p className="muted">{highlight.body}</p>
                </div>
              ))}
            </div>
          </div>
        </section>
      ) : null}

      {/* ---------- construction ---------- */}
      <section className="section reveal">
        <div className="container hero__grid">
          <div className="stack">
            <h2>Construction</h2>
            <div className="prose">
              <p>{product.description}</p>
            </div>

            {product.materials.length > 0 ? (
              <>
                <h3 style={{ marginTop: 'var(--space-6)' }}>Materials</h3>
                <ul className="stack" style={{ paddingLeft: '1.1rem', color: 'var(--ink-muted)' }}>
                  {product.materials.map((material) => (
                    <li key={material}>{material}</li>
                  ))}
                </ul>
              </>
            ) : null}

            {product.features.length > 0 ? (
              <>
                <h3 style={{ marginTop: 'var(--space-6)' }}>Features</h3>
                <ul className="stack" style={{ paddingLeft: '1.1rem', color: 'var(--ink-muted)' }}>
                  {product.features.map((feature) => (
                    <li key={feature}>{feature}</li>
                  ))}
                </ul>
              </>
            ) : null}
          </div>

          <div>
            {product.gallery[1] ? (
              <Image
                src={product.gallery[1].src}
                alt={product.gallery[1].alt}
                width={product.gallery[1].width}
                height={product.gallery[1].height}
                loading="lazy"
                sizes="(max-width: 62rem) 100vw, 50vw"
                style={{ width: '100%', height: 'auto', borderRadius: 'var(--radius-lg)', border: '1px solid var(--line)' }}
              />
            ) : null}

            <table className="spec-table" style={{ marginTop: 'var(--space-6)' }}>
              <caption className="visually-hidden">Specifications for {product.name}</caption>
              <tbody>
                {Object.entries(product.specifications).map(([key, value]) => (
                  <tr key={key}>
                    <th scope="row">{key}</th>
                    <td>{value}</td>
                  </tr>
                ))}
                <tr>
                  <th scope="row">Warranty</th>
                  <td>{product.warrantyYears} years from the date of sale</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      {/* ---------- sizes ---------- */}
      {product.sizes.length > 0 ? (
        <section className="section-tight section-sand reveal" id="sizes">
          <div className="container">
            <div className="section-head">
              <h2>Sizes and prices</h2>
              <p className="lede">
                Recommended retail prices. Your dealer sets the final price and handles delivery.
              </p>
            </div>

            <table className="spec-table" style={{ background: 'var(--paper-raised)', borderRadius: 'var(--radius)', padding: '0 var(--space-5)' }}>
              <caption className="visually-hidden">Available sizes for {product.name}</caption>
              <thead>
                <tr>
                  <th scope="col" style={{ width: '40%' }}>Size</th>
                  <th scope="col">Dimensions (inches)</th>
                  <th scope="col">Recommended retail</th>
                </tr>
              </thead>
              <tbody>
                {product.sizes.map((size) => (
                  <tr key={size.label}>
                    <th scope="row">{size.label}</th>
                    <td>{size.lengthIn} × {size.widthIn} × {size.heightIn}</td>
                    <td>{formatPrice(size.mrp)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {/* ---------- care ---------- */}
      {product.careInstructions ? (
        <section className="section-tight reveal">
          <div className="container-narrow stack">
            <h2>Care</h2>
            <p className="muted">{product.careInstructions}</p>
          </div>
        </section>
      ) : null}

      {/* ---------- FAQs ---------- */}
      {product.faqs.length > 0 ? (
        <div className="container-narrow">
          <FaqList faqs={product.faqs} heading={`${product.name} questions`} />
        </div>
      ) : null}

      {/* ---------- dealer CTA ---------- */}
      <section className="section section-ink reveal">
        <div className="container text-center stack">
          <h2>Try it before you decide</h2>
          <p className="lede mx-auto" style={{ textAlign: 'center' }}>
            {BRAND.shortName} mattresses are sold through appointed dealers who hold stock and handle warranty
            support. Find one near you and lie on it for ten minutes.
          </p>
          <div className="cluster mt-6" style={{ justifyContent: 'center' }}>
            <Link href="/dealers" className="btn btn-on-ink">Find a dealer</Link>
            <Link href="/contact" className="btn btn-ghost" style={{ color: '#d8d0c5' }}>Ask a question →</Link>
          </div>
        </div>
      </section>

      {/* ---------- related ---------- */}
      {related.length > 0 ? (
        <section className="section-tight">
          <div className="container">
            <div className="section-head">
              <h2>Other {BRAND.shortName} mattresses</h2>
            </div>
            <div className="grid grid-3">
              {related.map((item) => (
                <ProductCardLink key={item.slug} slug={item.slug} name={item.name} category={item.category} description={item.shortDescription} />
              ))}
            </div>
          </div>
        </section>
      ) : null}

      <JsonLd schema={breadcrumbSchema(trail)} />
      <JsonLd schema={productSchema(product)} />
      <JsonLd schema={faqSchema(product.faqs)} />
    </>
  );
}

/** A lighter card for the related row: no image request for below-fold links. */
function ProductCardLink({ slug, name, category, description }: { slug: string; name: string; category: string; description: string }) {
  return (
    <Link href={`/mattresses/${slug}`} className="card-link">
      <article className="card stack">
        <span className="eyebrow">{category}</span>
        <h3>{name}</h3>
        <p className="muted" style={{ fontSize: 'var(--step--1)' }}>{description}</p>
      </article>
    </Link>
  );
}
