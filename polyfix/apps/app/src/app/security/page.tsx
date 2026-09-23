'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { Loading, ErrorNotice, Field, formatDate } from '@/components/ui';

interface SessionRow {
  id: string; current: boolean; ip: string | null; device: string | null;
  signedInAt: string; lastSeenAt: string; twoFactor: boolean;
}

/**
 * Security settings.
 *
 * Two-factor enrolment happens in three deliberate steps: prove you know the
 * password, add the secret to an authenticator, then prove the authenticator
 * works before it is switched on. An interrupted enrolment cannot lock anyone
 * out, because the secret is stored but not enabled until that last step.
 *
 * Recovery codes are shown exactly once. Only their hashes are stored, so they
 * cannot be recovered later — which is the point.
 */
export default function SecurityPage() {
  const { user, reload } = useSession();
  const sessions = useApi<{ sessions: SessionRow[] }>('auth/sessions');

  const [step, setStep] = useState<'idle' | 'secret' | 'confirm'>('idle');
  const [password, setPassword] = useState('');
  const [secret, setSecret] = useState<{ secret: string; otpauthUrl: string } | null>(null);
  const [code, setCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const start = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true); setActionError(null);
    try {
      setSecret(await post<{ secret: string; otpauthUrl: string }>('auth/mfa/start', { password }));
      setStep('secret');
      setPassword('');
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not start setup.');
    } finally { setBusy(false); }
  };

  const confirm = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true); setActionError(null);
    try {
      const response = await post<{ recoveryCodes: string[] }>('auth/mfa/confirm', { totpCode: code });
      setRecoveryCodes(response.recoveryCodes);
      setStep('idle');
      setSecret(null);
      setCode('');
      await reload();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'That code was not accepted.');
    } finally { setBusy(false); }
  };

  const revokeOthers = async () => {
    setBusy(true); setActionError(null);
    try {
      const response = await post<{ revoked: number }>('auth/sessions/revoke-others');
      setMessage(`Signed out ${response.revoked} other session${response.revoked === 1 ? '' : 's'}.`);
      sessions.refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not sign out other sessions.');
    } finally { setBusy(false); }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Security</h1>
          <p>Two-factor authentication, password and active sessions.</p>
        </div>
      </div>

      {message ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{message}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      {recoveryCodes ? (
        <div className="notice notice-caution" style={{ marginBottom: '1.25rem' }}>
          <p><strong>Save these recovery codes now.</strong> They are shown once and each works a single time.</p>
          <div className="grid grid-4 mono" style={{ margin: '0.85rem 0', gap: '0.4rem' }}>
            {recoveryCodes.map((recoveryCode) => (
              <span key={recoveryCode} style={{ background: 'var(--surface)', padding: '0.35rem 0.5rem', borderRadius: '4px' }}>
                {recoveryCode}
              </span>
            ))}
          </div>
          <button type="button" className="btn btn-default btn-sm" onClick={() => setRecoveryCodes(null)}>
            I have saved them
          </button>
        </div>
      ) : null}

      <div className="split">
        <div className="stack">
          <section className="card stack" aria-labelledby="mfa-heading">
            <h2 id="mfa-heading" style={{ fontSize: '1rem' }}>Two-factor authentication</h2>

            {user?.mfaEnabled ? (
              <>
                <p className="notice notice-positive">
                  Two-factor authentication is on for this account.
                </p>
                <p className="small muted">
                  Backup, export and the SQL console require it, and it is required for administrator
                  and warranty manager roles.
                </p>
              </>
            ) : (
              <>
                <p className="notice notice-caution">
                  Two-factor authentication is off. Anyone who learns your password can sign in as you.
                </p>

                {step === 'idle' ? (
                  <form onSubmit={start} className="stack">
                    <Field label="Confirm your password" name="password" type="password" required
                      autoComplete="current-password" value={password}
                      onChange={(event) => setPassword(event.target.value)} />
                    <button type="submit" className="btn btn-primary" style={{ alignSelf: 'flex-start' }} disabled={busy}>
                      {busy ? 'Starting…' : 'Set up two-factor'}
                    </button>
                  </form>
                ) : null}

                {step === 'secret' && secret ? (
                  <div className="stack">
                    <p className="small">
                      Add this to your authenticator app (Google Authenticator, Authy, 1Password or
                      similar), then enter the code it shows.
                    </p>
                    <div className="card card-tight">
                      <p className="small muted">Setup key</p>
                      <p className="mono" style={{ fontSize: '1.05rem', wordBreak: 'break-all', marginTop: '0.25rem' }}>
                        {secret.secret}
                      </p>
                    </div>
                    <form onSubmit={confirm} className="stack">
                      <Field label="Code from your app" name="totpCode" inputMode="numeric" maxLength={6} required
                        large autoComplete="one-time-code" value={code}
                        onChange={(event) => setCode(event.target.value)} />
                      <button type="submit" className="btn btn-primary" style={{ alignSelf: 'flex-start' }}
                        disabled={busy || code.length !== 6}>
                        {busy ? 'Verifying…' : 'Turn on two-factor'}
                      </button>
                    </form>
                  </div>
                ) : null}
              </>
            )}
          </section>

          <section className="card stack" aria-labelledby="password-heading">
            <h2 id="password-heading" style={{ fontSize: '1rem' }}>Password</h2>
            <p className="small muted">
              Changing your password signs out every other device immediately.
            </p>
            <Link href="/security/change-password" className="btn btn-default" style={{ alignSelf: 'flex-start' }}>
              Change password
            </Link>
          </section>
        </div>

        <section className="card" aria-labelledby="sessions-heading">
          <div className="row-between" style={{ marginBottom: '0.85rem' }}>
            <h2 id="sessions-heading" style={{ fontSize: '1rem' }}>Active sessions</h2>
            <button type="button" className="btn btn-ghost btn-sm" onClick={revokeOthers} disabled={busy}>
              Sign out others
            </button>
          </div>

          {sessions.loading ? <Loading rows={3} /> : null}
          {sessions.data ? (
            <div className="stack-sm">
              {sessions.data.sessions.map((session) => (
                <div key={session.id} className="card card-tight">
                  <div className="row-between">
                    <span className="small strong">
                      {session.current ? 'This device' : 'Another device'}
                      {session.twoFactor ? <span className="badge badge-positive" style={{ marginLeft: '0.4rem' }}>2FA</span> : null}
                    </span>
                    <span className="small faint">{session.ip ?? 'unknown'}</span>
                  </div>
                  <p className="small muted" style={{ wordBreak: 'break-word' }}>{session.device ?? 'Unknown device'}</p>
                  <p className="small faint">
                    Signed in {formatDate(session.signedInAt, true)} · last active {formatDate(session.lastSeenAt, true)}
                  </p>
                </div>
              ))}
            </div>
          ) : null}
        </section>
      </div>
    </>
  );
}
