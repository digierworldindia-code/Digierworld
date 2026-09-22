'use client';

import { Suspense, useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, formatDate, formatMoney } from '@/components/ui';

interface ScanResult {
  mattress: {
    serialNumber: string; status: string; product: string; productSlug: string; size: string;
    comfortLevel: string; mrp: number; manufacturedOn: string; receivedOn: string | null;
    soldOn: string | null; isReplacement: boolean; conditionNote: string | null;
  };
  warranty: { status: string; startDate: string; endDate: string } | null;
  sale: { invoiceNumber: string; soldAt: string; customerName: string; customerCity: string | null } | null;
  claims: { id: string; claimNumber: string; status: string; submittedAt: string; reportedIssue: string }[];
  actions: string[];
}

/**
 * Scan.
 *
 * Three taps from here to a recorded sale: scan, confirm, sell. The input is a
 * plain text field rather than a camera view because a phone's own camera app
 * reads the QR and opens this page with the serial already filled — and when
 * the label is damaged, typing it is the fallback that always works.
 */
function ScanScreen() {
  const params = useSearchParams();
  const initial = params.get('serial') ?? '';

  const [serial, setSerial] = useState(initial);
  const [result, setResult] = useState<ScanResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const lookup = async (value: string) => {
    const cleaned = value.trim().toUpperCase().replace(/[\s-]/g, '');
    if (!cleaned) return;

    setBusy(true);
    setError(null);
    setResult(null);

    try {
      setResult(await post<ScanResult>('dealer/scan', { serialNumber: cleaned }));
    } catch (caught) {
      setError(
        caught instanceof ApiRequestError
          ? caught.info.status === 404
            ? 'That serial number is not in your stock. Check the digits, or ask COLIFEES if it should be.'
            : caught.info.message
          : 'Could not reach the service.',
      );
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (initial) void lookup(initial);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial]);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Scan a mattress</h1>
          <p>Point your camera at the QR label, or type the serial number.</p>
        </div>
      </div>

      <form
        className="card stack"
        onSubmit={(event) => { event.preventDefault(); void lookup(serial); }}
      >
        <div className="field">
          <label htmlFor="serial">Serial number</label>
          <input
            id="serial"
            className="input input-lg mono"
            value={serial}
            onChange={(event) => setSerial(event.target.value.toUpperCase())}
            placeholder="CLF26000001"
            autoComplete="off"
            autoCapitalize="characters"
            inputMode="text"
            maxLength={20}
            autoFocus
          />
          <span className="hint">Printed on the law label, on the side panel near the foot.</span>
        </div>
        <button type="submit" className="btn btn-primary btn-block" disabled={busy || serial.trim().length < 8}>
          {busy ? 'Looking up…' : 'Look up mattress'}
        </button>
      </form>

      {busy ? <div style={{ marginTop: '1rem' }}><Loading rows={2} /></div> : null}
      {error ? <div style={{ marginTop: '1rem' }}><ErrorNotice message={error} /></div> : null}

      {result ? (
        <div className="stack" style={{ marginTop: '1rem' }}>
          <section className="card">
            <div className="row-between" style={{ marginBottom: '0.75rem' }}>
              <div>
                <p className="mono strong" style={{ fontSize: '1.15rem' }}>{result.mattress.serialNumber}</p>
                <p className="small muted">{result.mattress.product} · {result.mattress.size}</p>
              </div>
              <StatusBadge status={result.mattress.status} />
            </div>

            <dl className="dl">
              <div><dt>Comfort</dt><dd>{result.mattress.comfortLevel}</dd></div>
              <div><dt>Retail price</dt><dd>{formatMoney(result.mattress.mrp)}</dd></div>
              <div><dt>Manufactured</dt><dd>{formatDate(result.mattress.manufacturedOn)}</dd></div>
              {result.mattress.receivedOn ? <div><dt>Received</dt><dd>{formatDate(result.mattress.receivedOn)}</dd></div> : null}
              {result.mattress.soldOn ? <div><dt>Sold</dt><dd>{formatDate(result.mattress.soldOn)}</dd></div> : null}
              {result.warranty ? (
                <div><dt>Warranty</dt><dd><StatusBadge status={result.warranty.status} /> until {formatDate(result.warranty.endDate)}</dd></div>
              ) : null}
            </dl>

            {result.mattress.conditionNote ? (
              <p className="notice notice-caution" style={{ marginTop: '0.75rem' }}>{result.mattress.conditionNote}</p>
            ) : null}

            {result.sale ? (
              <div className="notice notice-neutral" style={{ marginTop: '0.75rem' }}>
                Sold to {result.sale.customerName}
                {result.sale.customerCity ? ` (${result.sale.customerCity})` : ''} on{' '}
                {formatDate(result.sale.soldAt)} · invoice {result.sale.invoiceNumber}
              </div>
            ) : null}
          </section>

          {/* The whole point of the screen: what can I do with this unit, now. */}
          <div className="stack-sm">
            {result.actions.includes('SELL') ? (
              <Link href={`/dealer/sell?serial=${encodeURIComponent(result.mattress.serialNumber)}`} className="btn btn-primary btn-block">
                Record a sale
              </Link>
            ) : null}

            {result.actions.includes('RAISE_CLAIM') ? (
              <Link href={`/dealer/claims?new=${encodeURIComponent(result.mattress.serialNumber)}`} className="btn btn-default btn-block">
                Raise a warranty claim
              </Link>
            ) : null}

            {result.actions.includes('RECEIVE') ? (
              <Link href="/dealer/incoming" className="btn btn-default btn-block">
                This unit is in transit — go to receiving
              </Link>
            ) : null}

            {result.actions.length === 0 ? (
              <p className="notice notice-neutral">
                No actions are available for this mattress in its current state.
              </p>
            ) : null}
          </div>

          {result.claims.length > 0 ? (
            <section className="card">
              <h2 style={{ fontSize: '1rem', marginBottom: '0.6rem' }}>Claims on this mattress</h2>
              <div className="stack-sm">
                {result.claims.map((claim) => (
                  <Link key={claim.id} href={`/dealer/claims/${claim.id}`} className="row-between card card-tight" style={{ textDecoration: 'none' }}>
                    <span>
                      <span className="mono strong">{claim.claimNumber}</span>
                      <span className="small muted" style={{ display: 'block' }}>{claim.reportedIssue}</span>
                    </span>
                    <StatusBadge status={claim.status} />
                  </Link>
                ))}
              </div>
            </section>
          ) : null}
        </div>
      ) : null}
    </>
  );
}

export default function ScanPage() {
  return (
    <Suspense fallback={<Loading rows={3} />}>
      <ScanScreen />
    </Suspense>
  );
}
