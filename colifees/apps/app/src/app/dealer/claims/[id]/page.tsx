'use client';

import { use, useRef, useState } from 'react';
import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { upload, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, formatDate } from '@/components/ui';

interface DealerClaim {
  claim: {
    id: string; claimNumber: string; status: string; issueCategory: string; reportedIssue: string;
    description: string; submittedAt: string; decisionAt: string | null; decisionReason: string | null;
    resolution: string | null; serialNumber: string; product: string; size: string;
    replacementSerial: string | null; replacementIssuedAt: string | null;
  };
  timeline: { eventType: string; fromStatus: string | null; toStatus: string | null; note: string | null; createdAt: string }[];
  media: { id: string; kind: string; mimeType: string; byteSize: number; uploadedAt: string; url: string }[];
}

/**
 * Claim detail for the dealer.
 *
 * Shows the dealer's own claim, its visible history and the decision. Internal
 * review notes are absent — not filtered out here, but invisible to this
 * account at the database level.
 */
export default function DealerClaimPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { data, error, loading, refresh } = useApi<DealerClaim>(`dealer/claims/${id}`);

  const fileInput = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [rejected, setRejected] = useState<{ filename: string; reason: string }[]>([]);

  const sendFiles = async (files: FileList | null) => {
    if (!files || files.length === 0) return;

    setBusy(true);
    setUploadError(null);
    setRejected([]);

    const body = new FormData();
    for (const file of Array.from(files)) body.append('file', file);

    try {
      const response = await upload<{ uploaded: unknown[]; rejected: { filename: string; reason: string }[] }>(
        `dealer/claims/${id}/media`,
        body,
      );
      setRejected(response.rejected ?? []);
      refresh();
    } catch (caught) {
      setUploadError(caught instanceof ApiRequestError ? caught.info.message : 'Could not upload those files.');
    } finally {
      setBusy(false);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  if (loading) return <Loading rows={5} />;
  if (error) return <ErrorNotice message={error.message} requestId={error.requestId} />;
  if (!data) return null;

  const { claim } = data;
  const canUpload = !['REJECTED', 'CLOSED', 'WITHDRAWN', 'REPLACED'].includes(claim.status);

  return (
    <>
      <div className="page-head">
        <div>
          <p className="small muted"><Link href="/dealer/claims">Claims</Link></p>
          <h1 className="mono" style={{ fontSize: '1.4rem' }}>{claim.claimNumber}</h1>
          <p>{claim.reportedIssue}</p>
        </div>
        <StatusBadge status={claim.status} />
      </div>

      <div className="stack">
        {claim.status === 'INFO_REQUESTED' ? (
          <p className="notice notice-caution">
            COLIFEES has asked for more information. See the note below and add what is needed.
          </p>
        ) : null}

        {claim.replacementSerial ? (
          <p className="notice notice-positive">
            A replacement has been issued: <span className="mono strong">{claim.replacementSerial}</span> on{' '}
            {formatDate(claim.replacementIssuedAt)}. It is in your stock and carries the remaining warranty.
          </p>
        ) : null}

        <section className="card">
          <h2 style={{ fontSize: '1rem', marginBottom: '0.75rem' }}>The claim</h2>
          <dl className="dl">
            <div><dt>Mattress</dt><dd className="mono">{claim.serialNumber}</dd></div>
            <div><dt>Product</dt><dd>{claim.product} · {claim.size}</dd></div>
            <div><dt>Problem</dt><dd>{claim.issueCategory.replace(/_/g, ' ').toLowerCase()}</dd></div>
            <div><dt>Raised</dt><dd>{formatDate(claim.submittedAt, true)}</dd></div>
          </dl>
          <p style={{ marginTop: '0.85rem' }}>{claim.description}</p>
        </section>

        <section className="card">
          <h2 style={{ fontSize: '1rem', marginBottom: '0.75rem' }}>Photographs</h2>
          <p className="small muted" style={{ marginBottom: '0.85rem' }}>
            Clear photographs of the problem and of the law label help the review. JPEG, PNG, WebP or
            PDF.
          </p>

          {data.media.length > 0 ? (
            <div className="grid grid-3" style={{ marginBottom: '1rem' }}>
              {data.media.map((item) => (
                <a key={item.id} href={item.url} className="card card-tight" target="_blank" rel="noopener" style={{ textDecoration: 'none' }}>
                  <p className="small strong">{item.kind.replace(/_/g, ' ').toLowerCase()}</p>
                  <p className="small muted">{(item.byteSize / 1024).toFixed(0)} KB · {formatDate(item.uploadedAt)}</p>
                </a>
              ))}
            </div>
          ) : (
            <p className="small muted" style={{ marginBottom: '1rem' }}>Nothing uploaded yet.</p>
          )}

          {canUpload ? (
            <>
              <label htmlFor="claim-files" className="btn btn-primary" style={{ cursor: 'pointer' }}>
                {busy ? 'Uploading…' : 'Add photographs'}
              </label>
              <input
                id="claim-files"
                ref={fileInput}
                type="file"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                multiple
                className="visually-hidden"
                disabled={busy}
                onChange={(event) => void sendFiles(event.target.files)}
              />
            </>
          ) : (
            <p className="small muted">This claim is closed, so no further files can be added.</p>
          )}

          {uploadError ? <div style={{ marginTop: '0.75rem' }}><ErrorNotice message={uploadError} /></div> : null}

          {rejected.length > 0 ? (
            <div className="notice notice-caution" style={{ marginTop: '0.75rem' }}>
              <p><strong>Some files were not accepted:</strong></p>
              <ul style={{ marginTop: '0.4rem', paddingLeft: '1.1rem' }}>
                {rejected.map((item) => (
                  <li key={item.filename} className="small">{item.filename}: {item.reason}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </section>

        <section className="card">
          <h2 style={{ fontSize: '1rem', marginBottom: '1rem' }}>Progress</h2>
          <div className="timeline">
            {data.timeline.map((event, index) => (
              <div className="timeline__item" key={index}>
                <p className="timeline__time">{formatDate(event.createdAt, true)}</p>
                <p className="timeline__title">{event.eventType.replace(/_/g, ' ').toLowerCase()}</p>
                {event.note ? <p className="timeline__note">{event.note}</p> : null}
              </div>
            ))}
          </div>
        </section>

        {claim.decisionAt ? (
          <section className={`card notice ${claim.status === 'REJECTED' ? 'notice-critical' : 'notice-positive'}`}>
            <h2 style={{ fontSize: '1rem', marginBottom: '0.4rem' }}>Decision</h2>
            <p className="small">{formatDate(claim.decisionAt, true)}</p>
            <p style={{ marginTop: '0.4rem' }}>{claim.decisionReason}</p>
            {claim.resolution ? <p style={{ marginTop: '0.4rem' }}>{claim.resolution}</p> : null}
          </section>
        ) : null}
      </div>
    </>
  );
}
