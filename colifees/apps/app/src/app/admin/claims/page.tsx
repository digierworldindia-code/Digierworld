'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useApi, query } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface ClaimRow {
  id: string; claimNumber: string; status: string; issueCategory: string; reportedIssue: string;
  submittedAt: string; decisionAt: string | null; dealer: string; dealerCode: string; dealerCity: string;
  serialNumber: string; product: string; photoCount: number; riskLevel?: string; riskScore?: number;
}

const STATUSES = ['', 'SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED'];
const RISKS = ['', 'HIGH', 'MEDIUM', 'LOW'];

export default function ClaimsPage() {
  const { can } = useSession();
  const [status, setStatus] = useState('');
  const [riskLevel, setRiskLevel] = useState('');
  const [page, setPage] = useState(1);

  const { data, error, loading } = useApi<Page<ClaimRow>>(
    `ops/claims${query({ status, riskLevel, page, pageSize: 25 })}`,
    [status, riskLevel, page],
  );

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Warranty claims</h1>
          <p>Risk indicators highlight claims worth a closer look. Every decision is made by a person.</p>
        </div>
      </div>

      <div className="card card-tight row" style={{ marginBottom: '1rem' }}>
        <label htmlFor="claim-status" className="visually-hidden">Filter by status</label>
        <select id="claim-status" className="select" style={{ maxWidth: '14rem' }} value={status}
          onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
          {STATUSES.map((value) => (
            <option key={value} value={value}>{value === '' ? 'All statuses' : value.replace(/_/g, ' ').toLowerCase()}</option>
          ))}
        </select>

        {can('risk:view') ? (
          <>
            <label htmlFor="claim-risk" className="visually-hidden">Filter by risk level</label>
            <select id="claim-risk" className="select" style={{ maxWidth: '12rem' }} value={riskLevel}
              onChange={(event) => { setRiskLevel(event.target.value); setPage(1); }}>
              {RISKS.map((value) => (
                <option key={value} value={value}>{value === '' ? 'Any risk level' : `${value.toLowerCase()} risk`}</option>
              ))}
            </select>
          </>
        ) : null}

        {(status || riskLevel) && (
          <button type="button" className="btn btn-ghost btn-sm"
            onClick={() => { setStatus(''); setRiskLevel(''); setPage(1); }}>Clear</button>
        )}
      </div>

      {loading ? <Loading rows={6} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No claims match" body="Adjust the filters to see more." /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Claim</th>
                    <th scope="col">Mattress</th>
                    <th scope="col">Dealer</th>
                    {can('risk:view') ? <th scope="col">Risk</th> : null}
                    <th scope="col">Status</th>
                    <th scope="col">Photos</th>
                    <th scope="col">Raised</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((claim) => (
                    <tr key={claim.id}>
                      <td data-label="Claim" className="strong">
                        <Link href={`/admin/claims/${claim.id}`} className="mono">{claim.claimNumber}</Link>
                        <div className="small muted">{claim.reportedIssue}</div>
                      </td>
                      <td data-label="Mattress">
                        <span className="mono">{claim.serialNumber}</span>
                        <div className="small muted">{claim.product}</div>
                      </td>
                      <td data-label="Dealer">{claim.dealer}<div className="small muted">{claim.dealerCity}</div></td>
                      {can('risk:view') ? (
                        <td data-label="Risk">
                          {claim.riskLevel ? (
                            <>
                              <StatusBadge status={claim.riskLevel} />
                              <div className="small muted">score {claim.riskScore}</div>
                            </>
                          ) : '—'}
                        </td>
                      ) : null}
                      <td data-label="Status"><StatusBadge status={claim.status} /></td>
                      <td data-label="Photos" className="small muted">{claim.photoCount}</td>
                      <td data-label="Raised" className="small muted nowrap">{formatDate(claim.submittedAt)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div style={{ padding: '0 1.25rem 1rem' }}>
              <Pagination page={data.page} totalPages={data.totalPages} total={data.total} onChange={setPage} />
            </div>
          </div>
        )
      ) : null}
    </>
  );
}
