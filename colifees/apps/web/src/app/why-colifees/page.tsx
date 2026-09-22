import type { Metadata } from 'next';
import Link from 'next/link';
import { getPage } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema } from '@/lib/structured-data';

export const revalidate = 600;

export async function generateMetadata(): Promise<Metadata> {
  const page = await getPage('why-colifees');
  return buildMetadata({
    seo: page?.seo ?? null,
    fallbackTitle: 'Why Choose COLIFEES Mattresses',
    fallbackDescription:
      'Published material specifications, serial-number traceability, online warranty verification and an accountable dealer behind every COLIFEES mattress.',
    path: '/why-colifees',
  });
}

const TRAIL = [
  { name: 'Home', path: '/' },
  { name: 'Why COLIFEES', path: '/why-colifees' },
];

const FALLBACK_REASONS = [
  {
    title: 'Published specifications',
    body: 'Foam density in kg/m³, spring count, layer thickness and cover fabric are listed on every product page. You can compare them against any other mattress.',
  },
  {
    title: 'Serial-number traceability',
    body: 'Each mattress is assigned a permanent COLIFEES serial at the end of the line. Manufacturing batch, dispatch, dealer, sale date and warranty all attach to it.',
  },
  {
    title: 'Warranty you can verify without asking',
    body: 'Scan the QR label or enter the serial. The result shows product, manufacturing date and warranty status. No login, no phone call.',
  },
  {
    title: 'An accountable dealer',
    body: 'Every sale is recorded against a named dealer, so there is always a business that can answer for the mattress.',
  },
];

export default async function WhyPage() {
  const page = await getPage('why-colifees');
  const sections = (page?.page.sections ?? []) as Record<string, any>[];
  const reasons =
    (sections.find((section) => section.type === 'reasons')?.items as typeof FALLBACK_REASONS | undefined) ??
    FALLBACK_REASONS;

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={TRAIL} />
      </div>

      <section className="section-tight">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">Why COLIFEES</p>
            <h1>Four things we do differently</h1>
            <p className="lede">Not slogans. Things you can check.</p>
          </div>

          <ol className="stack-lg" style={{ listStyle: 'none', padding: 0 }}>
            {reasons.map((reason, index) => (
              <li key={reason.title} className="card">
                <div className="cluster" style={{ alignItems: 'flex-start', gap: 'var(--space-5)' }}>
                  <span className="step__number" style={{ fontSize: 'var(--step-3)' }}>
                    {String(index + 1).padStart(2, '0')}
                  </span>
                  <div className="stack" style={{ flex: '1 1 20rem' }}>
                    <h2 style={{ fontSize: 'var(--step-2)' }}>{reason.title}</h2>
                    <p className="muted">{reason.body}</p>
                  </div>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section section-ink">
        <div className="container text-center stack">
          <h2>What we will not claim</h2>
          <p className="lede mx-auto" style={{ textAlign: 'center' }}>
            You will not find a ranking, a star rating or an award on this site. We publish what the
            mattress is made of, what it costs and how long it is covered for. The rest is for you to
            judge, preferably lying down on one.
          </p>
          <div className="cluster mt-6" style={{ justifyContent: 'center' }}>
            <Link href="/mattresses" className="btn btn-on-ink">Compare the range</Link>
            <Link href="/dealers" className="btn btn-ghost" style={{ color: '#d8d0c5' }}>Find a dealer →</Link>
          </div>
        </div>
      </section>

      <JsonLd schema={breadcrumbSchema(TRAIL)} />
    </>
  );
}
