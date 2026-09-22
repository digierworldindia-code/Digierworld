'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { Loading, ErrorNotice, EmptyState, Pagination, Field, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface BatchRow {
  id: string; batchCode: string; manufacturedOn: string; plannedQuantity: number;
  producedQuantity: number; serialisedUnits: number; lineSupervisor: string | null;
  qualityCheckedBy: string | null; warehouse: string;
}

interface ProductRow { id: string; name: string; variantCount: number }

/**
 * Production.
 *
 * Creating a batch and serialising units are two separate actions on purpose: a
 * batch is a plan, and serial numbers are only issued once units physically
 * exist and have passed inspection. Serials come from the database, so two
 * people running this screen at the same time cannot collide.
 */
export default function BatchesPage() {
  const { can } = useSession();
  const [page, setPage] = useState(1);
  const { data, error, loading, refresh } = useApi<Page<BatchRow>>(`ops/batches${query({ page, pageSize: 20 })}`, [page]);
  const warehouses = useApi<{ warehouses: { id: string; name: string; code: string }[] }>('ops/warehouses');
  const products = useApi<{ products: ProductRow[] }>('ops/products');

  const [batchForm, setBatchForm] = useState({ warehouseId: '', manufacturedOn: new Date().toISOString().slice(0, 10), plannedQuantity: '50', lineSupervisor: '' });
  const [produceForm, setProduceForm] = useState({ batchId: '', productVariantId: '', quantity: '10' });
  const [variants, setVariants] = useState<{ id: string; sizeLabel: string; sku: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const loadVariants = async (productId: string) => {
    if (!productId) { setVariants([]); return; }
    try {
      const response = await fetch(`/api/bff/ops/products/${productId}`, { credentials: 'same-origin' });
      const payload = await response.json();
      setVariants(payload?.product?.variants ?? []);
    } catch {
      setVariants([]);
    }
  };

  const createBatch = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true); setActionError(null);
    try {
      const response = await post<{ id: string; batchCode: string }>('ops/batches', {
        warehouseId: batchForm.warehouseId,
        manufacturedOn: batchForm.manufacturedOn,
        plannedQuantity: Number(batchForm.plannedQuantity),
        ...(batchForm.lineSupervisor ? { lineSupervisor: batchForm.lineSupervisor } : {}),
      });
      setMessage(`Batch ${response.batchCode} created.`);
      setProduceForm((current) => ({ ...current, batchId: response.id }));
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not create the batch.');
    } finally { setBusy(false); }
  };

  const produce = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true); setActionError(null);
    try {
      const response = await post<{ batchCode: string; produced: number; serialNumbers: string[] }>('ops/mattresses/produce', {
        batchId: produceForm.batchId,
        items: [{ productVariantId: produceForm.productVariantId, quantity: Number(produceForm.quantity) }],
      });
      setMessage(
        `${response.produced} units serialised in ${response.batchCode}: ${response.serialNumbers[0]} to ${response.serialNumbers[response.serialNumbers.length - 1]}.`,
      );
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not serialise those units.');
    } finally { setBusy(false); }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Production</h1>
          <p>Manufacturing batches and serial number issue.</p>
        </div>
      </div>

      {message ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{message}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      {can('batch:write') ? (
        <div className="grid grid-2" style={{ marginBottom: '1.5rem' }}>
          <form onSubmit={createBatch} className="card stack" noValidate>
            <h2 style={{ fontSize: '1rem' }}>1. Open a batch</h2>
            <Field label="Warehouse" name="warehouseId" as="select" required value={batchForm.warehouseId}
              onChange={(event) => setBatchForm((c) => ({ ...c, warehouseId: event.target.value }))}
              options={[{ value: '', label: 'Select a warehouse' }, ...(warehouses.data?.warehouses ?? []).map((w) => ({ value: w.id, label: w.name }))]} />
            <div className="form-grid form-grid-2">
              <Field label="Manufactured on" name="manufacturedOn" type="date" required value={batchForm.manufacturedOn}
                onChange={(event) => setBatchForm((c) => ({ ...c, manufacturedOn: event.target.value }))} />
              <Field label="Planned quantity" name="plannedQuantity" type="number" min={1} required value={batchForm.plannedQuantity}
                onChange={(event) => setBatchForm((c) => ({ ...c, plannedQuantity: event.target.value }))} />
            </div>
            <Field label="Line supervisor" name="lineSupervisor" value={batchForm.lineSupervisor}
              onChange={(event) => setBatchForm((c) => ({ ...c, lineSupervisor: event.target.value }))} />
            <button type="submit" className="btn btn-primary" disabled={busy || !batchForm.warehouseId}>Create batch</button>
          </form>

          {can('mattress:create') ? (
            <form onSubmit={produce} className="card stack" noValidate>
              <h2 style={{ fontSize: '1rem' }}>2. Serialise finished units</h2>
              <Field label="Batch" name="batchId" as="select" required value={produceForm.batchId}
                onChange={(event) => setProduceForm((c) => ({ ...c, batchId: event.target.value }))}
                options={[{ value: '', label: 'Select a batch' }, ...(data?.items ?? []).map((b) => ({ value: b.id, label: `${b.batchCode} · ${formatDate(b.manufacturedOn)}` }))]} />

              <Field label="Product" name="productId" as="select"
                onChange={(event) => { void loadVariants(event.target.value); setProduceForm((c) => ({ ...c, productVariantId: '' })); }}
                options={[{ value: '', label: 'Select a product' }, ...(products.data?.products ?? []).map((p) => ({ value: p.id, label: p.name }))]} />

              <Field label="Size" name="productVariantId" as="select" required value={produceForm.productVariantId}
                onChange={(event) => setProduceForm((c) => ({ ...c, productVariantId: event.target.value }))}
                options={[{ value: '', label: variants.length ? 'Select a size' : 'Choose a product first' }, ...variants.map((v) => ({ value: v.id, label: `${v.sizeLabel} (${v.sku})` }))]} />

              <Field label="Quantity" name="quantity" type="number" min={1} max={500} required value={produceForm.quantity}
                onChange={(event) => setProduceForm((c) => ({ ...c, quantity: event.target.value }))}
                hint="Serial numbers and QR tokens are issued by the database, not chosen here." />

              <button type="submit" className="btn btn-primary" disabled={busy || !produceForm.batchId || !produceForm.productVariantId}>
                {busy ? 'Issuing…' : 'Issue serial numbers'}
              </button>
            </form>
          ) : null}
        </div>
      ) : null}

      {loading ? <Loading rows={4} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No batches yet" /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Batch</th><th scope="col">Made on</th><th scope="col">Planned</th>
                    <th scope="col">Serialised</th><th scope="col">Warehouse</th><th scope="col">Supervisor</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Batch" className="mono strong">{row.batchCode}</td>
                      <td data-label="Made on" className="nowrap">{formatDate(row.manufacturedOn)}</td>
                      <td data-label="Planned">{row.plannedQuantity}</td>
                      <td data-label="Serialised">{row.serialisedUnits}</td>
                      <td data-label="Warehouse">{row.warehouse}</td>
                      <td data-label="Supervisor" className="small muted">{row.lineSupervisor ?? '—'}</td>
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
