'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface DealerRow {
  id: string; code: string; businessName: string; ownerName: string; phone: string;
  city: string; state: string; status: string; publicListed: boolean; onboardedAt: string | null;
  unitsHeld: number; sales: number; claims: number;
}

export default function DealersPage() {
  const [search, setSearch] = useState('');
  const [applied, setApplied] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, error, loading } = useApi<Page<DealerRow>>(
    `ops/dealers${query({ search: applied, status, page, pageSize: 25 })}`,
    [applied, status, page],
  );

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Dealers</h1>
          <p>The appointed network, with stock and claim activity for each.</p>
        </div>
      </div>

      <form className="card card-tight row" style={{ marginBottom: '1rem' }}
        onSubmit={(event) => { event.preventDefault(); setApplied(search.trim()); setPage(1); }}>
        <label htmlFor="dealer-search" className="visually-hidden">Search dealers</label>
        <input id="dealer-search" className="input" style={{ maxWidth: '18rem' }} placeholder="Business name, code or city"
          value={search} onChange={(event) => setSearch(event.target.value)} maxLength={60} />
        <label htmlFor="dealer-status" className="visually-hidden">Filter by status</label>
        <select id="dealer-status" className="select" style={{ maxWidth: '12rem' }} value={status}
          onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
          {['', 'ACTIVE', 'PENDING', 'SUSPENDED', 'TERMINATED'].map((value) => (
            <option key={value} value={value}>{value === '' ? 'All statuses' : value.toLowerCase()}</option>
          ))}
        </select>
        <button type="submit" className="btn btn-default btn-sm">Search</button>
      </form>

      {loading ? <Loading rows={5} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No dealers match" /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Dealer</th><th scope="col">Location</th><th scope="col">Status</th>
                    <th scope="col">Stock</th><th scope="col">Sales</th><th scope="col">Claims</th>
                    <th scope="col">Claim rate</th><th scope="col">Onboarded</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => {
                    // Shown as a plain ratio with the counts beside it: a rate
                    // means nothing without knowing it is 2 of 4 or 30 of 600.
                    const rate = row.sales > 0 ? (row.claims / row.sales) * 100 : null;
                    return (
                      <tr key={row.id}>
                        <td data-label="Dealer" className="strong">
                          {row.businessName}
                          <div className="small muted mono">{row.code} · {row.ownerName}</div>
                        </td>
                        <td data-label="Location">{row.city}<div className="small muted">{row.state}</div></td>
                        <td data-label="Status">
                          <StatusBadge status={row.status} />
                          {row.publicListed ? <div className="small muted">listed publicly</div> : null}
                        </td>
                        <td data-label="Stock">{row.unitsHeld}</td>
                        <td data-label="Sales">{row.sales}</td>
                        <td data-label="Claims">{row.claims}</td>
                        <td data-label="Claim rate">
                          {rate === null ? <span className="muted">—</span> : (
                            <span style={{ color: rate > 15 ? 'var(--caution)' : 'inherit' }}>{rate.toFixed(1)}%</span>
                          )}
                        </td>
                        <td data-label="Onboarded" className="small muted nowrap">{formatDate(row.onboardedAt)}</td>
                      </tr>
                    );
                  })}
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
