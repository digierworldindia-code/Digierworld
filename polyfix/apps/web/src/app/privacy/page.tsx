import type { Metadata } from 'next';
import { buildMetadata } from '@/lib/seo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { BRAND } from '@polyfix/brand';

export const metadata: Metadata = buildMetadata({
  seo: null,
  fallbackTitle: 'Privacy Policy',
  fallbackDescription: `How ${BRAND.name} collects, uses and protects personal information.`,
  path: '/privacy',
});

/**
 * Privacy policy.
 *
 * This is a factual description of what the platform actually does with data,
 * written from the schema and the code rather than copied from a template.
 * The business should have it reviewed by a lawyer before launch and update the
 * grievance-officer details.
 */
export default function PrivacyPage() {
  return (
    <>
      <div className="container">
        <Breadcrumbs trail={[{ name: 'Home', path: '/' }, { name: 'Privacy', path: '/privacy' }]} />
      </div>

      <section className="section-tight">
        <div className="container-narrow stack-lg">
          <h1>Privacy policy</h1>
          <p className="muted">
            This policy describes what this website and the {BRAND.shortName} platform collect, why, and how
            long it is kept. It is written to match what the system does.
          </p>

          <div className="notice notice-caution">
            <strong>Before launch:</strong> have this reviewed against the Digital Personal Data
            Protection Act and add your grievance officer&rsquo;s name and contact details.
          </div>

          <div className="stack">
            <h2>What this website collects</h2>
            <p className="muted">
              If you send an enquiry or apply for a dealership, we store the details you enter: your
              name, phone number, email address, city and your message. We also store a one-way hash
              of your IP address and your browser&rsquo;s user-agent string, to limit automated abuse
              of the forms. We do not store the IP address itself.
            </p>
            <p className="muted">
              If analytics is enabled, Google Analytics 4 is loaded with IP anonymisation on and
              advertising signals off. If no measurement ID is configured, no analytics script is
              loaded at all.
            </p>
          </div>

          <div className="stack">
            <h2>Warranty verification</h2>
            <p className="muted">
              Checking a serial number does not require an account and does not identify you. The
              result shows the product, its manufacturing date and its warranty status. It never
              shows the customer, the dealer, the price or any claim history.
            </p>
          </div>

          <div className="stack">
            <h2>What your dealer records</h2>
            <p className="muted">
              When you buy a {BRAND.shortName} mattress, your dealer records the sale against the
              mattress&rsquo;s serial number, along with your name and contact details, so that the
              warranty can be honoured. Contact details are encrypted in our database. Your dealer
              can see their own customer records; other dealers cannot.
            </p>
          </div>

          <div className="stack">
            <h2>How long we keep it</h2>
            <p className="muted">
              Enquiries are kept while they are being handled and for a reasonable period afterwards.
              Sale and warranty records are kept for the life of the warranty and for as long as we
              are required to retain them, because they are the evidence behind a warranty
              obligation.
            </p>
          </div>

          <div className="stack">
            <h2>Your rights</h2>
            <p className="muted">
              You can ask what we hold about you, ask for it to be corrected, and ask for it to be
              deleted where we are not required to keep it. Write to the support address in the
              footer and we will respond.
            </p>
          </div>
        </div>
      </section>
    </>
  );
}
