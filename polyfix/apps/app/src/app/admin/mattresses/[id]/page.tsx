'use client';

import { use } from 'react';
import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { StatusBadge, Loading, ErrorNotice, formatDate, formatMoney } from '@/components/ui';

interface Passport {
  mattress: {
    id: string; serialNumber: string; status: string; product: string; productSlug: string;
    sku: string; size: string; mrp: number; batchCode: string; plant: string;
    manufacturedOn: string; dispatchedOn: string | null; receivedOn: string | null; soldOn: string | null;
    dealer: { code: string; businessName: string; city: string; state: string } | null;
    conditionNote: string | null; isReplacement: boolean; deleted: boolean; deleteReason: string | null;
  };
  warranty: { status: string; startDate: string; endDate: string; years: number } | null;
  sale: { invoiceNumber: string; soldAt: string; salePrice: number; customerName: string; customerCity: string | null; customerState: string | null } | null;
  claims: { id: string; claimNumber: string; status: string; riskLevel: string; submittedAt: string; reportedIssue: string }[];
  replacedBy: { serialNumber: string; issuedAt: string } | null;
  replacementFor: { serialNumber: string; claimNumber: string } | null;
  timeline: { eventType: string; fromStatus: string | null; toStatus: string | null; occurredAt: string; actor: string; detail: Record<string, unknown> | null }[];
}

/**
 * The digital passport for one mattress.
 *
 * Everything that ever happened to this unit, on one page: where it was built,
 * who it went to, when it sold, what was claimed and what replaced it. This is
 * the screen that answers a dispute, so it shows the record rather than a
 * summary of it.
 */
