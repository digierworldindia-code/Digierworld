import Link from 'next/link';
import { SITE } from '@/lib/site';
import { BRAND } from '@polyfix/brand';

interface FooterProps {
  settings: Record<string, unknown>;
}

function text(settings: Record<string, unknown>, key: string): string | null {
  const value = settings[key];
  return typeof value === 'string' && value.trim().length > 0 ? value : null;
}

/**
 * Footer.
 *
 * Contact details and social links come from the CMS, so an administrator can
 * change a phone number without a deploy. A social link that has not been set
 * renders nothing at all rather than a dead icon.
 */
export function SiteFooter({ settings }: FooterProps) {
  const phone = text(settings, 'company.support_phone');
  const email = text(settings, 'company.support_email');
  const address = text(settings, 'company.address');

  const socials = [
    { key: 'social.instagram', label: 'Instagram' },
    { key: 'social.facebook', label: 'Facebook' },
    { key: 'social.linkedin', label: 'LinkedIn' },
    { key: 'social.youtube', label: 'YouTube' },
  ]
    .map((social) => ({ ...social, href: text(settings, social.key) }))
    .filter((social): social is { key: string; label: string; href: string } => social.href !== null);

  return (
    <footer className="site-footer">
      <div className="container">
        <div className="footer-grid">
          <div>
            <Link href="/" className="wordmark" style={{ color: '#fbf9f5' }}>
              {BRAND.wordmark.lead}<span>{BRAND.wordmark.trail}</span>
            </Link>
            <p style={{ marginTop: '1rem', fontSize: 'var(--step--1)', maxWidth: '24ch' }}>
              Premium mattresses, serialised and traceable from the production line to your bedroom.
            </p>
          </div>

          <div>
            <h4>Mattresses</h4>
            <ul>
              <li><Link href="/mattresses">All mattresses</Link></li>
              <li><Link href="/mattresses/aurea-hybrid">Hybrid</Link></li>
              <li><Link href="/mattresses/serenity-memory-foam">Memory foam</Link></li>
              <li><Link href="/mattresses/latex-natura">Natural latex</Link></li>
              <li><Link href="/mattresses/orthocore-support">Orthopaedic</Link></li>
            </ul>
          </div>

          <div>
            <h4>Ownership</h4>
            <ul>
              <li><Link href="/warranty">Verify your mattress</Link></li>
              <li><Link href="/warranty#cover">What the warranty covers</Link></li>
              <li><Link href="/warranty#claim">Raise a claim</Link></li>
              <li><Link href="/dealers">Find a dealer</Link></li>
            </ul>
          </div>

          <div>
            <h4>Company</h4>
            <ul>
              <li><Link href="/about">About {BRAND.shortName}</Link></li>
              <li><Link href="/why-polyfix">Why {BRAND.shortName}</Link></li>
              <li><Link href="/dealers#apply">Become a dealer</Link></li>
              <li><Link href="/contact">Contact</Link></li>
              <li>
                {/* The private console is a separate application on its own
                    subdomain, and search engines are told to stay out of it. */}
                <a href={SITE.appUrl} rel="nofollow noopener">Dealer &amp; staff login</a>
              </li>
            </ul>
          </div>

          <div>
            <h4>Contact</h4>
            <ul>
              {phone && <li><a href={`tel:${phone.replace(/[^\d+]/g, '')}`}>{phone}</a></li>}
              {email && <li><a href={`mailto:${email}`}>{email}</a></li>}
              {/* Registered company location. The CMS value wins when an
                  administrator has set one; the brand module is the fallback so
                  the footer is never blank on a fresh install. */}
              <li style={{ color: '#9c9184' }}>{address ?? BRAND.location.line}</li>
            </ul>
            {socials.length > 0 && (
              <ul style={{ marginTop: '1rem' }}>
                {socials.map((social) => (
                  <li key={social.key}>
                    <a href={social.href} rel="noopener noreferrer" target="_blank">{social.label}</a>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        <div className="footer-bottom">
          <p>© {new Date().getFullYear()} {SITE.name}. All rights reserved.</p>
          <nav aria-label="Legal">
            <ul style={{ display: 'flex', gap: '1.5rem', listStyle: 'none', padding: 0 }}>
              <li><Link href="/privacy">Privacy</Link></li>
              <li><Link href="/terms">Terms</Link></li>
              <li><Link href="/warranty">Warranty terms</Link></li>
            </ul>
          </nav>
        </div>
      </div>
    </footer>
  );
}
