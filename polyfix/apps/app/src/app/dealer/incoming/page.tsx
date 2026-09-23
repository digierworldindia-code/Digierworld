'use client';

import { useState } from 'react';
import { useApi } from '@/lib/use-api';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, EmptyState, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface Consignment {
  id: string; dispatchCode: string; status: string; dispatchedAt: string | null; expectedAt: string | null;
  transporter: string | null; lrNumber: string | null; vehicleNumber: string | null; itemCount: number;
  items: { serialNumber: string; status: string; product: string; size: string }[];
}

type Condition = 'OK' | 'DAMAGED' | 'MISSING';

/**
 * Receiving.
 *
 * Every unit is confirmed individually, because "all fine" is exactly the habit
 * that lets a damaged mattress become an argument three months later. Damaged
 * and missing units are recorded with a remark and carried into the mattress's
 * permanent history.
 */
export default function IncomingPage() {
  const { data, error, loading, refresh } = useApi<Page<Consignment>>('dealer/incoming');
  const [openId, setOpenId] = useState<string | null>(null);
  const [conditions, setConditions] = useState<Record<string, Condition>>({});
  const [remarks, setRemarks] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const receive = async (consignment: Consignment) => {
    setBusy(true);
    setActionError(null);
    try {
      const result = await post<{ received: number; damaged: number; missing: number }>('dealer/receive', {
        dispatchId: consignment.id,
        items: consignment.items.map((item) => ({
          serialNumber: item.serialNumber,
          condition: conditions[item.serialNumber] ?? 'OK',
          ...(remarks[item.serialNumber] ? { remarks: remarks[item.serialNumber] } : {}),
        })),
      });
      setDone(
        `${result.received} unit${result.received === 1 ? '' : 's'} added to your stock` +
          (result.damaged > 0 ? `, ${result.damaged} noted as damaged` : '') +
          (result.missing > 0 ? `, ${result.missing} reported missing` : '') +
          '.',
      );
      setOpenId(null);
      setConditions({});
      setRemarks({});
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not record the receipt.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Incoming consignments</h1>
          <p>Confirm each unit as you unload it. Damage recorded now is on the record for good.</p>
        </div>
      </div>

      {done ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{done}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}
      {loading ? <Loading rows={3} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="Nothing in transit" body="Consignments appear here as soon as the plant dispatches them." /></div>
        ) : (
          <div className="stack">
            {data.items.map((consignment) => (
              <section className="card" key={consignment.id}>
                <div className="row-between">
                  <div>
                    <p className="mono strong">{consignment.dispatchCode}</p>
                    <p className="small muted">
                      {consignment.itemCount} unit{consignment.itemCount === 1 ? '' : 's'}
                      {consignment.transporter ? ` · ${consignment.transporter}` : ''}
                      {consignment.lrNumber ? ` · LR ${consignment.lrNumber}` : ''}
                    </p>
                    <p className="small faint">
                      Dispatched {formatDate(consignment.dispatchedAt)}
                      {consignment.expectedAt ? ` · expected ${formatDate(consignment.expectedAt)}` : ''}
                    </p>
                  </div>
                  <StatusBadge status={consignment.status} />
                </div>

                {openId === consignment.id ? (
                  <div className="stack" style={{ marginTop: '1rem' }}>
                    <div className="table-wrap">
                      <table className="data responsive">
                        <thead>
                          <tr>
                            <th scope="col">Serial</th><th scope="col">Product</th>
                            <th scope="col">Condition</th><th scope="col">Note</th>
                          </tr>
                        </thead>
                        <tbody>
                          {consignment.items.map((item) => {
                            const condition = conditions[item.serialNumber] ?? 'OK';
                            return (
                              <tr key={item.serialNumber}>
                                <td data-label="Serial" className="mono strong">{item.serialNumber}</td>
                                <td data-label="Product">{item.product}<div className="small muted">{item.size}</div></td>
                                <td data-label="Condition">
                                  <label htmlFor={`cond-${item.serialNumber}`} className="visually-hidden">
                                    Condition for {item.serialNumber}
                                  </label>
                                  <select
                                    id={`cond-${item.serialNumber}`}
                                    className="select"
                                    value={condition}
                                    onChange={(event) =>
                                      setConditions((current) => ({ ...current, [item.serialNumber]: event.target.value as Condition }))
                                    }
                                  >
                                    <option value="OK">Received, in good condition</option>
                                    <option value="DAMAGED">Received, damaged</option>
                                    <option value="MISSING">Not in the consignment</option>
                                  </select>
                                </td>
                                <td data-label="Note">
                                  <label htmlFor={`note-${item.serialNumber}`} className="visually-hidden">
                                    Note for {item.serialNumber}
                                  </label>
                                  <input
                                    id={`note-${item.serialNumber}`}
                                    className="input"
                                    placeholder={condition === 'OK' ? 'Optional' : 'Describe the problem'}
                                    value={remarks[item.serialNumber] ?? ''}
                                    maxLength={300}
                                    onChange={(event) =>
                                      setRemarks((current) => ({ ...current, [item.serialNumber]: event.target.value }))
                                    }
                                  />
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>

                    <div className="row">
                      <button type="button" className="btn btn-primary" disabled={busy} onClick={() => receive(consignment)}>
                        {busy ? 'Recording…' : `Confirm ${consignment.items.length} unit${consignment.items.length === 1 ? '' : 's'}`}
                      </button>
                      <button type="button" className="btn btn-ghost" onClick={() => setOpenId(null)}>Cancel</button>
                    </div>
                  </div>
                ) : (
                  <button
                    type="button"
                    className="btn btn-primary btn-block"
                    style={{ marginTop: '1rem' }}
                    onClick={() => setOpenId(consignment.id)}
                  >
                    Receive this consignment
                  </button>
                )}
              </section>
            ))}
          </div>
        )
      ) : null}
    </>
  );
}
