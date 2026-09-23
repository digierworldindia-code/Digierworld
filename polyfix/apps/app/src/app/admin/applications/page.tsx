'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';
import { BRAND } from '@polyfix/brand';

interface ApplicationRow {
  id: string; businessName: string; ownerName: string; mobile: string; email: string;
  city: string; state: string; gstNumber: string | null; hasExistingBusiness: boolean;
  status: string; spamScore: number; createdAt: string; reviewNotes: string | null;
  createdDealer: { code: string } | null;
}

/**
 * Dealership applications.
 *
 * Approving an application creates the dealer record but deliberately does not
 * create a login. Credentials are a separate, explicit act on the users screen,
 * so nobody gets access as a side effect of a button labelled "approve".
 */
export default function ApplicationsPage() {
  const { can } = useSession();
  const [page, setPage] = useState(1);
  const { data, error, loading, refresh } = useApi<Page<ApplicationRow>>(`ops/applications${query({ page, pageSize: 20 })}`, [page]);
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const review = async (id: string, decision: 'APPROVED' | 'REJECTED' | 'INFO_REQUESTED') => {
    setBusy(id); setActionError(null); setMessage(null);
    try {
      const response = await post<{ status: string; dealerId: string | null; nextStep: string | null }>(
        `ops/applications/${id}/review`,
        { decision, ...(notes[id] ? { notes: notes[id] } : {}) },
      );
      setMessage(response.nextStep ?? `Application marked ${decision.replace(/_/g, ' ').toLowerCase()}.`);
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not record that decision.');
    } finally { setBusy(null); }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Dealership applications</h1>
          <p>Businesses applying to carry {BRAND.shortName}.</p>
        </div>
      </div>

      {message ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{message}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}
      {loading ? <Loading rows={4} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No applications" body="Applications from the website appear here." /></div>
        ) : (
          <div className="stack">
            {data.items.map((application) => (
              <article className="card" key={application.id}>
                <div className="row-between">
                  <div>
                    <h2 style={{ fontSize: '1rem' }}>{application.businessName}</h2>
                    <p className="small muted">
                      {application.ownerName} · {application.mobile} · {application.email}
                    </p>
                    <p className="small muted">
                      {application.city}, {application.state}
                      {application.gstNumber ? ` · GST ${application.gstNumber}` : ''}
                      {application.hasExistingBusiness ? ' · existing retail business' : ''}
                    </p>
                  </div>
                  <div className="row">
                    <StatusBadge status={application.status} />
                    {application.spamScore >= 50 ? <span className="badge badge-caution">likely spam</span> : null}
                  </div>
                </div>

                <p className="small faint" style={{ marginTop: '0.5rem' }}>
                  Received {formatDate(application.createdAt, true)}
                  {application.createdDealer ? ` · dealer ${application.createdDealer.code} created` : ''}
                </p>

                {application.reviewNotes ? (
                  <p className="small" style={{ marginTop: '0.5rem' }}>Notes: {application.reviewNotes}</p>
                ) : null}

                {can('dealer_application:review') && application.status !== 'APPROVED' ? (
                  <div className="stack" style={{ marginTop: '0.85rem' }}>
                    <label htmlFor={`notes-${application.id}`} className="visually-hidden">Review notes</label>
                    <input id={`notes-${application.id}`} className="input" placeholder="Review notes (optional)"
                      value={notes[application.id] ?? ''} maxLength={2000}
                      onChange={(event) => setNotes((current) => ({ ...current, [application.id]: event.target.value }))} />
                    <div className="row">
                      <button type="button" className="btn btn-primary btn-sm" disabled={busy === application.id}
                        onClick={() => review(application.id, 'APPROVED')}>Approve and create dealer</button>
                      <button type="button" className="btn btn-default btn-sm" disabled={busy === application.id}
                        onClick={() => review(application.id, 'INFO_REQUESTED')}>Request information</button>
                      <button type="button" className="btn btn-danger btn-sm" disabled={busy === application.id}
                        onClick={() => review(application.id, 'REJECTED')}>Reject</button>
                    </div>
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
