'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { post, ApiRequestError } from '@/lib/api-client';
import { Field, Loading, ErrorNotice, formatDate } from '@/components/ui';
import { BRAND } from '@polyfix/brand';

interface SaleResult {
  saleId: string; warrantyId: string; serialNumber: string;
  warrantyStart: string; warrantyEnd: string; warrantyYears: number; message: string;
}

const PAYMENT_MODES = [
  { value: 'UPI', label: 'UPI' },
  { value: 'CASH', label: 'Cash' },
  { value: 'CARD', label: 'Card' },
  { value: 'BANK_TRANSFER', label: 'Bank transfer' },
  { value: 'FINANCE', label: 'Finance / EMI' },
  { value: 'OTHER', label: 'Other' },
];

/**
 * Record a sale.
 *
 * One form, submitted once, and the warranty starts automatically — the dealer
 * never has to remember a second step. Field errors come back from the API
 * keyed by field name, so a bad phone number lands next to the phone number.
 */
function SellForm() {
  const params = useSearchParams();
  const router = useRouter();

  const [serial, setSerial] = useState(params.get('serial') ?? '');
  const [form, setForm] = useState({
    invoiceNumber: '',
    soldAt: new Date().toISOString().slice(0, 10),
    salePrice: '',
    paymentMode: 'UPI',
    fullName: '',
    phone: '',
    email: '',
    addressLine: '',
    city: '',
    state: '',
    pincode: '',
  });

  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<SaleResult | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});

    try {
      const response = await post<SaleResult>('dealer/sales', {
        serialNumber: serial.trim().toUpperCase(),
        invoiceNumber: form.invoiceNumber.trim(),
        soldAt: form.soldAt,
        salePrice: Number(form.salePrice),
        paymentMode: form.paymentMode,
        customer: {
          fullName: form.fullName.trim(),
          phone: form.phone.trim(),
          ...(form.email.trim() ? { email: form.email.trim() } : {}),
          ...(form.addressLine.trim() ? { addressLine: form.addressLine.trim() } : {}),
          ...(form.city.trim() ? { city: form.city.trim() } : {}),
          ...(form.state.trim() ? { state: form.state.trim() } : {}),
          ...(form.pincode.trim() ? { pincode: form.pincode.trim() } : {}),
        },
      });
      setResult(response);
    } catch (caught) {
      if (caught instanceof ApiRequestError) {
        setError(caught.info.message);
        // The API namespaces customer fields, so "customer.phone" is flattened
        // back to "phone" for display.
        const flattened: Record<string, string[]> = {};
        for (const [key, value] of Object.entries(caught.info.details ?? {})) {
          flattened[key.replace(/^customer\./, '')] = value;
        }
        setFieldErrors(flattened);
      } else {
        setError('Could not reach the service. Check your connection and try again.');
      }
    } finally {
      setBusy(false);
    }
  };

  if (result) {
    return (
      <>
        <div className="page-head"><h1>Sale recorded</h1></div>
        <div className="card stack">
          <p className="notice notice-positive" role="status">{result.message}</p>
          <dl className="dl">
            <div><dt>Mattress</dt><dd className="mono">{result.serialNumber}</dd></div>
            <div><dt>Warranty starts</dt><dd>{formatDate(result.warrantyStart)}</dd></div>
            <div><dt>Warranty ends</dt><dd>{formatDate(result.warrantyEnd)}</dd></div>
            <div><dt>Term</dt><dd>{result.warrantyYears} years</dd></div>
          </dl>
          <p className="small muted">
            Tell the customer they can check this mattress themselves at any time by scanning the QR
            label on it.
          </p>
          <div className="stack-sm">
            <Link href="/dealer/scan" className="btn btn-primary btn-block">Scan the next mattress</Link>
            <Link href="/dealer/sales" className="btn btn-default btn-block">View sales</Link>
          </div>
        </div>
      </>
    );
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Record a sale</h1>
          <p>The warranty starts automatically from the sale date.</p>
        </div>
      </div>

      <form onSubmit={submit} className="card stack" noValidate>
        <Field
          label="Mattress serial number"
          name="serialNumber"
          value={serial}
          onChange={(event) => setSerial(event.target.value.toUpperCase())}
          required
          large
          errors={fieldErrors.serialNumber}
        />

        <div className="form-grid form-grid-2">
          <Field label="Invoice number" name="invoiceNumber" required value={form.invoiceNumber}
            onChange={set('invoiceNumber')} errors={fieldErrors.invoiceNumber} />
          <Field label="Sale date" name="soldAt" type="date" required value={form.soldAt}
            onChange={set('soldAt')} errors={fieldErrors.soldAt} />
        </div>

        <div className="form-grid form-grid-2">
          <Field label="Sale price (₹)" name="salePrice" type="number" min={0} step={1} required
            value={form.salePrice} onChange={set('salePrice')} errors={fieldErrors.salePrice} />
          <Field label="Payment mode" name="paymentMode" as="select" options={PAYMENT_MODES}
            value={form.paymentMode} onChange={set('paymentMode')} errors={fieldErrors.paymentMode} />
        </div>

        <h2 style={{ fontSize: '1rem', marginTop: '0.5rem' }}>Customer</h2>
        <p className="small muted" style={{ marginTop: '-0.5rem' }}>
          Needed so the warranty can be honoured. Contact details are encrypted and are visible only
          to you and the {BRAND.shortName} warranty team.
        </p>

        <div className="form-grid form-grid-2">
          <Field label="Full name" name="fullName" required autoComplete="off" value={form.fullName}
            onChange={set('fullName')} errors={fieldErrors.fullName} />
          <Field label="Mobile number" name="phone" type="tel" inputMode="tel" required value={form.phone}
            onChange={set('phone')} errors={fieldErrors.phone} />
        </div>

        <div className="form-grid form-grid-2">
          <Field label="Email (optional)" name="email" type="email" value={form.email}
            onChange={set('email')} errors={fieldErrors.email} />
          <Field label="City" name="city" value={form.city} onChange={set('city')} errors={fieldErrors.city} />
        </div>

        <div className="form-grid form-grid-2">
          <Field label="State" name="state" value={form.state} onChange={set('state')} errors={fieldErrors.state} />
          <Field label="PIN code" name="pincode" inputMode="numeric" maxLength={6} value={form.pincode}
            onChange={set('pincode')} errors={fieldErrors.pincode} />
        </div>

        <Field label="Delivery address" name="addressLine" as="textarea" value={form.addressLine}
          onChange={set('addressLine')} errors={fieldErrors.addressLine} />

        {error ? <ErrorNotice message={error} /> : null}

        <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
          {busy ? 'Recording…' : 'Record sale and start warranty'}
        </button>
      </form>
    </>
  );
}

export default function SellPage() {
  return (
    <Suspense fallback={<Loading rows={5} />}>
      <SellForm />
    </Suspense>
  );
}
