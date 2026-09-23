import type { Metadata } from 'next';
import Link from 'next/link';
import { getDealerRegions, getDealers, getPage } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { DealerApplicationForm } from '@/components/dealer-application-form';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema, dealerListSchema } from '@/lib/structured-data';
import { IconPin } from '@/components/icons';
import { BRAND } from '@polyfix/brand';

export const revalidate = 600;

export async function generateMetadata(): Promise<Metadata> {
  const page = await getPage('dealers');
  return buildMetadata({
    seo: page?.seo ?? null,
    fallbackTitle: `${BRAND.name} Dealers — Find a Mattress Showroom Near You`,
    fallbackDescription:
      `Find an appointed ${BRAND.shortName} mattress dealer or showroom near you, or apply to become a ${BRAND.shortName} dealer.`,
    path: '/dealers',
  });
}

const TRAIL = [
  { name: 'Home', path: '/' },
  { name: 'Dealers', path: '/dealers' },
];

export default async function DealersPage({
  searchParams,
}: {
  searchParams: Promise<{ state?: string; city?: string }>;
}) {
  const params = await searchParams;

  // Filter values are bounded and encoded before they reach the API, which
  // validates them again. The page never interpolates them into anything.
  const state = typeof params.state === 'string' ? params.state.slice(0, 80) : '';
  const city = typeof params.city === 'string' ? params.city.slice(0, 80) : '';
  const query = new URLSearchParams();
  if (state) query.set('state', state);
  if (city) query.set('city', city);
  const suffix = query.toString() ? `?${query.toString()}` : '';

  const [{ dealers }, { regions }] = await Promise.all([getDealers(suffix), getDealerRegions()]);
  const states = [...new Set(regions.map((region) => region.state))].sort();

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={TRAIL} />
      </div>

      <section className="section-tight">
        <div className="container">
          <div className="section-head">
            <p className="eyebrow">Dealer network</p>
            <h1>Find a {BRAND.shortName} dealer</h1>
            <p className="lede">
              Our mattresses are sold through appointed dealers who hold stock, record your sale
              against the mattress serial number, and handle warranty support.
            </p>
          </div>

          {/* A plain GET form: filtering works with JavaScript disabled, the
              result is a shareable URL, and there is no client-side state. */}
          <form method="get" className="card" style={{ display: 'grid', gap: 'var(--space-4)' }}>
            <div className="form-grid form-grid-2">
              <div className="field">
                <label htmlFor="state-filter">State</label>
                <select id="state-filter" name="state" className="select" defaultValue={state}>
                  <option value="">All states</option>
                  {states.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="city-filter">City</label>
                <input id="city-filter" name="city" className="input" defaultValue={city} maxLength={80} placeholder="Jaipur" />
              </div>
            </div>
            <div className="cluster">
              <button type="submit" className="btn btn-primary">Search dealers</button>
              {(state || city) && <Link href="/dealers" className="btn btn-ghost">Clear</Link>}
            </div>
          </form>

          <div className="mt-7">
            {dealers.length === 0 ? (
              <div className="notice notice-neutral">
                <p style={{ fontWeight: 600 }}>No listed dealers match that search.</p>
                <p style={{ marginTop: '0.5rem' }}>
                  Our network is growing. <Link href="/contact">Tell us where you are</Link> and we
                  will point you to the nearest option.
                </p>
              </div>
            ) : (
              <>
                <p className="muted" style={{ marginBottom: 'var(--space-5)' }}>
                  {dealers.length} dealer{dealers.length === 1 ? '' : 's'}
                  {state ? ` in ${state}` : ''}{city ? `, ${city}` : ''}
                </p>
                <div className="grid grid-3">
                  {dealers.map((dealer) => (
                    <div className="dealer-card" key={`${dealer.businessName}-${dealer.pincode}`}>
                      <div className="cluster" style={{ gap: 'var(--space-2)' }}>
                        <span style={{ color: 'var(--brass)' }}><IconPin /></span>
                        {dealer.isShowroom ? <span className="badge badge-neutral">Showroom</span> : null}
                      </div>
                      <h3>{dealer.businessName}</h3>
                      <address>
                        {dealer.address}
                        <br />
                        {dealer.city}, {dealer.state} {dealer.pincode}
                      </address>
                      <p>
                        <a href={`tel:${dealer.phone.replace(/[^\d+]/g, '')}`} className="btn btn-secondary" style={{ marginTop: '0.5rem' }}>
                          Call {dealer.phone}
                        </a>
                      </p>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        </div>
      </section>

      <section className="section section-sand" id="apply">
        <div className="container hero__grid">
          <div className="stack">
            <p className="eyebrow">Partnership</p>
            <h2>Become a {BRAND.shortName} dealer</h2>
            <p className="lede">
              If you run a furniture or bedding retail business and want to carry {BRAND.shortName}, send us
              your details. Our dealer development team reviews every application.
            </p>

            <div className="stack mt-6">
              <h3 style={{ fontSize: 'var(--step-1)' }}>What you get</h3>
              <ul className="stack" style={{ paddingLeft: '1.1rem', color: 'var(--ink-muted)' }}>
                <li>A dealer portal for stock, sales and warranty claims, built for a phone</li>
                <li>Serial-level traceability on every unit you receive and sell</li>
                <li>Warranty claims handled through one system, with a documented decision</li>
                <li>A listing on this page, if you choose to be listed</li>
              </ul>
            </div>
          </div>

          <DealerApplicationForm />
        </div>
      </section>

      <JsonLd schema={breadcrumbSchema(TRAIL)} />
      <JsonLd schema={dealerListSchema(dealers)} />
    </>
  );
}
