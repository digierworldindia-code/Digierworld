'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { ErrorNotice, Field } from '@/components/ui';

export default function ChangePasswordPage() {
  const router = useRouter();
  const { user, reload } = useSession();

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setErrors([]);

    if (newPassword !== confirm) {
      setErrors(['The two passwords do not match.']);
      return;
    }

    setBusy(true);
    try {
      await post('auth/change-password', { currentPassword, newPassword });
      const refreshed = await reload();
      router.replace(refreshed?.dealer ? '/dealer' : '/admin');
    } catch (caught) {
      if (caught instanceof ApiRequestError) {
        setError(caught.info.message);
        setErrors(caught.info.details?.newPassword ?? []);
      } else {
        setError('Could not reach the service.');
      }
    } finally { setBusy(false); }
  };

  return (
    <div style={{ maxWidth: '32rem' }}>
      <div className="page-head">
        <div>
          <h1>Change your password</h1>
          <p>
            {user?.mustChangePassword
              ? 'You are using a temporary password. Set your own before continuing.'
              : 'Every other signed-in device will be signed out.'}
          </p>
        </div>
      </div>

      <form onSubmit={submit} className="card stack" noValidate>
        <Field label="Current password" name="currentPassword" type="password" required
          autoComplete="current-password" value={currentPassword}
          onChange={(event) => setCurrentPassword(event.target.value)} />

        <Field label="New password" name="newPassword" type="password" required
          autoComplete="new-password" value={newPassword}
          onChange={(event) => setNewPassword(event.target.value)}
          errors={errors}
          hint="At least 12 characters, with upper and lower case, a digit and a symbol. It must not contain your name, email or the company name." />

        <Field label="Confirm new password" name="confirm" type="password" required
          autoComplete="new-password" value={confirm}
          onChange={(event) => setConfirm(event.target.value)} />

        {error ? <ErrorNotice message={error} /> : null}

        <button type="submit" className="btn btn-primary" style={{ alignSelf: 'flex-start' }} disabled={busy}>
          {busy ? 'Saving…' : 'Change password'}
        </button>
      </form>
    </div>
  );
}
