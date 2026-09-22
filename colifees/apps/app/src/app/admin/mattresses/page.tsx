'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useApi, query } from '@/lib/use-api';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface MattressRow {
  id: string; serialNumber: string; status: string; product: string; size: string;
  batchCode: string; dealer: string | null; manufacturedOn: string; soldOn: string | null; isReplacement: boolean;
}

const STATUSES = ['', 'MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED'];

function MattressList() {
  const params = useSearchParams();
  const [search, setSearch] = useState(params.get('search') ?? '');
  const [applied, setApplied] = useState(params.get('search') ?? '');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, error, loading } = useApi<Page<MattressRow>>(
    `ops/mattresses${query({ search: applied, status, page, pageSize: 25 })}`,
    [applied, status, page],
  );

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Mattresses</h1>
          <p>Every unit ever produced, and where it is now.</p>
        </div>
        <Link href="/admin/batches" className="btn btn-primary btn-sm">Production</Link>
      </div>

      <form
        className="card card-tight row"
        style={{ marginBottom: '1rem' }}
        onSubmit={(event) => { event.preventDefault(); setApplied(search.trim()); setPage(1); }}
      >
        <label htmlFor="serial-search" className="visually-hidden">Search by serial number</label>
        <input
          id="serial-search"
          className="input"
          style={{ maxWidth: '18rem' }}
          placeholder="Serial number"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          maxLength={40}
        />

        <label htmlFor="status-filter" className="visually-hidden">Filter by status</label>
        <select
          id="status-filter"
          className="select"
          style={{ maxWidth: '13rem' }}
          value={status}
          onChange={(event) => { setStatus(event.target.value); setPage(1); }}
        >
          {STATUSES.map((value) => (
            <option key={value} value={value}>
              {value === '' ? 'All statuses' : value.replace(/_/g, ' ').toLowerCase()}
            </option>
          ))}
        </select>

        <button type="submit" className="btn btn-default btn-sm">Search</button>
        {(applied || status) && (
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            onClick={() => { setSearch(''); setApplied(''); setStatus(''); setPage(1); }}
          >
            Clear
          </button>
        )}
      </form>

      {loading ? <Loading rows={6} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No mattresses match" body="Try a different serial number or clear the filters." /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Serial</th>
                    <th scope="col">Product</th>
                    <th scope="col">Status</th>
                    <th scope="col">Held by</th>
                    <th scope="col">Batch</th>
                    <th scope="col">Made</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Serial" className="strong">
                        <Link href={`/admin/mattresses/${row.id}`} className="mono">{row.serialNumber}</Link>
                        {row.isReplacement ? <span className="badge badge-info" style={{ marginLeft: '0.4rem' }}>replacement</span> : null}
                      </td>
                      <td data-label="Product">{row.product}<div className="small muted">{row.size}</div></td>
                      <td data-label="Status"><StatusBadge status={row.status} /></td>
                      <td data-label="Held by">{row.dealer ?? <span className="muted">Plant</span>}</td>
                      <td data-label="Batch" className="mono small">{row.batchCode}</td>
                      <td data-label="Made" className="nowrap small muted">{formatDate(row.manufacturedOn)}</td>
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

export default function MattressesPage() {
  return (
    <Suspense fallback={<Loading rows={6} />}>
      <MattressList />
    </Suspense>
  );
}
