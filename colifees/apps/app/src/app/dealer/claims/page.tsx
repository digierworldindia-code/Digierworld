'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useApi, query } from '@/lib/use-api';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, EmptyState, Pagination, Field, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface ClaimRow {
  id: string; claimNumber: string; status: string; issueCategory: string; reportedIssue: string;
  submittedAt: string; decisionAt: string | null; decisionReason: string | null;
  serialNumber: string; product: string; photoCount: number;
}

const CATEGORIES = [
  { value: 'SAGGING', label: 'Sagging or a dip in the surface' },
  { value: 'FOAM_DEGRADATION', label: 'Foam has broken down' },
  { value: 'SPRING_FAILURE', label: 'Spring failure' },
  { value: 'FABRIC_TEAR', label: 'Fabric tear' },
  { value: 'STITCHING', label: 'Stitching or seam has come apart' },
  { value: 'SIZE_MISMATCH', label: 'Size does not match' },
  { value: 'TRANSIT_DAMAGE', label: 'Damaged in transit' },
  { value: 'OTHER', label: 'Something else' },
];

function ClaimsScreen() {
  const params = useSearchParams();
  const newSerial = params.get('new') ?? '';

  const [page, setPage] = useState(1);
  const { data, error, loading, refresh } = useApi<Page<ClaimRow>>(`dealer/claims${query({ page, pageSize: 25 })}`, [page]);

  const [raising, setRaising] = useState(Boolean(newSerial));
  const [form, setForm] = useState({
    serialNumber: newSerial,
    issueCategory: 'SAGGING',
    reportedIssue: '',
    description: '',
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [actionError, setActionError] = useState<string | null>(null);
  const [created, setCreated] = useState<{ id: string; claimNumber: string; message: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setActionError(null);
    setFieldErrors({});

    try {
      const response = await post<{ id: string; claimNumber: string; message: string }>('dealer/claims', {
        ...form,
        serialNumber: form.serialNumber.trim().toUpperCase(),
      });
      setCreated(response);
      setRaising(false);
      setForm({ serialNumber: '', issueCategory: 'SAGGING', reportedIssue: '', description: '' });
      refresh();
    } catch (caught) {
      if (caught instanceof ApiRequestError) {
        setActionError(caught.info.message);
        setFieldErrors(caught.info.details ?? {});
      } else {
        setActionError('Could not reach the service.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Warranty claims</h1>
          <p>Raise a claim against a mattress you sold, and follow it through to a decision.</p>
        </div>
        {!raising ? (
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setRaising(true)}>Raise a claim</button>
        ) : null}
      </div>

      {created ? (
        <div className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>
          <p><strong>Claim {created.claimNumber} submitted.</strong> {created.message}</p>
          <Link href={`/dealer/claims/${created.id}`} className="btn btn-default btn-sm" style={{ marginTop: '0.6rem' }}>
            Add photographs
          </Link>
        </div>
      ) : null}

      {raising ? (
        <form onSubmit={submit} className="card stack" style={{ marginBottom: '1.25rem' }} noValidate>
          <h2 style={{ fontSize: '1rem' }}>New claim</h2>

          <Field label="Mattress serial number" name="serialNumber" required large
            value={form.serialNumber} onChange={set('serialNumber')} errors={fieldErrors.serialNumber}
            placeholder="CLF26000001" />

          <Field label="What is the problem?" name="issueCategory" as="select" options={CATEGORIES}
            value={form.issueCategory} onChange={set('issueCategory')} errors={fieldErrors.issueCategory} />

          <Field label="Short summary" name="reportedIssue" required maxLength={200}
            value={form.reportedIssue} onChange={set('reportedIssue')} errors={fieldErrors.reportedIssue}
            hint="One line, as the customer described it." />

          <Field label="Full description" name="description" as="textarea" required
            value={form.description} onChange={set('description')} errors={fieldErrors.description}
            hint="What the customer reports, when it started, and what you saw when you inspected it. At least 20 characters." />

          {actionError ? <ErrorNotice message={actionError} /> : null}

          <div className="row">
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {busy ? 'Submitting…' : 'Submit claim'}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setRaising(false)}>Cancel</button>
          </div>
        </form>
      ) : null}

      {loading ? <Loading rows={4} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        data.items.length === 0 ? (
          <div className="card"><EmptyState title="No claims raised" body="Claims you raise appear here with their status and outcome." /></div>
        ) : (
          <div className="card card-flush">
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Claim</th><th scope="col">Mattress</th>
                    <th scope="col">Status</th><th scope="col">Photos</th><th scope="col">Raised</th>
                  </tr>
                </thead>
                <tbody>
                  {data.items.map((claim) => (
                    <tr key={claim.id}>
                      <td data-label="Claim">
                        <Link href={`/dealer/claims/${claim.id}`} className="mono strong">{claim.claimNumber}</Link>
                        <div className="small muted">{claim.reportedIssue}</div>
                      </td>
                      <td data-label="Mattress">
                        <span className="mono">{claim.serialNumber}</span>
                        <div className="small muted">{claim.product}</div>
                      </td>
                      <td data-label="Status">
                        <StatusBadge status={claim.status} />
                        {claim.decisionReason ? <div className="small muted">{claim.decisionReason}</div> : null}
                      </td>
                      <td data-label="Photos" className="small muted">{claim.photoCount}</td>
                      <td data-label="Raised" className="small muted nowrap">{formatDate(claim.submittedAt)}</td>
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

export default function DealerClaimsPage() {
  return (
    <Suspense fallback={<Loading rows={4} />}>
      <ClaimsScreen />
    </Suspense>
  );
}
