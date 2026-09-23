'use client';

import Link from 'next/link';
import { useEffect } from 'react';

/**
 * Error boundary.
 *
 * The visitor gets a plain apology and a way forward. The detail goes to the
 * console for the developer and, in production, to whatever error reporter is
 * configured — never onto the page, where it would expose internals.
 */
export default function Error({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error('page render failed', error);
  }, [error]);

  return (
    <section className="section">
      <div className="container-narrow text-center stack-lg">
        <p className="eyebrow">Something went wrong</p>
        <h1>This page did not load</h1>
        <p className="lede mx-auto" style={{ textAlign: 'center' }}>
          Sorry about that. Try again in a moment, and if it keeps happening, let us know.
        </p>
        {error.digest ? (
          <p className="muted" style={{ fontSize: 'var(--step--1)' }}>
            Reference: {error.digest}
          </p>
        ) : null}
        <div className="cluster mt-6" style={{ justifyContent: 'center' }}>
          <button type="button" className="btn btn-primary" onClick={reset}>Try again</button>
          <Link href="/" className="btn btn-secondary">Go to the homepage</Link>
          <Link href="/contact" className="btn btn-ghost">Contact us →</Link>
        </div>
      </div>
    </section>
  );
}
