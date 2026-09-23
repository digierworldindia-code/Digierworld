import type { Metadata } from 'next';
import Link from 'next/link';
import { getPage, getSite } from '@/lib/api';
import { buildMetadata } from '@/lib/seo';
import { ContactForm } from '@/components/contact-form';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { JsonLd } from '@/components/json-ld';
import { breadcrumbSchema } from '@/lib/structured-data';
import { absoluteUrl } from '@/lib/site';
import { BRAND } from '@polyfix/brand';

export const revalidate = 600;

export async function generateMetadata(): Promise<Metadata> {
  const page = await getPage('contact');
  return buildMetadata({
    seo: page?.seo ?? null,
    fallbackTitle: `Contact ${BRAND.name} — Mattress Enquiries & Support`,
    fallbackDescription: `Contact ${BRAND.name} for product questions, dealership enquiries or warranty support.`,
    path: '/contact',
  });
}

const TRAIL = [
  { name: 'Home', path: '/' },
  { name: 'Contact', path: '/contact' },
];

function setting(settings: Record<string, unknown>, key: string): string | null {
  const value = settings[key];
  return typeof value === 'string' && value.trim().length > 0 ? value : null;
}

export default async function ContactPage() {
  const site = await getSite();
  const phone = setting(site.settings, 'company.support_phone');
  const email = setting(site.settings, 'company.support_email');
  const address = setting(site.settings, 'company.address');

  return (
    <>
      <div className="container">
        <Breadcrumbs trail={TRAIL} />
      </div>

      <section className="section-tight">
        <div className="container hero__grid">
          <div className="stack">
            <p className="eyebrow">Contact</p>
            <h1>Talk to {BRAND.shortName}</h1>
            <p className="lede">
              Product questions, dealership enquiries and warranty support. Tell us what you need and
              the right person will reply.
            </p>

            <div className="stack-lg mt-7">
              {phone ? (
                <div>
                  <h3 style={{ fontSize: 'var(--step-1)' }}>Phone</h3>
                  <p><a href={`tel:${phone.replace(/[^\d+]/g, '')}`}>{phone}</a></p>
                </div>
              ) : null}

              {email ? (
                <div>
                  <h3 style={{ fontSize: 'var(--step-1)' }}>Email</h3>
                  <p><a href={`mailto:${email}`}>{email}</a></p>
                </div>
              ) : null}

              {address ? (
                <div>
                  <h3 style={{ fontSize: 'var(--step-1)' }}>Address</h3>
                  <address style={{ fontStyle: 'normal', color: 'var(--ink-muted)' }}>{address}</address>
                </div>
              ) : null}

              <div className="notice notice-neutral">
                <p style={{ fontWeight: 600 }}>Warranty question about a mattress you own?</p>
                <p style={{ marginTop: '0.4rem' }}>
                  Start by <Link href="/warranty">checking the serial number</Link>. Claims are raised
                  by the dealer you bought from.
                </p>
              </div>
            </div>
          </div>

          <div>
            <h2 style={{ fontSize: 'var(--step-2)', marginBottom: 'var(--space-5)' }}>Send us a message</h2>
            <ContactForm />
          </div>
        </div>
      </section>

      <JsonLd schema={breadcrumbSchema(TRAIL)} />
      <JsonLd
        schema={{
          '@context': 'https://schema.org',
          '@type': 'ContactPage',
          name: `Contact ${BRAND.name}`,
          url: absoluteUrl('/contact'),
        }}
      />
    </>
  );
}
