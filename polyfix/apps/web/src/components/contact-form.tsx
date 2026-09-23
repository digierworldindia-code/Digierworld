'use client';

import { useActionState } from 'react';
import { submitContactAction, IDLE } from '@/app/actions';
import { FormField, Honeypot, SubmitButton } from '@/components/form-field';

const REQUIREMENTS = [
  { value: 'PRODUCT_ENQUIRY', label: 'Product enquiry' },
  { value: 'WARRANTY_SUPPORT', label: 'Warranty support' },
  { value: 'DEALERSHIP', label: 'Dealership enquiry' },
  { value: 'BULK_ORDER', label: 'Bulk or institutional order' },
  { value: 'OTHER', label: 'Something else' },
];

export function ContactForm() {
  const [state, action, pending] = useActionState(submitContactAction, IDLE);

  if (state.status === 'success') {
    return (
      <div className="card notice notice-positive" role="status">
        <p style={{ fontWeight: 600 }}>{state.message}</p>
        <p style={{ marginTop: '0.5rem' }}>
          We usually reply within one working day. For anything urgent, please call us.
        </p>
      </div>
    );
  }

  return (
    <form action={action} className="card stack-lg" noValidate>
      <div className="form-grid form-grid-2">
        <FormField label="Your name" name="name" required autoComplete="name" maxLength={160} errors={state.fieldErrors?.name} />
        <FormField
          label="Mobile number"
          name="phone"
          type="tel"
          required
          inputMode="tel"
          autoComplete="tel"
          placeholder="98765 43210"
          errors={state.fieldErrors?.phone}
        />
      </div>

      <div className="form-grid form-grid-2">
        <FormField label="Email address" name="email" type="email" inputMode="email" autoComplete="email" errors={state.fieldErrors?.email} />
        <FormField label="City" name="city" autoComplete="address-level2" maxLength={80} errors={state.fieldErrors?.city} />
      </div>

      <FormField
        label="What is this about?"
        name="requirement"
        as="select"
        required
        options={REQUIREMENTS}
        errors={state.fieldErrors?.requirement}
      />

      <FormField
        label="Message"
        name="message"
        as="textarea"
        required
        rows={5}
        hint="Tell us what you need. If this is about a specific mattress, include the serial number."
        maxLength={2000}
        errors={state.fieldErrors?.message}
      />

      <Honeypot />

      {state.status === 'error' && state.message ? (
        <p className="notice notice-critical" role="alert">{state.message}</p>
      ) : null}

      <div className="cluster">
        <SubmitButton pending={pending}>Send enquiry</SubmitButton>
        <span className="muted" style={{ fontSize: 'var(--step--1)' }}>
          We use your details only to answer this enquiry.
        </span>
      </div>
    </form>
  );
}
