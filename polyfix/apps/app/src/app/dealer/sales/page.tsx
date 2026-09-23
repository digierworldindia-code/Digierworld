'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useApi, query } from '@/lib/use-api';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatMoney, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface SaleRow {
  id: string; invoiceNumber: string; soldAt: string; salePrice: number; paymentMode: string;
  serialNumber: string; product: string; size: string; customerName: string; customerCity: string | null;
  warrantyEnd: string | null; warrantyStatus: string | null;
}

export default function DealerSalesPage() {
  const [page, setPage] = useState(1);
  const { data, error, loading } = useApi<Page<SaleRow>>(`dealer/sales${query({ page, pageSize: 25 })}`, [page]);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Your sales</h1>
          <p>Every mattress you have sold, and the warranty attached to it.</p>
        </div>
        <Link href="/dealer/scan" className="btn btn-primary btn-sm">Record a sale</Link>
      </div>

      {loading ? <Loading rows={5} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card">
            <EmptyState
              title="No sales recorded yet"
              body="Scan a mattress you have sold to record it and start the customer's warranty."
              action={<Link href="/dealer/scan" className="btn btn-primary btn-sm">Scan a mattress</Link>}
            />
          </div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Invoice</th><th scope="col">Mattress</th><th scope="col">Customer</th>
                    <th scope="col">Price</th><th scope="col">Warranty</th><th scope="col">Sold</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Invoice" className="mono strong">{row.invoiceNumber}</td>
                      <td data-label="Mattress">
                        <Link href={`/dealer/scan?serial=${encodeURIComponent(row.serialNumber)}`} className="mono">{row.serialNumber}</Link>
                        <div className="small muted">{row.product} · {row.size}</div>
                      </td>
                      <td data-label="Customer">{row.customerName}<div className="small muted">{row.customerCity ?? ''}</div></td>
                      <td data-label="Price">{formatMoney(row.salePrice)}<div className="small muted">{row.paymentMode.toLowerCase()}</div></td>
                      <td data-label="Warranty">
                        {row.warrantyStatus ? <StatusBadge status={row.warrantyStatus} /> : '—'}
                        <div className="small muted nowrap">{formatDate(row.warrantyEnd)}</div>
                      </td>
                      <td data-label="Sold" className="small muted nowrap">{formatDate(row.soldAt)}</td>
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
