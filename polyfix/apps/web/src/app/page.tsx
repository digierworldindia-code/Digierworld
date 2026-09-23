import Image from 'next/image';
import Link from 'next/link';
import type { Metadata } from 'next';
import { getPage, getProducts, getSite } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { formatPrice } from '@/lib/site';
import { ProductCard } from '@/components/product-card';
import { FaqList } from '@/components/faq-list';
import { JsonLd } from '@/components/json-ld';
import { faqSchema } from '@/lib/structured-data';
import { ICONS } from '@/components/icons';
import { BRAND } from '@polyfix/brand';

/**
 * Homepage.
 *
 * Seven sections, in the order the brief asks for, and deliberately short: the
 * job of this page is to establish what the company makes, why it can be trusted,
 * and where to go next. Copy comes from the CMS, so marketing can rewrite any
 * of it without a deploy; the fallbacks below keep the page whole if the
 * content service is unreachable.
 */

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  const page = await getPage('home');
  return buildMetadata({
    seo: page?.seo ?? null,
    fallbackTitle: `${BRAND.name} — Premium Mattresses & Sleep Solutions`,
    fallbackDescription:
      `${BRAND.name} manufactures premium hybrid, memory foam, latex and orthopaedic mattresses. Every unit carries a serial number you can verify online.`,
    path: '/',
  });
}

const FALLBACK_HERO = {
  eyebrow: BRAND.name,
  heading: 'Mattresses built to be slept on for years',
  body: 'Hybrid, memory foam, latex and orthopaedic ranges, manufactured to a documented specification and traceable by serial number from the production line to your bedroom.',
  primaryCta: { label: 'Explore mattresses', href: '/mattresses' },
  secondaryCta: { label: 'Find a dealer', href: '/dealers' },
  tertiaryCta: { label: 'Check warranty', href: '/warranty' },
  image: {
    src: '/images/hero/polyfix-hero.svg',
    alt: `A ${BRAND.shortName} mattress in a calm, softly lit bedroom`,
    width: 1600,
    height: 1100,
  },
};

const FALLBACK_BENEFITS = [
  { title: 'Premium comfort', body: 'Comfort layers specified by material and density, not by marketing adjective.', icon: 'comfort' },
  { title: 'Quality materials', body: 'High-density foam, tempered pocketed springs and natural latex, documented on every product page.', icon: 'materials' },
  { title: 'Long-lasting support', body: 'Core densities chosen to resist the body impressions that shorten a mattress life.', icon: 'support' },
  { title: 'Reliable warranty', body: 'Every unit carries a serial number. Verify the product and its warranty status in seconds.', icon: 'warranty' },
];

