'use client';

import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { Kpi, StatusBadge, Loading, ErrorNotice, EmptyState, BarChart, formatDate } from '@/components/ui';

interface Dashboard {
  inventory: { totalUnits: number; inWarehouse: number; inTransit: number; atDealers: number; sold: number };
  network: { activeDealers: number; pendingApplications: number };
  warranty: { openClaims: number; highRiskClaims: number; claimsLast30Days: number };
  commercial: { salesThisMonth: number; newLeads: number };
  reviewQueue: {
    id: string; claimNumber: string; status: string; riskLevel: string; riskScore: number;
    submittedAt: string; reportedIssue: string; dealer: string; serialNumber: string;
  }[];
  topDealers: { dealer: string; city: string | null; sales: number }[];
}

/**
 * Operations dashboard.
 *
 * Counts and a work queue, nothing more. It answers "where is the stock, what
 * needs a decision today" without putting a single customer name on a screen
 * that people leave open in a shared office.
 */
export default function AdminDashboard() {
  const { user } = useSession();
  const { data, error, loading } = useApi<Dashboard>('ops/dashboard');

  if (loading) return <Loading rows={5} />;
  if (error) return <ErrorNotice message={error.message} requestId={error.requestId} />;
  if (!data) return null;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Good day{user ? `, ${user.fullName.split(' ')[0]}` : ''}</h1>
          <p>Stock position, warranty queue and network activity.</p>
        </div>
      </div>

      <section aria-labelledby="stock-heading" className="stack">
        <h2 id="stock-heading" className="visually-hidden">Stock position</h2>
        <div className="grid grid-4">
          <Kpi label="Units produced" value={data.inventory.totalUnits.toLocaleString('en-IN')} hint="All time" />
          <Kpi label="At the plant" value={data.inventory.inWarehouse.toLocaleString('en-IN')} hint="Available to dispatch" />
          <Kpi label="In transit" value={data.inventory.inTransit.toLocaleString('en-IN')} hint="Dispatched, not yet received" />
          <Kpi label="At dealers" value={data.inventory.atDealers.toLocaleString('en-IN')} hint="Received, unsold" />
        </div>
      </section>

      <section aria-labelledby="attention-heading" className="stack" style={{ marginTop: '1rem' }}>
        <h2 id="attention-heading" className="visually-hidden">Needs attention</h2>
        <div className="grid grid-4">
          <Kpi label="Open claims" value={data.warranty.openClaims} hint={`${data.warranty.claimsLast30Days} raised in 30 days`} />
          <Kpi
            label="Flagged for review"
            value={data.warranty.highRiskClaims}
            hint="High risk indicators"
            alert={data.warranty.highRiskClaims > 0}
          />
          <Kpi label="Sales this month" value={data.commercial.salesThisMonth} />
          <Kpi label="New website leads" value={data.commercial.newLeads} hint={`${data.network.pendingApplications} dealer applications`} />
        </div>
      </section>

      <div className="split" style={{ marginTop: '1.5rem' }}>
        <section className="card card-flush" aria-labelledby="queue-heading">
          <div className="panel-head">
            <h2 id="queue-heading">Claims awaiting a decision</h2>
            <Link href="/admin/claims" className="btn btn-default btn-sm">All claims</Link>
          </div>

          {data.reviewQueue.length === 0 ? (
            <EmptyState title="Nothing waiting" body="Every claim has been reviewed." />
          ) : (
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Claim</th>
                    <th scope="col">Mattress</th>
                    <th scope="col">Dealer</th>
                    <th scope="col">Risk</th>
                    <th scope="col">Status</th>
                    <th scope="col">Raised</th>
                  </tr>
                </thead>
                <tbody>
                  {data.reviewQueue.map((claim) => (
                    <tr key={claim.id}>
                      <td data-label="Claim" className="strong">
                        <Link href={`/admin/claims/${claim.id}`} className="mono">{claim.claimNumber}</Link>
                        <div className="small muted">{claim.reportedIssue}</div>
                      </td>
                      <td data-label="Mattress"><span className="mono">{claim.serialNumber}</span></td>
                      <td data-label="Dealer">{claim.dealer}</td>
                      <td data-label="Risk"><StatusBadge status={claim.riskLevel} /></td>
                      <td data-label="Status"><StatusBadge status={claim.status} /></td>
                      <td data-label="Raised" className="nowrap small muted">{formatDate(claim.submittedAt)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="card" aria-labelledby="dealers-heading">
          <h2 id="dealers-heading" style={{ marginBottom: '0.9rem' }}>Sales by dealer</h2>
          <p className="small muted" style={{ marginBottom: '1rem' }}>Last 30 days</p>

          {data.topDealers.length === 0 ? (
            <EmptyState title="No sales recorded yet" />
          ) : (
            <BarChart data={data.topDealers.map((entry) => ({ label: entry.dealer, value: entry.sales }))} />
          )}

          <div className="stack-sm" style={{ marginTop: '1.25rem' }}>
            <Link href="/admin/dealers" className="btn btn-default btn-sm btn-block">
              {data.network.activeDealers} active dealers
            </Link>
            {data.network.pendingApplications > 0 ? (
              <Link href="/admin/applications" className="btn btn-default btn-sm btn-block">
                {data.network.pendingApplications} application{data.network.pendingApplications === 1 ? '' : 's'} to review
              </Link>
            ) : null}
          </div>
        </section>
      </div>
    </>
  );
}
