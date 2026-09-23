'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface WarrantyRow {
  id: string; serialNumber: string; product: string; dealer: string;
  startDate: string; endDate: string; years: number; status: string; daysRemaining: number;
}

export default function WarrantiesPage() {
  const [page, setPage] = useState(1);
  const { data, error, loading } = useApi<Page<WarrantyRow>>(`ops/warranties${query({ page, pageSize: 30 })}`, [page]);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Warranties</h1>
          <p>Active cover, ordered by the soonest to expire.</p>
        </div>
      </div>

      {loading ? <Loading rows={6} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No warranties yet" body="A warranty is created automatically when a dealer records a sale." /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Mattress</th><th scope="col">Dealer</th><th scope="col">Term</th>
                    <th scope="col">Expires</th><th scope="col">Remaining</th><th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Mattress" className="mono strong">{row.serialNumber}
                        <div className="small muted">{row.product}</div>
                      </td>
                      <td data-label="Dealer">{row.dealer}</td>
                      <td data-label="Term">{row.years} yrs<div className="small muted">from {formatDate(row.startDate)}</div></td>
                      <td data-label="Expires" className="nowrap">{formatDate(row.endDate)}</td>
                      <td data-label="Remaining" className="small">
                        {row.daysRemaining > 0
                          ? `${Math.floor(row.daysRemaining / 30)} months`
                          : <span className="muted">ended</span>}
                      </td>
                      <td data-label="Status"><StatusBadge status={row.status} /></td>
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