export default async function HomePage() {
  const [page, { products }, site] = await Promise.all([getPage('home'), getProducts(), getSite()]);

  const hero = { ...FALLBACK_HERO, ...(page?.page.hero ?? {}) } as typeof FALLBACK_HERO;
  const sections = page?.page.sections ?? [];

  const findSection = (type: string) => sections.find((section) => section.type === type);
  const benefits = (findSection('benefits')?.items as typeof FALLBACK_BENEFITS | undefined) ?? FALLBACK_BENEFITS;
  const craftsmanship = findSection('craftsmanship');
  const verification = findSection('verification');
  const dealersSection = findSection('dealers');
  const closing = findSection('closing-cta');

  const featured = products.filter((product) => product.isFeatured).slice(0, 3);
  const showcase = featured.length > 0 ? featured : products.slice(0, 3);
  const homeFaqs = site.faqs.filter((faq) => faq.category === 'buying' || faq.category === 'authenticity').slice(0, 5);

  const lowestPrice = products.reduce<number | null>((lowest, product) => {
    if (product.fromPrice === null) return lowest;
    return lowest === null ? product.fromPrice : Math.min(lowest, product.fromPrice);
  }, null);

  return (
    <>
      {/* ---------- 1. hero ---------- */}
      <section className="hero">
        <div className="container hero__grid">
          <div className="hero__content">
            <p className="eyebrow">{hero.eyebrow}</p>
            <h1>{hero.heading}</h1>
            <p className="lede">{hero.body}</p>

            <div className="hero__ctas">
              <Link href={hero.primaryCta.href} className="btn btn-primary">{hero.primaryCta.label}</Link>
              <Link href={hero.secondaryCta.href} className="btn btn-secondary">{hero.secondaryCta.label}</Link>
              <Link href={hero.tertiaryCta.href} className="btn btn-ghost">{hero.tertiaryCta.label} →</Link>
            </div>

            <dl className="hero__trust">
              <div>
                <dt className="visually-hidden">Ranges</dt>
                <dd><strong>{products.length || 5}</strong>mattress ranges</dd>
              </div>
              <div>
                <dt className="visually-hidden">Sizes</dt>
                <dd><strong>5</strong>standard sizes</dd>
              </div>
              <div>
                <dt className="visually-hidden">Warranty</dt>
                <dd><strong>Up to 12 yrs</strong>warranty term</dd>
              </div>
              {lowestPrice !== null ? (
                <div>
                  <dt className="visually-hidden">Starting price</dt>
                  <dd><strong>{formatPrice(lowestPrice)}</strong>starting from</dd>
                </div>
              ) : null}
            </dl>
          </div>

          <div className="hero__media">
            {/* The largest element on first paint, so it is fetched with
                priority and never lazy-loaded — this is the LCP image. */}
            <Image
              src={hero.image.src}
              alt={hero.image.alt}
              width={hero.image.width}
              height={hero.image.height}
              priority
              fetchPriority="high"
              sizes="(max-width: 62rem) 100vw, 50vw"
              style={{ width: '100%', height: 'auto' }}
            />
          </div>
        </div>
      </section>

      {/* ---------- 2. why this brand ---------- */}
      <section className="section section-sand reveal" aria-labelledby="why-heading">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">Why {BRAND.shortName}</p>
            <h2 id="why-heading">Four things you can check for yourself</h2>
          </div>

          <div className="grid grid-4">
            {benefits.map((benefit) => {
              const Icon = ICONS[benefit.icon ?? 'comfort'] ?? ICONS.comfort!;
              return (
                <div className="benefit" key={benefit.title}>
                  <span className="benefit__icon"><Icon /></span>
                  <h3>{benefit.title}</h3>
                  <p>{benefit.body}</p>
                </div>
              );
            })}
          </div>

          <p className="mt-7">
            <Link href="/why-polyfix" className="btn btn-secondary">See what sits behind each of these</Link>
          </p>
        </div>
      </section>

      {/* ---------- 3. featured range ---------- */}
      <section className="section reveal" aria-labelledby="range-heading">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">The range</p>
            <h2 id="range-heading">Five constructions, firm through medium-soft</h2>
            <p className="lede">
              Each mattress is specified by material, density and firmness rating, so you can compare
              them on the same terms.
            </p>
          </div>

          {showcase.length > 0 ? (
            <div className="grid grid-3">
              {showcase.map((product, index) => (
                <ProductCard key={product.slug} product={product} priority={index === 0} />
              ))}
            </div>
          ) : (
            <p className="muted">The catalogue is being updated. Please check back shortly.</p>
          )}

          <p className="mt-7">
            <Link href="/mattresses" className="btn btn-primary">Compare all mattresses</Link>
          </p>
        </div>
      </section>

      {/* ---------- 4. craftsmanship ---------- */}
      <section className="section section-ink reveal" aria-labelledby="craft-heading">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">Manufacturing</p>
            <h2 id="craft-heading">{craftsmanship?.heading ?? `How a ${BRAND.shortName} mattress is made`}</h2>
            <p className="lede">
              {craftsmanship?.body ??
                'Foam is cut to specification, comfort layers are bonded, covers are quilted and stitched, and each finished unit is inspected before it is assigned a serial number.'}
            </p>
          </div>

          <ol className="grid grid-4" style={{ listStyle: 'none', padding: 0, counterReset: 'step' }}>
            {((craftsmanship?.steps as { title: string; body: string }[] | undefined) ?? [
              { title: 'Material intake', body: 'Foam, spring units and fabric checked against specification on arrival.' },
              { title: 'Build', body: 'Layers cut, bonded and assembled to the documented construction for that model.' },
              { title: 'Quilting and finishing', body: 'Covers quilted, panels stitched and edges tape-bound.' },
              { title: 'Inspection and serialisation', body: `Each unit inspected, then assigned its permanent ${BRAND.shortName} serial number and QR label.` },
            ]).map((step, index) => (
              <li className="step" key={step.title} style={{ borderTopColor: '#8a6a3d' }}>
                <span className="step__number">{String(index + 1).padStart(2, '0')}</span>
                <h3 style={{ fontSize: 'var(--step-1)' }}>{step.title}</h3>
                <p style={{ color: '#b8aea2', fontSize: 'var(--step--1)' }}>{step.body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* ---------- 5. verification ---------- */}
      <section className="section reveal" aria-labelledby="verify-heading">
        <div className="container hero__grid">
          <div className="stack">
            <p className="eyebrow">Authentication</p>
            <h2 id="verify-heading">{verification?.heading ?? 'Every mattress can be checked'}</h2>
            <p className="lede">
              {verification?.body ??
                'Scan the QR label or type the serial number. You will see whether the product is genuine, what it is, when it was made and whether the warranty is still running.'}
            </p>
            <p className="muted" style={{ fontSize: 'var(--step--1)' }}>
              Nothing about the customer or the dealer is shown, to anyone, on the public page.
            </p>
            <div className="cluster mt-6">
              <Link href="/warranty" className="btn btn-primary">Verify your mattress</Link>
              <Link href="/warranty#cover" className="btn btn-ghost">What the warranty covers →</Link>
            </div>
          </div>

          <div className="card" style={{ background: 'var(--sand)' }}>
            <p className="eyebrow">Example result</p>
            <div className="stack" style={{ marginTop: '1rem' }}>
              <span className="badge badge-positive">Genuine {BRAND.shortName} product</span>
              <dl className="spec-table" style={{ display: 'block' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.75rem 0', borderBottom: '1px solid var(--line)' }}>
                  <dt className="muted">Serial number</dt><dd style={{ fontWeight: 600 }}>CLF26000001</dd>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.75rem 0', borderBottom: '1px solid var(--line)' }}>
                  <dt className="muted">Product</dt><dd style={{ fontWeight: 600 }}>Aurea Hybrid, Queen</dd>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.75rem 0', borderBottom: '1px solid var(--line)' }}>
                  <dt className="muted">Manufactured</dt><dd style={{ fontWeight: 600 }}>March 2026</dd>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0.75rem 0' }}>
                  <dt className="muted">Warranty</dt><dd style={{ fontWeight: 600, color: 'var(--positive)' }}>Active</dd>
                </div>
              </dl>
            </div>
          </div>
        </div>
      </section>

      {/* ---------- 6. dealer network ---------- */}
      <section className="section section-sand reveal" aria-labelledby="dealers-heading">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">Dealer network</p>
            <h2 id="dealers-heading">{dealersSection?.heading ?? `Find a ${BRAND.shortName} dealer`}</h2>
            <p className="lede">
              {dealersSection?.body ??
                'Our mattresses are sold through a network of appointed dealers who hold stock and handle warranty support. Firmness is personal — try before you decide.'}
            </p>
          </div>
          <div className="cluster">
            <Link href="/dealers" className="btn btn-primary">Find a dealer near you</Link>
            <Link href="/dealers#apply" className="btn btn-secondary">Become a dealer</Link>
          </div>
        </div>
      </section>

      {/* ---------- FAQs ---------- */}
      {homeFaqs.length > 0 ? (
        <div className="container-narrow">
          <FaqList faqs={homeFaqs} heading="Common questions" />
          <JsonLd schema={faqSchema(homeFaqs)} />
        </div>
      ) : null}

      {/* ---------- 7. closing ---------- */}
      <section className="section section-tight reveal">
        <div className="container">
          <div className="card text-center" style={{ padding: 'var(--space-8) var(--space-6)' }}>
            <h2>{closing?.heading ?? 'Not sure which mattress suits you?'}</h2>
            <p className="lede mx-auto mt-6" style={{ textAlign: 'center' }}>
              {closing?.body ??
                'Compare the range by construction and firmness rating, or visit a dealer and try them.'}
            </p>
            <div className="cluster mt-6" style={{ justifyContent: 'center' }}>
              <Link href="/mattresses" className="btn btn-primary">Compare mattresses</Link>
              <Link href="/contact" className="btn btn-secondary">Talk to us</Link>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
