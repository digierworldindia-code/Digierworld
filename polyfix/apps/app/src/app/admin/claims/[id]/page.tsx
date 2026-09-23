'use client';

import { use, useState } from 'react';
import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, Field, formatDate } from '@/components/ui';

interface ClaimDetail {
  claim: {
    id: string; claimNumber: string; status: string; issueCategory: string; reportedIssue: string;
    description: string; submittedAt: string; submittedBy: string; decisionAt: string | null;
    decisionReason: string | null; decidedBy: string | null; resolution: string | null;
  };
  dealer: { id: string; code: string; businessName: string; city: string; state: string; phone: string };
  customer: { fullName: string; city: string | null; state: string | null };
  warranty: { startDate: string; endDate: string; years: number; status: string } | null;
  mattress: {
    id: string; serialNumber: string; product: string; size: string; batchCode: string;
    manufacturedOn: string; dispatchedOn: string | null; receivedOn: string | null; soldOn: string | null;
  };
  replacement: { serialNumber: string; issuedAt: string; remarks: string | null } | null;
  timeline: { eventType: string; fromStatus: string | null; toStatus: string | null; note: string | null; isInternal: boolean; createdAt: string; actor: { fullName: string } | null }[];
  media: { id: string; kind: string; mimeType: string; byteSize: number; width: number | null; height: number | null; uploadedAt: string; url: string }[];
  risk?: {
    level: string; score: number; computedAt: string | null;
    signals: { code: string; weight: number; summary: string; evidence: Record<string, unknown> }[];
    dealerContext: { sales: number; claims: number };
    disclaimer: string;
  };
}

/**
 * Claim review.
 *
 * The layout puts the evidence first and the decision last, on purpose. Risk
 * indicators sit beside the facts that produced them, with the dealer's sales
 * and claim counts alongside, so a "high risk" flag is read in context rather
 * than treated as a verdict.
 */
