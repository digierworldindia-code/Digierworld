import Link from 'next/link';
import { NAV, SITE } from '@/lib/site';

/**
 * Site header.
 *
 * A server component with no client JavaScript. The mobile menu is a native
 * `<details>` element, so it opens, closes, traps nothing, and works with a
 * keyboard and a screen reader before any script has loaded.
 */
export function SiteHeader() {
  return (
    <header className="site-header">
      <div className="container site-header__inner">
        <Link href="/" className="wordmark" aria-label={`${SITE.name} home`}>
          COLI<span>FEES</span>
        </Link>

        <nav className="site-nav" aria-label="Primary">
          <ul>
            {NAV.filter((item) => item.href !== '/').map((item) => (
              <li key={item.href}>
                <Link href={item.href}>{item.label}</Link>
              </li>
            ))}
          </ul>
        </nav>

        <div className="header-actions">
          <Link href="/warranty" className="btn btn-secondary">
            Verify<span className="label-long">&nbsp;mattress</span>
          </Link>

          <details className="nav-mobile">
            <summary aria-label="Open menu">
              <svg width="20" height="14" viewBox="0 0 20 14" fill="none" aria-hidden="true">
                <path d="M0 1h20M0 7h20M0 13h20" stroke="currentColor" strokeWidth="1.6" />
              </svg>
            </summary>
            <div className="nav-mobile__panel">
              <nav aria-label="Primary, mobile">
                <ul>
                  {NAV.map((item) => (
                    <li key={item.href}>
                      <Link href={item.href}>{item.label}</Link>
                    </li>
                  ))}
                </ul>
              </nav>
            </div>
          </details>
        </div>
      </div>
    </header>
  );
}
