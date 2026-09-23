'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { patch, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface LeadRow {
  id: string; name: string; phone: string; email: string | null; city: string | null;
  requirement: string; message: string; status: string; spamScore: number;
  createdAt: string; respondedAt: string | null; internalNotes: string | null;
}

const STATUSES = ['NEW', 'CONTACTED', 'FOLLOW_UP', 'CONVERTED', 'CLOSED'];

export default function LeadsPage() {
  const { can } = useSession();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const { data, error, loading, refresh } = useApi<Page<LeadRow>>(`ops/leads${query({ status, page, pageSize: 25 })}`, [status, page]);
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const setLeadStatus = async (id: string, next: string) => {
    setBusy(id); setActionError(null);
    try {
      await patch(`ops/leads/${id}`, { status: next });
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not update the lead.');
    } finally { setBusy(null); }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Website leads</h1>
          <p>Enquiries from the public contact form.</p>
        </div>
      </div>

      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      <div className="card card-tight row" style={{ marginBottom: '1rem' }}>
        <label htmlFor="lead-status" className="visually-hidden">Filter by status</label>
        <select id="lead-status" className="select" style={{ maxWidth: '14rem' }} value={status}
          onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
          <option value="">All statuses</option>
          {STATUSES.map((value) => <option key={value} value={value}>{value.replace(/_/g, ' ').toLowerCase()}</option>)}
        </select>
      </div>

      {loading ? <Loading rows={5} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No leads" body="Enquiries from the website appear here." /></div>
        ) : (
          <div className="stack">
            {data.items.map((lead) => (
              <article className="card" key={lead.id}>
                <div className="row-between">
                  <div>
                    <h2 style={{ fontSize: '1rem' }}>
                      {lead.name}
                      {/* Flagged, not hidden: a person decides, and a false
                          positive must never silently lose a real enquiry. */}
                      {lead.spamScore >= 50 ? <span className="badge badge-caution" style={{ marginLeft: '0.5rem' }}>likely spam</span> : null}
                    </h2>
                    <p className="small muted">
                      {lead.phone}
                      {lead.email ? ` · ${lead.email}` : ''}
                      {lead.city ? ` · ${lead.city}` : ''}
                    </p>
                  </div>
                  <div className="row">
                    <StatusBadge status={lead.status} />
                    <span className="badge badge-neutral">{lead.requirement.replace(/_/g, ' ').toLowerCase()}</span>
                  </div>
                </div>

                <p style={{ marginTop: '0.75rem' }}>{lead.message}</p>
                <p className="small faint" style={{ marginTop: '0.5rem' }}>
                  Received {formatDate(lead.createdAt, true)}
                  {lead.respondedAt ? ` · responded ${formatDate(lead.respondedAt, true)}` : ''}
                </p>

                {can('lead:update') ? (
                  <div className="row" style={{ marginTop: '0.85rem' }}>
                    {STATUSES.filter((value) => value !== lead.status).map((value) => (
                      <button key={value} type="button" className="btn btn-default btn-sm"
                        disabled={busy === lead.id} onClick={() => setLeadStatus(lead.id, value)}>
                        Mark {value.replace(/_/g, ' ').toLowerCase()}
                      </button>
                    ))}
                  </div>
                ) : null}
              </article>
            ))}
            <Pagination page={data.page} totalPages={data.totalPages} total={data.total} onChange={setPage} />
          </div>
        )
      ) : null}
    </>
  );
}
