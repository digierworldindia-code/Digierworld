import type { Metadata } from 'next';
import Link from 'next/link';
import { getPage, getSite } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { VerifyForm } from '@/components/verify-form';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { FaqList } from '@/components/faq-list';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema, faqSchema } from '@/lib/structured-data';
import { BRAND } from '@polyfix/brand';

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  const page = await getPage('warranty');
  return buildMetadata({
    seo: page?.seo ?? null,
    fallbackTitle: 'Mattress Warranty & Serial Number Verification',
    fallbackDescription:
      `Verify a ${BRAND.shortName} mattress by QR code or serial number. See the product, manufacturing date and warranty status, and learn how to raise a warranty claim.`,
    path: '/warranty',
  });
}

const TRAIL = [
  { name: 'Home', path: '/' },
  { name: 'Warranty', path: '/warranty' },
];

export default async function WarrantyPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>;
}) {
  const [{ q }, site] = await Promise.all([searchParams, getSite()]);
  const warrantyFaqs = site.faqs.filter((faq) => faq.category === 'warranty' || faq.category === 'authenticity');

  // A QR scan arrives as ?q=<token>. It is passed straight to the form, which
  // submits it to the API — the token is never trusted for anything beyond
  // being a lookup key, and the API returns only public fields.
  const token = typeof q === 'string' && /^[A-Za-z0-9_-]{22,64}$/.test(q) ? q : undefined;

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={TRAIL} />
      </div>

      <section className="section-tight">
        <div className="container hero__grid">
          <div className="stack">
            <p className="eyebrow">Warranty</p>
            <h1>Verify your {BRAND.shortName} mattress</h1>
            <p className="lede">
              Scan the QR code on the label, or type the serial number printed beside it. You will see
              whether the product is genuine, what it is, when it was made and whether the warranty is
              still running.
            </p>
            <p className="muted" style={{ fontSize: 'var(--step--1)' }}>
              This check shows product and warranty information only. It never displays customer
              details, dealer information or claim history.
            </p>
          </div>

          <VerifyForm initialToken={token} />
        </div>
      </section>

      <section className="section section-sand" id="cover">
        <div className="container">
          <div className="section-head">
            <h2>What the warranty covers</h2>
          </div>

          <div className="grid grid-2">
            <div className="card stack">
              <span className="badge badge-positive">Covered</span>
              <h3>Manufacturing defects</h3>
              <ul className="stack" style={{ paddingLeft: '1.1rem', color: 'var(--ink-muted)' }}>
                <li>Foam that loses height beyond the stated tolerance under normal use</li>
                <li>Spring failure or displacement</li>
                <li>Stitching or seam separation not caused by misuse</li>
                <li>Cover splitting at a seam</li>
              </ul>
              <p className="muted" style={{ fontSize: 'var(--step--1)' }}>
                The term runs from the date of sale recorded by your dealer, not from the
                manufacturing date.
              </p>
            </div>

            <div className="card stack">
              <span className="badge badge-neutral">Not covered</span>
              <ul className="stack" style={{ paddingLeft: '1.1rem', color: 'var(--ink-muted)' }}>
                <li>Normal softening and settling over time</li>
                <li>Comfort preference, or choosing a firmness that does not suit you</li>
                <li>Stains, burns, tears and general wear</li>
                <li>Damage from an unsuitable, sagging or unsupportive base</li>
                <li>Damage in transit after delivery</li>
                <li>Any mattress whose law label has been removed</li>
                <li>Any mattress bought from someone other than an appointed {BRAND.shortName} dealer</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <section className="section" id="claim">
        <div className="container">
          <div className="section-head">
            <h2>How to raise a claim</h2>
            <p className="lede">
              Claims are raised by your selling dealer against your mattress serial number. That keeps
              one accountable business involved from beginning to end.
            </p>
          </div>

          <ol className="grid grid-4" style={{ listStyle: 'none', padding: 0 }}>
            {[
              { title: 'Contact your dealer', body: 'The dealer you bought from raises the claim against your serial number.' },
              { title: 'Photographs', body: 'They upload photographs showing the issue and the law label.' },
              { title: 'Review', body: `The ${BRAND.shortName} warranty team reviews the claim, the mattress record and the photographs.` },
              { title: 'Outcome', body: 'You are told the decision. If approved, a replacement is issued and linked to the original record.' },
            ].map((step, index) => (
              <li className="step" key={step.title}>
                <span className="step__number">{String(index + 1).padStart(2, '0')}</span>
                <h3 style={{ fontSize: 'var(--step-1)' }}>{step.title}</h3>
                <p className="muted" style={{ fontSize: 'var(--step--1)' }}>{step.body}</p>
              </li>
            ))}
          </ol>

          <div className="notice notice-neutral mt-7" style={{ maxWidth: 'var(--measure)' }}>
            <strong>Keep the law label.</strong> It carries the serial number, and it is what makes
            the warranty verifiable. A mattress without its label cannot be matched to our records.
          </div>

          <p className="mt-6">
            <Link href="/dealers" className="btn btn-secondary">Find your dealer</Link>
          </p>
        </div>
      </section>

      {warrantyFaqs.length > 0 ? (
        <div className="container-narrow">
          <FaqList faqs={warrantyFaqs} heading="Warranty questions" />
          <JsonLd schema={faqSchema(warrantyFaqs)} />
        </div>
      ) : null}

      <JsonLd schema={breadcrumbSchema(TRAIL)} />
    </>
  );
}
