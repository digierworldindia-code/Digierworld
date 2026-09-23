'use client';

import { useActionState, useEffect, useRef } from 'react';
import Link from 'next/link';
import { verifyMattressAction, IDLE } from '@/app/actions';
import { FormField } from '@/components/form-field';
import { BRAND } from '@polyfix/brand';

/**
 * Warranty verification.
 *
 * The result is rendered from exactly what the API returns, which is a
 * deliberately small set of public fields: product, manufacturing date and
 * warranty status. There is no customer name, no dealer, no price and no claim
 * history to display, because the endpoint does not send any.
 *
 * A QR scan lands here with `?q=<token>` and submits automatically, so the
 * common path is: point the phone camera, read the answer.
 */
export function VerifyForm({ initialToken }: { initialToken?: string }) {
  const [state, action, pending] = useActionState(verifyMattressAction, IDLE);
  const formRef = useRef<HTMLFormElement>(null);
  const submitted = useRef(false);

  useEffect(() => {
    if (initialToken && !submitted.current) {
      submitted.current = true;
      formRef.current?.requestSubmit();
    }
  }, [initialToken]);

  const result = state.result;

  return (
    <div className="stack-lg">
      <form ref={formRef} action={action} className="card stack" noValidate>
        {initialToken ? <input type="hidden" name="qrToken" value={initialToken} /> : null}

        <FormField
          label="Mattress serial number"
          name="serialNumber"
          required={!initialToken}
          placeholder="CLF26000001"
          hint="Printed on the law label stitched to the side panel, near the foot of the mattress."
          autoComplete="off"
          maxLength={20}
          errors={state.fieldErrors?.serialNumber}
        />

        <div className="cluster">
          <button type="submit" className="btn btn-primary" disabled={pending}>
            {pending ? 'Checking…' : 'Verify mattress'}
          </button>
          <span className="muted" style={{ fontSize: 'var(--step--1)' }}>
            Or scan the QR code on the label with your phone camera.
          </span>
        </div>

        {state.status === 'error' && state.message ? (
          <p className="notice notice-critical" role="alert">{state.message}</p>
        ) : null}
      </form>

      {result ? (
        <div aria-live="polite">
          {!result.found ? (
            <div className="card stack">
              <span className="badge badge-critical">Not recognised</span>
              <h3>We have no record of that serial number</h3>
              <p className="muted">{result.note}</p>
              <p style={{ fontSize: 'var(--step--1)' }}>
                If you believe this is a genuine {BRAND.shortName} product,{' '}
                <Link href="/contact">contact us</Link> with a photograph of the label.
              </p>
            </div>
          ) : (
            <div className="card stack">
              <span className="badge badge-positive">Genuine {BRAND.shortName} product</span>

              <h3 style={{ marginTop: '0.5rem' }}>{result.product?.name}</h3>
              <p className="muted">{result.note}</p>

              <table className="spec-table" style={{ marginTop: '1rem' }}>
                <caption className="visually-hidden">Verification details for this mattress</caption>
                <tbody>
                  <tr>
                    <th scope="row">Serial number</th>
                    <td>{result.serialNumber}</td>
                  </tr>
                  <tr>
                    <th scope="row">Product</th>
                    <td>
                      {result.product ? (
                        <Link href={`/mattresses/${result.product.slug}`}>{result.product.name}</Link>
                      ) : '—'}
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Size</th>
                    <td>{result.product?.size ?? '—'}</td>
                  </tr>
                  <tr>
                    <th scope="row">Comfort level</th>
                    <td>{result.product?.comfortLevel ?? '—'}</td>
                  </tr>
                  <tr>
                    <th scope="row">Manufactured</th>
                    <td>{result.manufacturedOn ?? '—'}</td>
                  </tr>
                  <tr>
                    <th scope="row">Warranty status</th>
                    <td><WarrantyBadge status={result.warranty?.status ?? 'NOT_ACTIVATED'} /></td>
                  </tr>
                  {result.warranty?.startDate ? (
                    <tr>
                      <th scope="row">Warranty period</th>
                      <td>
                        {result.warranty.startDate} to {result.warranty.endDate}
                        {result.warranty.years ? ` (${result.warranty.years} years)` : ''}
                      </td>
                    </tr>
                  ) : null}
                  {result.warranty?.status === 'ACTIVE' && result.warranty.daysRemaining ? (
                    <tr>
                      <th scope="row">Time remaining</th>
                      <td>{Math.floor(result.warranty.daysRemaining / 30)} months</td>
                    </tr>
                  ) : null}
                </tbody>
              </table>

              <p className="muted" style={{ fontSize: 'var(--step--1)', marginTop: '1rem' }}>
                Need to raise a claim? Contact the dealer you bought from — they raise it against this
                serial number. <Link href="/warranty#claim">How claims work</Link>.
              </p>
            </div>
          )}
        </div>
      ) : null}
    </div>
  );
}

function WarrantyBadge({ status }: { status: string }) {
  const map: Record<string, { className: string; label: string }> = {
    ACTIVE: { className: 'badge badge-positive', label: 'Active' },
    EXPIRED: { className: 'badge badge-neutral', label: 'Expired' },
    VOID: { className: 'badge badge-critical', label: 'Void' },
    SUPERSEDED: { className: 'badge badge-caution', label: 'Replaced under warranty' },
    NOT_ACTIVATED: { className: 'badge badge-caution', label: 'Not activated yet' },
  };
  const entry = map[status] ?? map.NOT_ACTIVATED!;
  return <span className={entry.className}>{entry.label}</span>;
}
