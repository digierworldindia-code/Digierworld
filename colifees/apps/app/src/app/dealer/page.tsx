'use client';

import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { Kpi, Loading, ErrorNotice } from '@/components/ui';

interface Summary {
  inStock: number;
  incoming: number;
  soldThisMonth: number;
  openClaims: number;
  unread: number;
  dealer: { businessName: string; code: string; city: string; state: string } | null;
}

/**
 * Dealer home.
 *
 * Built around one action. A dealer standing in a showroom with a phone in one
 * hand wants to scan a label, and everything else on this screen is secondary
 * to that — which is why the scan button is the largest thing on it and sits
 * above the numbers rather than below them.
 */
export default function DealerHome() {
  const { user } = useSession();
  const { data, error, loading } = useApi<Summary>('dealer/summary');

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{data?.dealer?.businessName ?? 'Dealer portal'}</h1>
          <p>
            {user?.fullName}
            {data?.dealer ? ` · ${data.dealer.code} · ${data.dealer.city}` : ''}
          </p>
        </div>
      </div>

      <Link href="/dealer/scan" className="btn-scan" style={{ textDecoration: 'none', marginBottom: '1.25rem' }}>
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" aria-hidden="true">
          <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
          <path d="M4 12h16" />
        </svg>
        Scan a mattress
        <small>Scan the QR label or type the serial number</small>
      </Link>

      {loading ? <Loading rows={2} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data ? (
        <>
          <div className="grid grid-4">
            <Kpi label="In stock" value={data.inStock} hint="Received, unsold" />
            <Kpi label="Incoming" value={data.incoming} hint="Awaiting your receipt" alert={data.incoming > 0} />
            <Kpi label="Sold this month" value={data.soldThisMonth} />
            <Kpi label="Open claims" value={data.openClaims} />
          </div>

          <div className="grid grid-2" style={{ marginTop: '1rem' }}>
            <Link href="/dealer/incoming" className="card" style={{ textDecoration: 'none' }}>
              <h2 style={{ fontSize: '1rem' }}>Receive a consignment</h2>
              <p className="small muted" style={{ marginTop: '0.3rem' }}>
                {data.incoming > 0
                  ? `${data.incoming} consignment${data.incoming === 1 ? '' : 's'} waiting to be confirmed.`
                  : 'Nothing is waiting to be received.'}
              </p>
            </Link>

            <Link href="/dealer/claims" className="card" style={{ textDecoration: 'none' }}>
              <h2 style={{ fontSize: '1rem' }}>Warranty claims</h2>
              <p className="small muted" style={{ marginTop: '0.3rem' }}>
                {data.openClaims > 0
                  ? `${data.openClaims} claim${data.openClaims === 1 ? '' : 's'} in progress.`
                  : 'No claims in progress.'}
                {data.unread > 0 ? ` ${data.unread} unread update${data.unread === 1 ? '' : 's'}.` : ''}
              </p>
            </Link>
          </div>
        </>
      ) : null}
    </>
  );
}
