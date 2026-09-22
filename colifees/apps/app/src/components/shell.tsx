'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useState } from 'react';
import { useSession } from '@/lib/session';
import { post } from '@/lib/api-client';

/**
 * Console shell: sidebar for staff, bottom tab bar for dealers on a phone.
 *
 * Navigation is filtered by the permissions the signed-in user actually holds,
 * so a warehouse user never sees a warranty decision link they cannot use. This
 * is presentation, not protection — the API refuses the request regardless.
 */

interface NavEntry {
  href: string;
  label: string;
  permission?: string;
  icon: React.ReactNode;
}

const STAFF_NAV: { section: string; items: NavEntry[] }[] = [
  {
    section: 'Overview',
    items: [{ href: '/admin', label: 'Dashboard', permission: 'dashboard:view', icon: <IconGrid /> }],
  },
  {
    section: 'Operations',
    items: [
      { href: '/admin/mattresses', label: 'Mattresses', permission: 'mattress:read', icon: <IconBox /> },
      { href: '/admin/batches', label: 'Production', permission: 'batch:read', icon: <IconFactory /> },
      { href: '/admin/dispatches', label: 'Dispatches', permission: 'dispatch:read', icon: <IconTruck /> },
    ],
  },
  {
    section: 'Warranty',
    items: [
      { href: '/admin/claims', label: 'Claims', permission: 'claim:read', icon: <IconShield /> },
      { href: '/admin/warranties', label: 'Warranties', permission: 'warranty:read', icon: <IconCheck /> },
    ],
  },
  {
    section: 'Network',
    items: [
      { href: '/admin/dealers', label: 'Dealers', permission: 'dealer:read', icon: <IconStore /> },
      { href: '/admin/applications', label: 'Applications', permission: 'dealer_application:read', icon: <IconInbox /> },
      { href: '/admin/leads', label: 'Website leads', permission: 'lead:read', icon: <IconMail /> },
    ],
  },
  {
    section: 'Website',
    items: [
      { href: '/admin/content', label: 'Products & content', permission: 'cms:read', icon: <IconPage /> },
      { href: '/admin/seo', label: 'SEO', permission: 'seo:read', icon: <IconSearch /> },
    ],
  },
  {
    section: 'Administration',
    items: [
      { href: '/admin/users', label: 'Users & roles', permission: 'user:read', icon: <IconUsers /> },
      { href: '/admin/audit', label: 'Audit trail', permission: 'audit:read', icon: <IconList /> },
      { href: '/admin/system', label: 'System', permission: 'system:health', icon: <IconServer /> },
    ],
  },
];

const DEALER_NAV: NavEntry[] = [
  { href: '/dealer', label: 'Home', icon: <IconGrid /> },
  { href: '/dealer/inventory', label: 'Stock', icon: <IconBox /> },
  { href: '/dealer/incoming', label: 'Incoming', icon: <IconTruck /> },
  { href: '/dealer/sales', label: 'Sales', icon: <IconCheck /> },
  { href: '/dealer/claims', label: 'Claims', icon: <IconShield /> },
];

export function Shell({ children }: { children: React.ReactNode }) {
  const { user, isDealer } = useSession();
  const pathname = usePathname();

  if (!user) return <>{children}</>;

  return (
    <div className={`shell${isDealer ? ' has-tabbar' : ''}`}>
      {!isDealer ? <StaffSidebar pathname={pathname} /> : null}

      <div className="main">
        <Topbar />
        <div className="content">{children}</div>
      </div>

      {isDealer ? (
        <nav className="tabbar" aria-label="Dealer sections">
          {DEALER_NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              aria-current={pathname === item.href ? 'page' : undefined}
            >
              {item.icon}
              <span>{item.label}</span>
            </Link>
          ))}
        </nav>
      ) : null}
    </div>
  );
}

function StaffSidebar({ pathname }: { pathname: string }) {
  const { can } = useSession();

  return (
    <aside className="sidebar">
      <Link href="/admin" className="sidebar__brand">
        COLI<span>FEES</span> Control
      </Link>

      {STAFF_NAV.map((group) => {
        const visible = group.items.filter((item) => !item.permission || can(item.permission));
        if (visible.length === 0) return null;

        return (
          <div className="sidebar__section" key={group.section}>
            <p className="sidebar__label">{group.section}</p>
            {visible.map((item) => (
              <Link
                key={item.href}
                href={item.href}
                className="nav-item"
                aria-current={pathname === item.href || (item.href !== '/admin' && pathname.startsWith(item.href)) ? 'page' : undefined}
              >
                {item.icon}
                <span>{item.label}</span>
              </Link>
            ))}
          </div>
        );
      })}
    </aside>
  );
}

