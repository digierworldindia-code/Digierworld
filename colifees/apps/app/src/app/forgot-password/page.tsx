'use client';

import { useState } from 'react';
import Link from 'next/link';
import { post } from '@/lib/api-client';
import { Field } from '@/components/ui';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    try {
      await post('auth/forgot-password', { email });
    } catch {
      // The API answers identically whether or not the address is registered,
      // and so does this screen. A failure here must not become a way to
      // discover which addresses exist.
    } finally {
      setSent(true);
      setBusy(false);
    }
  };

  return (
    <div className="auth">
      <div className="auth__card">
        <div className="auth__brand">
          <strong>COLI<span>FEES</span> Control</strong>
        </div>

        {sent ? (
          <div className="stack">
            <p className="notice notice-positive" role="status">
              If that email address has an account, a reset link is on its way. The link is valid for
              30 minutes.
            </p>
            <Link href="/login" className="btn btn-default btn-block">Back to sign-in</Link>
          </div>
        ) : (
          <form onSubmit={submit} className="stack" noValidate>
            <p className="small muted">
              Enter your email address and we will send you a link to set a new password.
            </p>
            <Field
              label="Email address"
              name="email"
              type="email"
              autoComplete="username"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
            <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
              {busy ? 'Sending…' : 'Send reset link'}
            </button>
            <Link href="/login" className="btn btn-ghost btn-sm">Back to sign-in</Link>
          </form>
        )}
      </div>
    </div>
  );
}
