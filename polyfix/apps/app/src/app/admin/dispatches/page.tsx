'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useApi, query } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, Field, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface DispatchRow {
  id: string; dispatchCode: string; status: string; dealer: string; dealerCode: string; dealerCity: string;
  warehouse: string; itemCount: number; dispatchedAt: string | null; expectedAt: string | null;
  transporter: string | null; lrNumber: string | null;
}

interface DealerOption { id: string; code: string; businessName: string; city: string }
interface WarehouseOption { id: string; code: string; name: string }

/**
 * Dispatch management.
 *
 * Creating a dispatch takes a list of serial numbers, because that is what the
 * warehouse has in front of it: a stack of labels to scan. The API validates
 * every one against stock before anything is written, and rejects the whole
 * consignment if a single unit is not available — a partial dispatch would
 * leave the paperwork and the pallet disagreeing.
 */
export default function DispatchesPage() {
  const { can } = useSession();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);

  const { data, error, loading, refresh } = useApi<Page<DispatchRow>>(
    `ops/dispatches${query({ status, page, pageSize: 25 })}`,
    [status, page],
  );
  const dealers = useApi<Page<DealerOption>>('ops/dealers?pageSize=100&status=ACTIVE');
  const warehouses = useApi<{ warehouses: WarehouseOption[] }>('ops/warehouses');

  const [form, setForm] = useState({ warehouseId: '', dealerId: '', transporter: '', lrNumber: '', vehicleNumber: '', serials: '' });
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [created, setCreated] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setActionError(null);

    const serialNumbers = form.serials
      .split(/[\s,;]+/)
      .map((value) => value.trim().toUpperCase())
      .filter(Boolean);

    try {
      const response = await post<{ id: string; dispatchCode: string; itemCount: number }>('ops/dispatches', {
        warehouseId: form.warehouseId,
        dealerId: form.dealerId,
        serialNumbers,
        ...(form.transporter ? { transporter: form.transporter } : {}),
        ...(form.lrNumber ? { lrNumber: form.lrNumber } : {}),
        ...(form.vehicleNumber ? { vehicleNumber: form.vehicleNumber } : {}),
      });
      setCreated(`${response.dispatchCode} created with ${response.itemCount} units. Send it when the vehicle leaves.`);
      setCreating(false);
      setForm({ warehouseId: '', dealerId: '', transporter: '', lrNumber: '', vehicleNumber: '', serials: '' });
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not create the dispatch.');
    } finally {
      setBusy(false);
    }
  };

  const send = async (dispatchId: string) => {
    setBusy(true);
    setActionError(null);
    try {
      await post(`ops/dispatches/${dispatchId}/send`, {});
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not send the dispatch.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Dispatches</h1>
          <p>Consignments from the plant to dealers.</p>
        </div>
        {can('dispatch:create') && !creating ? (
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)}>New dispatch</button>
        ) : null}
      </div>

      {created ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{created}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      {creating ? (
        <form onSubmit={submit} className="card stack" style={{ marginBottom: '1.25rem' }} noValidate>
          <h2 style={{ fontSize: '1rem' }}>New dispatch</h2>

          <div className="form-grid form-grid-2">
            <Field
              label="From warehouse" name="warehouseId" as="select" required
              value={form.warehouseId}
              onChange={(event) => setForm((current) => ({ ...current, warehouseId: event.target.value }))}
              options={[{ value: '', label: 'Select a warehouse' }, ...(warehouses.data?.warehouses ?? []).map((w) => ({ value: w.id, label: `${w.name} (${w.code})` }))]}
            />
            <Field
              label="To dealer" name="dealerId" as="select" required
              value={form.dealerId}
              onChange={(event) => setForm((current) => ({ ...current, dealerId: event.target.value }))}
              options={[{ value: '', label: 'Select a dealer' }, ...(dealers.data?.items ?? []).map((d) => ({ value: d.id, label: `${d.businessName} — ${d.city} (${d.code})` }))]}
            />
          </div>

          <div className="form-grid form-grid-2">
            <Field label="Transporter" name="transporter" value={form.transporter}
              onChange={(event) => setForm((current) => ({ ...current, transporter: event.target.value }))} />
            <Field label="LR number" name="lrNumber" value={form.lrNumber}
              onChange={(event) => setForm((current) => ({ ...current, lrNumber: event.target.value }))} />
          </div>

          <Field label="Vehicle number" name="vehicleNumber" value={form.vehicleNumber}
            onChange={(event) => setForm((current) => ({ ...current, vehicleNumber: event.target.value }))} />

          <Field
            label="Serial numbers"
            name="serials"
            as="textarea"
            required
            value={form.serials}
            onChange={(event) => setForm((current) => ({ ...current, serials: event.target.value }))}
            hint="Scan or paste one per line. Every unit must be free stock at the selected warehouse, or the whole dispatch is refused."
            style={{ minHeight: '9rem', fontFamily: 'var(--mono)' }}
          />

          <div className="row">
            <button type="submit" className="btn btn-primary" disabled={busy || !form.warehouseId || !form.dealerId}>
              {busy ? 'Creating…' : 'Create dispatch'}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setCreating(false)}>Cancel</button>
          </div>
        </form>
      ) : null}

      <div className="card card-tight row" style={{ marginBottom: '1rem' }}>
        <label htmlFor="dispatch-status" className="visually-hidden">Filter by status</label>
        <select id="dispatch-status" className="select" style={{ maxWidth: '15rem' }} value={status}
          onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
          {['', 'DRAFT', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED'].map((value) => (
            <option key={value} value={value}>{value === '' ? 'All statuses' : value.replace(/_/g, ' ').toLowerCase()}</option>
          ))}
        </select>
      </div>

      {loading ? <Loading rows={5} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No dispatches" body="Create one to move stock to a dealer." /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Code</th><th scope="col">Dealer</th><th scope="col">Units</th>
                    <th scope="col">Status</th><th scope="col">Dispatched</th><th scope="col"><span className="visually-hidden">Action</span></th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Code" className="mono strong">{row.dispatchCode}
                        <div className="small muted">{row.warehouse}</div>
                      </td>
                      <td data-label="Dealer">{row.dealer}<div className="small muted">{row.dealerCity}</div></td>
                      <td data-label="Units">{row.itemCount}</td>
                      <td data-label="Status"><StatusBadge status={row.status} /></td>
                      <td data-label="Dispatched" className="small muted nowrap">
                        {formatDate(row.dispatchedAt)}
                        {row.lrNumber ? <div className="small faint">LR {row.lrNumber}</div> : null}
                      </td>
                      <td data-label="Action">
                        {row.status === 'DRAFT' && can('dispatch:update') ? (
                          <button type="button" className="btn btn-primary btn-sm" disabled={busy} onClick={() => send(row.id)}>Send</button>
                        ) : (
                          <Link href={`/admin/mattresses?search=`} className="btn btn-ghost btn-sm">—</Link>
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
