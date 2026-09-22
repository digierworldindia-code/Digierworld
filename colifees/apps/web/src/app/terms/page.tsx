import type { Metadata } from 'next';
import Link from 'next/link';
import { buildMetadata } from '@/lib/seo';
import { Breadcrumbs } from '@/components/breadcrumbs';

export const metadata: Metadata = buildMetadata({
  seo: null,
  fallbackTitle: 'Terms of Use',
  fallbackDescription: 'Terms governing the use of the COLIFEES website.',
  path: '/terms',
});

export default function TermsPage() {
  return (
    <>
      <div className="container">
        <Breadcrumbs trail={[{ name: 'Home', path: '/' }, { name: 'Terms', path: '/terms' }]} />
      </div>

      <section className="section-tight">
        <div className="container-narrow stack-lg">
          <h1>Terms of use</h1>

          <div className="notice notice-caution">
            <strong>Before launch:</strong> have these reviewed by your legal adviser and add your
            registered entity name, address and jurisdiction.
          </div>

          <div className="stack">
            <h2>Product information</h2>
            <p className="muted">
              Specifications, sizes and recommended retail prices on this site are provided in good
              faith and may change. Your dealer sets the final selling price. Where a specification
              here differs from the label on the product you received, the label governs.
            </p>
          </div>

          <div className="stack">
            <h2>Warranty</h2>
            <p className="muted">
              The warranty term for each product is stated on its page and runs from the date of sale
              recorded by your dealer. What the warranty covers, and what it does not, is set out on
              the <Link href="/warranty">warranty page</Link>. A mattress bought from someone other
              than an appointed COLIFEES dealer is not covered.
            </p>
          </div>

          <div className="stack">
            <h2>Acceptable use</h2>
            <p className="muted">
              Do not use this site to submit false information, to attempt to gain access to accounts
              or systems you are not authorised to use, or to place automated load on the site. The
              verification service is provided for checking mattresses you own or are considering.
            </p>
          </div>

          <div className="stack">
            <h2>Dealer accounts</h2>
            <p className="muted">
              The dealer portal is for appointed dealers. Credentials are personal and must not be
              shared. Activity in the portal is logged.
            </p>
          </div>
        </div>
      </section>
    </>
  );
}
