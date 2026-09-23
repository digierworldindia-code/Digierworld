'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface AuditRow {
  id: string; occurredAt: string; user: string | null; role: string | null; action: string;
  entity: string; entityId: string | null; ip: string | null;
  previousValue: unknown; newValue: unknown; reason: string | null; requestId: string | null;
}

interface ChainCheck {
  entriesChecked: number;
  intact: boolean;
  problems: { id: string; occurredAt: string; problem: string }[];
}

/**
 * Audit trail.
 *
 * Read-only by construction: there is no endpoint that edits or deletes an
 * entry, the application's database role holds no UPDATE or DELETE privilege on
 * the table, and a trigger refuses the operation regardless of who attempts it.
 *
 * The integrity check re-walks the hash chain, where each row seals the one
 * before it. An intact result means no historical row has been altered outside
 * the application — including by restoring a doctored backup.
 */
export default function AuditPage() {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const [entity, setEntity] = useState('');
  const [expanded, setExpanded] = useState<string | null>(null);

  const { data, error, loading } = useApi<Page<AuditRow>>(
    `ops/system/audit${query({ page, pageSize: 40, action, entity })}`,
    [page, action, entity],
  );
  const chain = useApi<ChainCheck>('ops/system/audit/verify');

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Audit trail</h1>
          <p>Every significant action, in order, permanently.</p>
        </div>
      </div>

      {chain.data ? (
        <div className={`notice ${chain.data.intact ? 'notice-positive' : 'notice-critical'}`} style={{ marginBottom: '1rem' }}>
          {chain.data.intact ? (
            <p>
              <strong>Hash chain intact.</strong> {chain.data.entriesChecked.toLocaleString('en-IN')} entries
              verified; none has been altered since it was written.
            </p>
          ) : (
            <>
              <p><strong>Hash chain broken.</strong> {chain.data.problems.length} entries do not match their seal.</p>
              <ul className="small" style={{ marginTop: '0.4rem', paddingLeft: '1.1rem' }}>
                {chain.data.problems.slice(0, 5).map((problem) => (
                  <li key={problem.id}>Entry {problem.id}: {problem.problem}</li>
                ))}
              </ul>
              <p className="small" style={{ marginTop: '0.4rem' }}>
                Investigate immediately. This indicates the table was modified outside the application.
              </p>
            </>
          )}
        </div>
      ) : null}

      <div className="card card-tight row" style={{ marginBottom: '1rem' }}>
        <label htmlFor="audit-action" className="visually-hidden">Filter by action</label>
        <input id="audit-action" className="input" style={{ maxWidth: '16rem' }} placeholder="Action, e.g. CLAIM_APPROVED"
          value={action} onChange={(event) => { setAction(event.target.value.toUpperCase()); setPage(1); }} maxLength={60} />
        <label htmlFor="audit-entity" className="visually-hidden">Filter by entity</label>
        <input id="audit-entity" className="input" style={{ maxWidth: '14rem' }} placeholder="Entity, e.g. warranty_claim"
          value={entity} onChange={(event) => { setEntity(event.target.value.toLowerCase()); setPage(1); }} maxLength={60} />
        {(action || entity) ? (
          <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setAction(''); setEntity(''); setPage(1); }}>Clear</button>
        ) : null}
      </div>

      {loading ? <Loading rows={8} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No entries match" /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th>
                    <th scope="col">Record</th><th scope="col">Reason</th><th scope="col"><span className="visually-hidden">Detail</span></th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((row) => (
                    <>
                      <tr key={row.id}>
                        <td data-label="When" className="small nowrap">{formatDate(row.occurredAt, true)}</td>
                        <td data-label="Who" className="small">
                          {row.user ?? <span className="muted">system</span>}
                          {row.role ? <div className="faint">{row.role.replace(/_/g, ' ').toLowerCase()}</div> : null}
                        </td>
                        <td data-label="Action" className="strong small">{row.action.replace(/_/g, ' ').toLowerCase()}</td>
                        <td data-label="Record" className="small mono">
                          {row.entity}
                          {row.entityId ? <div className="faint">{row.entityId.slice(0, 8)}…</div> : null}
                        </td>
                        <td data-label="Reason" className="small muted">{row.reason ?? '—'}</td>
                        <td data-label="Detail">
                          <button type="button" className="btn btn-ghost btn-sm"
                            onClick={() => setExpanded(expanded === row.id ? null : row.id)}
                            aria-expanded={expanded === row.id}>
                            {expanded === row.id ? 'Hide' : 'Detail'}
                          </button>
                        </td>
                      </tr>
                      {expanded === row.id ? (
                        <tr key={`${row.id}-detail`}>
                          <td colSpan={6} style={{ background: 'var(--surface-2)' }}>
                            <div className="grid grid-2">
                              <div>
                                <p className="small strong">Before</p>
                                <pre className="mono small" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                  {row.previousValue ? JSON.stringify(row.previousValue, null, 2) : '—'}
                                </pre>
                              </div>
                              <div>
                                <p className="small strong">After</p>
                                <pre className="mono small" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                  {row.newValue ? JSON.stringify(row.newValue, null, 2) : '—'}
                                </pre>
                              </div>
                            </div>
                            <p className="small faint" style={{ marginTop: '0.5rem' }}>
                              Entry {row.id} · request {row.requestId ?? 'n/a'} · from {row.ip ?? 'unknown'}
                            </p>
                          </td>
                        </tr>
                      ) : null}
                    </>
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