export default function ClaimDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { can } = useSession();
  const { data, error, loading, refresh } = useApi<ClaimDetail>(`ops/claims/${id}`);

  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [note, setNote] = useState('');
  const [decisionReason, setDecisionReason] = useState('');
  const [infoMessage, setInfoMessage] = useState('');
  const [replacementSerial, setReplacementSerial] = useState('');

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setActionError(null);
    try {
      await fn();
      refresh();
      setNote('');
      setDecisionReason('');
      setInfoMessage('');
      setReplacementSerial('');
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not complete that action.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <Loading rows={6} />;
  if (error) return <ErrorNotice message={error.message} requestId={error.requestId} />;
  if (!data) return null;

  const { claim, mattress, warranty } = data;
  const open = ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'].includes(claim.status);

  return (
    <>
      <div className="page-head">
        <div>
          <p className="small muted"><Link href="/admin/claims">Claims</Link> / review</p>
          <h1 className="mono" style={{ fontSize: '1.6rem' }}>{claim.claimNumber}</h1>
          <p>{claim.reportedIssue}</p>
        </div>
        <div className="row">
          <StatusBadge status={claim.status} />
          {data.risk ? <StatusBadge status={data.risk.level} /> : null}
        </div>
      </div>

      <div className="split">
        <div className="stack">
          <section className="card" aria-labelledby="report-heading">
            <h2 id="report-heading" style={{ marginBottom: '0.75rem' }}>What was reported</h2>
            <p className="small muted" style={{ marginBottom: '0.5rem' }}>
              {claim.issueCategory.replace(/_/g, ' ').toLowerCase()} · raised by {claim.submittedBy} on {formatDate(claim.submittedAt, true)}
            </p>
            <p>{claim.description}</p>
          </section>

          {can('claim:media:view') ? (
            <section className="card" aria-labelledby="evidence-heading">
              <h2 id="evidence-heading" style={{ marginBottom: '0.75rem' }}>Evidence</h2>
              {data.media.length === 0 ? (
                <p className="small muted">No photographs have been uploaded yet.</p>
              ) : (
                <div className="grid grid-3">
                  {data.media.map((item) => (
                    <a key={item.id} href={item.url} className="card card-tight" target="_blank" rel="noopener">
                      <p className="small strong">{item.kind.replace(/_/g, ' ').toLowerCase()}</p>
                      <p className="small muted">
                        {item.width && item.height ? `${item.width}×${item.height} · ` : ''}
                        {(item.byteSize / 1024).toFixed(0)} KB
                      </p>
                      <p className="small faint">{formatDate(item.uploadedAt)}</p>
                      <span className="btn btn-default btn-sm" style={{ marginTop: '0.5rem' }}>Download</span>
                    </a>
                  ))}
                </div>
              )}
              <p className="small faint" style={{ marginTop: '0.75rem' }}>
                Links expire after a few minutes and are checked against your session when opened.
              </p>
            </section>
          ) : null}

          <section className="card" aria-labelledby="history-heading">
            <h2 id="history-heading" style={{ marginBottom: '1rem' }}>History</h2>
            <div className="timeline">
              {data.timeline.map((event, index) => (
                <div className={`timeline__item${event.isInternal ? ' timeline__item--muted' : ''}`} key={index}>
                  <p className="timeline__time">
                    {formatDate(event.createdAt, true)} · {event.actor?.fullName ?? 'System'}
                    {event.isInternal ? ' · internal' : ''}
                  </p>
                  <p className="timeline__title">{event.eventType.replace(/_/g, ' ').toLowerCase()}</p>
                  {event.note ? <p className="timeline__note">{event.note}</p> : null}
                </div>
              ))}
            </div>
          </section>

          {/* ---------- actions ---------- */}
          {open && can('claim:review') ? (
            <section className="card stack" aria-labelledby="review-heading">
              <h2 id="review-heading">Add a note</h2>
              <Field
                label="Note"
                name="note"
                as="textarea"
                value={note}
                onChange={(event) => setNote(event.target.value)}
                hint="Internal notes are invisible to the dealer. This is enforced by the database, not just this screen."
              />
              <div className="row">
                <button type="button" className="btn btn-default" disabled={busy || note.trim().length === 0}
                  onClick={() => run(() => post(`ops/claims/${id}/notes`, { note, isInternal: true }))}>
                  Save internal note
                </button>
                <button type="button" className="btn btn-ghost" disabled={busy || note.trim().length === 0}
                  onClick={() => run(() => post(`ops/claims/${id}/notes`, { note, isInternal: false }))}>
                  Save note visible to dealer
                </button>
              </div>

              <hr style={{ border: 0, borderTop: '1px solid var(--line)', margin: '0.5rem 0' }} />

              <h2>Ask the dealer for more</h2>
              <Field
                label="What do you need?"
                name="infoMessage"
                as="textarea"
                value={infoMessage}
                onChange={(event) => setInfoMessage(event.target.value)}
                hint="The dealer sees this message and is notified."
              />
              <button type="button" className="btn btn-default" style={{ alignSelf: 'flex-start' }}
                disabled={busy || infoMessage.trim().length < 10}
                onClick={() => run(() => post(`ops/claims/${id}/request-information`, { message: infoMessage }))}>
                Request information
              </button>
            </section>
          ) : null}

          {open && can('claim:decide') ? (
            <section className="card stack" aria-labelledby="decision-heading">
              <h2 id="decision-heading">Decision</h2>
              <Field
                label="Reason"
                name="decisionReason"
                as="textarea"
                required
                value={decisionReason}
                onChange={(event) => setDecisionReason(event.target.value)}
                hint="Recorded permanently in the audit trail alongside the risk indicators shown to you now. Minimum 10 characters."
              />
              <div className="row">
                <button type="button" className="btn btn-primary" disabled={busy || decisionReason.trim().length < 10}
                  onClick={() => run(() => post(`ops/claims/${id}/decision`, { decision: 'APPROVED', decisionReason }))}>
                  Approve claim
                </button>
                <button type="button" className="btn btn-danger" disabled={busy || decisionReason.trim().length < 10}
                  onClick={() => run(() => post(`ops/claims/${id}/decision`, { decision: 'REJECTED', decisionReason }))}>
                  Reject claim
                </button>
              </div>
            </section>
          ) : null}

          {claim.status === 'APPROVED' && can('claim:replace') ? (
            <section className="card stack" aria-labelledby="replacement-heading">
              <h2 id="replacement-heading">Issue a replacement</h2>
              <p className="small muted">
                The replacement must be free stock. The original mattress stays in the record, marked
                replaced, and the two are permanently linked.
              </p>
              <Field
                label="Replacement serial number"
                name="replacementSerial"
                value={replacementSerial}
                onChange={(event) => setReplacementSerial(event.target.value.toUpperCase())}
                placeholder="CLF26000123"
                large
              />
              <button type="button" className="btn btn-primary" style={{ alignSelf: 'flex-start' }}
                disabled={busy || replacementSerial.trim().length < 8}
                onClick={() => run(() => post(`ops/claims/${id}/replacement`, { replacementSerialNumber: replacementSerial }))}>
                Issue replacement
              </button>
            </section>
          ) : null}

          {actionError ? <ErrorNotice message={actionError} /> : null}
        </div>

        {/* ---------- context column ---------- */}
        <div className="stack">
          {data.risk ? (
            <section className={`risk risk--${data.risk.level}`} aria-labelledby="risk-heading">
              <div className="row-between">
                <h2 id="risk-heading" style={{ fontSize: '1rem' }}>Risk indicators</h2>
                <StatusBadge status={data.risk.level} />
              </div>
              <p className="small" style={{ marginTop: '0.35rem' }}>
                Score {data.risk.score} of 100 · dealer has {data.risk.dealerContext.claims} claims across{' '}
                {data.risk.dealerContext.sales} sales
              </p>

              {data.risk.signals.length === 0 ? (
                <p className="small" style={{ marginTop: '0.75rem' }}>No indicators triggered.</p>
              ) : (
                <div className="risk__signals">
                  {data.risk.signals.map((signal) => (
                    <div className="risk__signal" key={signal.code}>
                      <h4>{signal.code.replace(/_/g, ' ').toLowerCase()}</h4>
                      <p>{signal.summary}</p>
                    </div>
                  ))}
                </div>
              )}

              <p className="small" style={{ marginTop: '0.85rem', opacity: 0.85 }}>{data.risk.disclaimer}</p>
            </section>
          ) : null}

          <section className="card" aria-labelledby="unit-heading">
            <h2 id="unit-heading" style={{ marginBottom: '0.75rem' }}>Mattress</h2>
            <dl className="dl">
              <div><dt>Serial</dt><dd><Link href={`/admin/mattresses/${mattress.id}`} className="mono">{mattress.serialNumber}</Link></dd></div>
              <div><dt>Product</dt><dd>{mattress.product}</dd></div>
              <div><dt>Size</dt><dd>{mattress.size}</dd></div>
              <div><dt>Batch</dt><dd className="mono">{mattress.batchCode}</dd></div>
              <div><dt>Made</dt><dd>{formatDate(mattress.manufacturedOn)}</dd></div>
              <div><dt>Received</dt><dd>{formatDate(mattress.receivedOn)}</dd></div>
              <div><dt>Sold</dt><dd>{formatDate(mattress.soldOn)}</dd></div>
            </dl>
          </section>

          {warranty ? (
            <section className="card" aria-labelledby="cover-heading">
              <h2 id="cover-heading" style={{ marginBottom: '0.75rem' }}>Warranty</h2>
              <dl className="dl">
                <div><dt>Status</dt><dd><StatusBadge status={warranty.status} /></dd></div>
                <div><dt>Term</dt><dd>{warranty.years} years</dd></div>
                <div><dt>From</dt><dd>{formatDate(warranty.startDate)}</dd></div>
                <div><dt>Until</dt><dd>{formatDate(warranty.endDate)}</dd></div>
              </dl>
            </section>
          ) : null}

          <section className="card" aria-labelledby="parties-heading">
            <h2 id="parties-heading" style={{ marginBottom: '0.75rem' }}>Parties</h2>
            <dl className="dl">
              <div><dt>Dealer</dt><dd>{data.dealer.businessName}</dd></div>
              <div><dt>Code</dt><dd className="mono">{data.dealer.code}</dd></div>
              <div><dt>Location</dt><dd>{data.dealer.city}, {data.dealer.state}</dd></div>
              <div><dt>Phone</dt><dd>{data.dealer.phone}</dd></div>
              <div><dt>Customer</dt><dd>{data.customer.fullName}</dd></div>
            </dl>
          </section>

          {data.replacement ? (
            <section className="card" aria-labelledby="issued-heading">
              <h2 id="issued-heading" style={{ marginBottom: '0.5rem' }}>Replacement issued</h2>
              <p className="small">
                <span className="mono strong">{data.replacement.serialNumber}</span> on{' '}
                {formatDate(data.replacement.issuedAt)}
              </p>
              {data.replacement.remarks ? <p className="small muted">{data.replacement.remarks}</p> : null}
            </section>
          ) : null}

          {claim.decisionAt ? (
            <section className="card" aria-labelledby="outcome-heading">
              <h2 id="outcome-heading" style={{ marginBottom: '0.5rem' }}>Outcome</h2>
              <p className="small muted">{formatDate(claim.decisionAt, true)} · {claim.decidedBy}</p>
              <p className="small" style={{ marginTop: '0.4rem' }}>{claim.decisionReason}</p>
            </section>
          ) : null}
        </div>
      </div>
    </>
  );
}
