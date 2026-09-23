'use client';

import { useActionState } from 'react';
import { submitDealerApplicationAction, IDLE } from '@/app/actions';
import { FormField, Honeypot, SubmitButton } from '@/components/form-field';

export function DealerApplicationForm() {
  const [state, action, pending] = useActionState(submitDealerApplicationAction, IDLE);

  if (state.status === 'success') {
    return (
      <div className="card notice notice-positive" role="status">
        <p style={{ fontWeight: 600 }}>{state.message}</p>
        <p style={{ marginTop: '0.5rem' }}>
          Applications are reviewed by our dealer development team. If your business is a fit, we will
          be in touch to discuss territory, stock and terms.
        </p>
      </div>
    );
  }

  return (
    <form action={action} className="card stack-lg" noValidate>
      <div className="form-grid form-grid-2">
        <FormField label="Business name" name="businessName" required maxLength={180} autoComplete="organization" errors={state.fieldErrors?.businessName} />
        <FormField label="Owner name" name="ownerName" required maxLength={160} autoComplete="name" errors={state.fieldErrors?.ownerName} />
      </div>

      <div className="form-grid form-grid-2">
        <FormField label="Mobile number" name="mobile" type="tel" required inputMode="tel" autoComplete="tel" errors={state.fieldErrors?.mobile} />
        <FormField label="Email address" name="email" type="email" required inputMode="email" autoComplete="email" errors={state.fieldErrors?.email} />
      </div>

      <div className="form-grid form-grid-2">
        <FormField label="City" name="city" required maxLength={80} autoComplete="address-level2" errors={state.fieldErrors?.city} />
        <FormField label="State" name="state" required maxLength={80} autoComplete="address-level1" errors={state.fieldErrors?.state} />
      </div>

      <FormField
        label="Business address"
        name="address"
        as="textarea"
        required
        rows={3}
        maxLength={400}
        errors={state.fieldErrors?.address}
      />

      <FormField
        label="GST number"
        name="gstNumber"
        maxLength={20}
        hint="15 characters, if your business is registered."
        errors={state.fieldErrors?.gstNumber}
      />

      <div className="field">
        <label style={{ display: 'flex', gap: '0.75rem', alignItems: 'flex-start', fontWeight: 400 }}>
          <input type="checkbox" name="hasExistingBusiness" style={{ marginTop: '0.35rem' }} />
          <span>I already run a furniture, bedding or mattress retail business.</span>
        </label>
      </div>

      <FormField
        label="Tell us about your business"
        name="existingBusinessDetails"
        as="textarea"
        rows={3}
        maxLength={1000}
        hint="Showroom size, brands you carry, years in operation."
        errors={state.fieldErrors?.existingBusinessDetails}
      />

      <FormField label="Anything else?" name="message" as="textarea" rows={3} maxLength={2000} errors={state.fieldErrors?.message} />

      <Honeypot />

      {state.status === 'error' && state.message ? (
        <p className="notice notice-critical" role="alert">{state.message}</p>
      ) : null}

      <SubmitButton pending={pending}>Submit application</SubmitButton>
    </form>
  );
}