export default function MattressPassport({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { data, error, loading } = useApi<Passport>(`ops/mattresses/${id}`);

  if (loading) return <Loading rows={6} />;
  if (error) return <ErrorNotice message={error.message} requestId={error.requestId} />;
  if (!data) return null;

  const { mattress, warranty, sale } = data;

  return (
    <>
      <div className="page-head">
        <div>
          <p className="small muted"><Link href="/admin/mattresses">Mattresses</Link> / passport</p>
          <h1 className="mono" style={{ fontSize: '1.6rem' }}>{mattress.serialNumber}</h1>
          <p>{mattress.product} · {mattress.size}</p>
        </div>
        <div className="row">
          <StatusBadge status={mattress.status} />
          <a
            href={`/api/bff/ops/mattresses/${mattress.id}/qr?format=svg`}
            className="btn btn-default btn-sm"
            target="_blank"
            rel="noopener"
          >
            QR label
          </a>
        </div>
      </div>

      {mattress.deleted ? (
        <div className="notice notice-critical" style={{ marginBottom: '1rem' }}>
          <strong>This record was removed.</strong> Reason: {mattress.deleteReason}. It is retained for
          audit and cannot be deleted permanently.
        </div>
      ) : null}

      <div className="split">
        <div className="stack">
          <section className="card" aria-labelledby="timeline-heading">
            <h2 id="timeline-heading" style={{ marginBottom: '1rem' }}>Lifecycle</h2>
            <div className="timeline">
              {data.timeline.map((event, index) => (
                <div className="timeline__item" key={`${event.eventType}-${index}`}>
                  <p className="timeline__time">{formatDate(event.occurredAt, true)} · {event.actor}</p>
                  <p className="timeline__title">{event.eventType.replace(/_/g, ' ').toLowerCase()}</p>
                  {event.fromStatus && event.toStatus ? (
                    <p className="timeline__note">
                      {event.fromStatus.replace(/_/g, ' ').toLowerCase()} → {event.toStatus.replace(/_/g, ' ').toLowerCase()}
                    </p>
                  ) : null}
                  {event.detail && Object.keys(event.detail).length > 0 ? (
                    <p className="timeline__note mono small">
                      {Object.entries(event.detail)
                        .filter(([, value]) => value !== null && value !== undefined)
                        .map(([key, value]) => `${key}: ${String(value)}`)
                        .join(' · ')}
                    </p>
                  ) : null}
                </div>
              ))}
            </div>
          </section>

          {data.claims.length > 0 ? (
            <section className="card card-flush" aria-labelledby="claims-heading">
              <div className="panel-head"><h2 id="claims-heading">Warranty claims</h2></div>
              <div className="table-wrap">
                <table className="data responsive">
                  <thead>
                    <tr>
                      <th scope="col">Claim</th><th scope="col">Issue</th>
                      <th scope="col">Status</th><th scope="col">Risk</th><th scope="col">Raised</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.claims.map((claim) => (
                      <tr key={claim.id}>
                        <td data-label="Claim"><Link href={`/admin/claims/${claim.id}`} className="mono">{claim.claimNumber}</Link></td>
                        <td data-label="Issue">{claim.reportedIssue}</td>
                        <td data-label="Status"><StatusBadge status={claim.status} /></td>
                        <td data-label="Risk"><StatusBadge status={claim.riskLevel} /></td>
                        <td data-label="Raised" className="small muted nowrap">{formatDate(claim.submittedAt)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          ) : null}
        </div>

        <div className="stack">
          <section className="card" aria-labelledby="identity-heading">
            <h2 id="identity-heading" style={{ marginBottom: '0.75rem' }}>Identity</h2>
            <dl className="dl">
              <div><dt>SKU</dt><dd className="mono">{mattress.sku}</dd></div>
              <div><dt>Batch</dt><dd className="mono">{mattress.batchCode}</dd></div>
              <div><dt>Built at</dt><dd>{mattress.plant}</dd></div>
              <div><dt>Manufactured</dt><dd>{formatDate(mattress.manufacturedOn)}</dd></div>
              <div><dt>Retail price</dt><dd>{formatMoney(mattress.mrp)}</dd></div>
              {mattress.conditionNote ? <div><dt>Condition</dt><dd>{mattress.conditionNote}</dd></div> : null}
            </dl>
          </section>

          <section className="card" aria-labelledby="custody-heading">
            <h2 id="custody-heading" style={{ marginBottom: '0.75rem' }}>Custody</h2>
            <dl className="dl">
              <div><dt>Dispatched</dt><dd>{formatDate(mattress.dispatchedOn)}</dd></div>
              <div><dt>Received</dt><dd>{formatDate(mattress.receivedOn)}</dd></div>
              <div><dt>Sold</dt><dd>{formatDate(mattress.soldOn)}</dd></div>
              <div>
                <dt>Dealer</dt>
                <dd>{mattress.dealer ? `${mattress.dealer.businessName} (${mattress.dealer.code})` : 'Plant'}</dd>
              </div>
            </dl>
          </section>

          {warranty ? (
            <section className="card" aria-labelledby="warranty-heading">
              <h2 id="warranty-heading" style={{ marginBottom: '0.75rem' }}>Warranty</h2>
              <dl className="dl">
                <div><dt>Status</dt><dd><StatusBadge status={warranty.status} /></dd></div>
                <div><dt>Term</dt><dd>{warranty.years} years</dd></div>
                <div><dt>From</dt><dd>{formatDate(warranty.startDate)}</dd></div>
                <div><dt>Until</dt><dd>{formatDate(warranty.endDate)}</dd></div>
              </dl>
            </section>
          ) : null}

          {sale ? (
            <section className="card" aria-labelledby="sale-heading">
              <h2 id="sale-heading" style={{ marginBottom: '0.75rem' }}>Sale</h2>
              <dl className="dl">
                <div><dt>Invoice</dt><dd className="mono">{sale.invoiceNumber}</dd></div>
                <div><dt>Date</dt><dd>{formatDate(sale.soldAt)}</dd></div>
                <div><dt>Price</dt><dd>{formatMoney(sale.salePrice)}</dd></div>
                <div><dt>Customer</dt><dd>{sale.customerName}</dd></div>
                <div><dt>Location</dt><dd>{[sale.customerCity, sale.customerState].filter(Boolean).join(', ') || '—'}</dd></div>
              </dl>
            </section>
          ) : null}

          {data.replacedBy || data.replacementFor ? (
            <section className="card" aria-labelledby="chain-heading">
              <h2 id="chain-heading" style={{ marginBottom: '0.75rem' }}>Replacement chain</h2>
              {data.replacementFor ? (
                <p className="small">
                  Issued as a replacement for{' '}
                  <span className="mono strong">{data.replacementFor.serialNumber}</span> under claim{' '}
                  <span className="mono">{data.replacementFor.claimNumber}</span>.
                </p>
              ) : null}
              {data.replacedBy ? (
                <p className="small">
                  Replaced by <span className="mono strong">{data.replacedBy.serialNumber}</span> on{' '}
                  {formatDate(data.replacedBy.issuedAt)}. This record is retained in full.
                </p>
              ) : null}
            </section>
          ) : null}
        </div>
      </div>
    </>
  );
}
