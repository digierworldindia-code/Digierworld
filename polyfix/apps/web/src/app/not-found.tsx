import Link from 'next/link';
import type { Metadata } from 'next';
import { noIndexMetadata } from '@/lib/seo';

export const metadata: Metadata = noIndexMetadata(
  'Page not found',
  'The page you were looking for does not exist.',
);

/**
 * 404.
 *
 * Useful rather than decorative: the four destinations below cover almost every
 * reason someone arrives here, so a broken link becomes a detour instead of a
 * dead end.
 */
export default function NotFound() {
  return (
    <section className="section">
      <div className="container-narrow text-center stack-lg">
        <p className="eyebrow">404</p>
        <h1>We could not find that page</h1>
        <p className="lede mx-auto" style={{ textAlign: 'center' }}>
          The address may have changed, or the link that brought you here may be out of date.
        </p>

        <div className="grid grid-2 mt-7" style={{ textAlign: 'left' }}>
          <Link href="/mattresses" className="card-link">
            <div className="card stack">
              <h2 style={{ fontSize: 'var(--step-1)' }}>Browse mattresses</h2>
              <p className="muted">Five constructions, five sizes, full specifications.</p>
            </div>
          </Link>
          <Link href="/warranty" className="card-link">
            <div className="card stack">
              <h2 style={{ fontSize: 'var(--step-1)' }}>Verify a mattress</h2>
              <p className="muted">Check a serial number and its warranty status.</p>
            </div>
          </Link>
          <Link href="/dealers" className="card-link">
            <div className="card stack">
              <h2 style={{ fontSize: 'var(--step-1)' }}>Find a dealer</h2>
              <p className="muted">Appointed dealers who hold stock and handle support.</p>
            </div>
          </Link>
          <Link href="/contact" className="card-link">
            <div className="card stack">
              <h2 style={{ fontSize: 'var(--step-1)' }}>Contact us</h2>
              <p className="muted">Tell us what you were looking for and we will help.</p>
            </div>
          </Link>
        </div>
      </div>
    </section>
  );
}
