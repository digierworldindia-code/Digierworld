'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { post, ApiRequestError } from '@/lib/api-client';
import { Field, Wordmark, CONSOLE_SUFFIX } from '@/components/ui';

function ResetForm() {
  const params = useSearchParams();
  const token = params.get('token') ?? '';
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setErrors([]);

    if (password !== confirm) {
      setErrors(['The two passwords do not match.']);
      return;
    }

    setBusy(true);
    try {
      await post('auth/reset-password', { token, newPassword: password });
      setDone(true);
    } catch (caught) {
      if (caught instanceof ApiRequestError) {
        setError(caught.info.message);
        setErrors(caught.info.details?.newPassword ?? []);
      } else {
        setError('Could not reach the service. Try again.');
      }
    } finally {
      setBusy(false);
    }
  };

  if (!token) {
    return (
      <div className="stack">
        <p className="notice notice-critical">That reset link is incomplete. Request a new one.</p>
        <Link href="/forgot-password" className="btn btn-default btn-block">Request a new link</Link>
      </div>
    );
  }

  if (done) {
    return (
      <div className="stack">
        <p className="notice notice-positive" role="status">
          Your password has been changed and every other session has been signed out.
        </p>
        <Link href="/login" className="btn btn-primary btn-block">Sign in</Link>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="stack" noValidate>
      <Field
        label="New password"
        name="newPassword"
        type="password"
        autoComplete="new-password"
        required
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        hint="At least 12 characters, with upper and lower case, a digit and a symbol."
        errors={errors}
      />
      <Field
        label="Confirm new password"
        name="confirm"
        type="password"
        autoComplete="new-password"
        required
        value={confirm}
        onChange={(event) => setConfirm(event.target.value)}
      />
      {error ? <p className="notice notice-critical" role="alert">{error}</p> : null}
      <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
        {busy ? 'Saving…' : 'Set new password'}
      </button>
    </form>
  );
}

export default function ResetPasswordPage() {
  return (
    <div className="auth">
      <div className="auth__card">
        <div className="auth__brand">
          <strong><Wordmark suffix={CONSOLE_SUFFIX} /></strong>
          <p className="small muted" style={{ marginTop: '0.35rem' }}>Set a new password</p>
        </div>
        <Suspense fallback={<p className="small muted">Loading…</p>}>
          <ResetForm />
        </Suspense>
      </div>
    </div>
  );
}