function Topbar() {
  const { user, isDealer } = useSession();
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);

  const signOut = async () => {
    setBusy(true);
    try {
      await post('auth/logout');
    } catch {
      // Even if the call fails, the local session is over as far as this tab is
      // concerned; the server-side session has its own expiry.
    } finally {
      window.location.href = '/login';
    }
  };

  const search = (event: React.FormEvent) => {
    event.preventDefault();
    const term = query.trim();
    if (!term) return;
    // Global search looks up a serial number, which is the identifier staff and
    // dealers actually hold in their hand. Results are scoped by the API.
    router.push(isDealer ? `/dealer/scan?serial=${encodeURIComponent(term)}` : `/admin/mattresses?search=${encodeURIComponent(term)}`);
  };

  return (
    <header className="topbar">
      <form className="topbar__search" role="search" onSubmit={search}>
        <IconSearch />
        <label htmlFor="global-search" className="visually-hidden">Search by serial number</label>
        <input
          id="global-search"
          className="input"
          type="search"
          placeholder="Search serial number…"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          maxLength={40}
          autoComplete="off"
        />
      </form>

      <div className="row" style={{ marginLeft: 'auto', gap: '0.5rem' }}>
        {isDealer ? (
          <Link href="/dealer/scan" className="btn btn-primary btn-sm">Scan</Link>
        ) : null}

        <details style={{ position: 'relative' }}>
          <summary
            className="btn btn-default btn-sm"
            style={{ listStyle: 'none', cursor: 'pointer' }}
            aria-label="Account menu"
          >
            {user?.fullName?.split(' ')[0] ?? 'Account'}
          </summary>
          <div
            className="card"
            style={{
              position: 'absolute', right: 0, top: 'calc(100% + 0.4rem)', width: 'min(18rem, 80vw)',
              boxShadow: 'var(--shadow-lg)', zIndex: 50,
            }}
          >
            <p className="strong">{user?.fullName}</p>
            <p className="small muted" style={{ wordBreak: 'break-all' }}>{user?.email}</p>
            <p className="small muted" style={{ marginTop: '0.35rem' }}>
              {user?.roles.map((role) => role.replace(/_/g, ' ').toLowerCase()).join(', ')}
            </p>
            {user?.dealer ? (
              <p className="small muted">{user.dealer.businessName} · {user.dealer.code}</p>
            ) : null}

            <div className="stack-sm" style={{ marginTop: '0.9rem' }}>
              {!user?.mfaEnabled ? (
                <Link href="/security" className="btn btn-default btn-sm btn-block">Turn on two-factor</Link>
              ) : (
                <Link href="/security" className="btn btn-default btn-sm btn-block">Security settings</Link>
              )}
              <button type="button" className="btn btn-ghost btn-sm btn-block" onClick={signOut} disabled={busy}>
                {busy ? 'Signing out…' : 'Sign out'}
              </button>
            </div>
          </div>
        </details>
      </div>
    </header>
  );
}

/* --- icons, inline to avoid a dependency for nine shapes ----------------- */
const iconProps = {
  width: 17,
  height: 17,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  'aria-hidden': true,
};

function IconGrid() { return <svg {...iconProps}><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>; }
function IconBox() { return <svg {...iconProps}><path d="M3 8.5 12 4l9 4.5v7L12 20l-9-4.5z"/><path d="M3 8.5 12 13l9-4.5M12 13v7"/></svg>; }
function IconFactory() { return <svg {...iconProps}><path d="M3 20V9l5 3V9l5 3V9l5 3v8z"/><path d="M3 20h18"/></svg>; }
function IconTruck() { return <svg {...iconProps}><path d="M3 16V6h11v10"/><path d="M14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/></svg>; }
function IconShield() { return <svg {...iconProps}><path d="M12 3 5 6v5.5c0 4.2 2.9 7.9 7 9.5 4.1-1.6 7-5.3 7-9.5V6z"/></svg>; }
function IconCheck() { return <svg {...iconProps}><path d="M20 6 9 17l-5-5"/></svg>; }
function IconStore() { return <svg {...iconProps}><path d="M4 9h16v11H4z"/><path d="M3 9l1.5-5h15L21 9"/><path d="M9 20v-6h6v6"/></svg>; }
function IconInbox() { return <svg {...iconProps}><path d="M4 13h4l2 3h4l2-3h4"/><path d="M4 13 6 5h12l2 8v6H4z"/></svg>; }
function IconMail() { return <svg {...iconProps}><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>; }
function IconPage() { return <svg {...iconProps}><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>; }
function IconUsers() { return <svg {...iconProps}><circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 6M18 20a5.5 5.5 0 0 0-3-4.9"/></svg>; }
function IconList() { return <svg {...iconProps}><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>; }
function IconServer() { return <svg {...iconProps}><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>; }
function IconSearch() { return <svg {...iconProps}><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>; }
