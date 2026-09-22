'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useApi, query } from '@/lib/use-api';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatMoney, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface StockRow {
  id: string; serialNumber: string; status: string; product: string; size: string;
  mrp: number; receivedOn: string | null; conditionNote: string | null;
}

export default function InventoryPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [applied, setApplied] = useState('');

  const { data, error, loading } = useApi<Page<StockRow>>(
    `dealer/inventory${query({ page, pageSize: 25, search: applied })}`,
    [page, applied],
  );

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Your stock</h1>
          <p>Units you hold, and units on their way to you.</p>
        </div>
        <Link href="/dealer/scan" className="btn btn-primary btn-sm">Scan</Link>
      </div>

      <form
        className="card card-tight row"
        style={{ marginBottom: '1rem' }}
        onSubmit={(event) => { event.preventDefault(); setApplied(search.trim()); setPage(1); }}
      >
        <label htmlFor="stock-search" className="visually-hidden">Search your stock</label>
        <input id="stock-search" className="input" style={{ maxWidth: '16rem' }} placeholder="Serial number"
          value={search} onChange={(event) => setSearch(event.target.value)} maxLength={40} />
        <button type="submit" className="btn btn-default btn-sm">Search</button>
        {applied ? (
          <button type="button" className="btn btn-ghost btn-sm"
            onClick={() => { setSearch(''); setApplied(''); setPage(1); }}>Clear</button>
        ) : null}
      </form>

      {loading ? <Loading rows={5} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card">
            <EmptyState
              title="No stock to show"
              body="Units appear here once you have confirmed a consignment."
              action={<Link href="/dealer/incoming" className="btn btn-primary btn-sm">Check incoming</Link>}
            />
          </div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Serial</th><th scope="col">Product</th>
                    <th scope="col">Status</th><th scope="col">Price</th>
                    <th scope="col">Received</th><th scope="col"><span className="visually-hidden">Action</span></th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Serial" className="mono strong">{row.serialNumber}</td>
                      <td data-label="Product">
                        {row.product}<div className="small muted">{row.size}</div>
                        {row.conditionNote ? <div className="small" style={{ color: 'var(--caution)' }}>{row.conditionNote}</div> : null}
                      </td>
                      <td data-label="Status"><StatusBadge status={row.status} /></td>
                      <td data-label="Price">{formatMoney(row.mrp)}</td>
                      <td data-label="Received" className="small muted nowrap">{formatDate(row.receivedOn)}</td>
                      <td data-label="Action">
                        {row.status === 'DEALER_RECEIVED' ? (
                          <Link href={`/dealer/sell?serial=${encodeURIComponent(row.serialNumber)}`} className="btn btn-default btn-sm">Sell</Link>
                        ) : (
                          <Link href={`/dealer/scan?serial=${encodeURIComponent(row.serialNumber)}`} className="btn btn-ghost btn-sm">View</Link>
                        )}
                      </td>
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
