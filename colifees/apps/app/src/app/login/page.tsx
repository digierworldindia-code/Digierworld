'use client';

import { Suspense, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { post, ApiRequestError } from '@/lib/api-client';
import { useSession } from '@/lib/session';
import { Field } from '@/components/ui';

/**
 * Sign-in.
 *
 * The form asks for a second factor only once the API has said it is needed,
 * so a user without two-factor enabled never sees a field they cannot fill.
 * Failure messages are whatever the API returned, deliberately unchanged:
 * "that combination was not recognised" is the same answer for an unknown
 * account and a wrong password, and the console must not try to be more
 * helpful than that.
 */
function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const { reload } = useSession();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [needsMfa, setNeedsMfa] = useState(false);
  const [useRecoveryCode, setUseRecoveryCode] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const response = await post<{ user: { dealer: unknown }; next: string }>('auth/login', {
        email,
        password,
        ...(needsMfa && !useRecoveryCode && totpCode ? { totpCode } : {}),
        ...(needsMfa && useRecoveryCode && totpCode ? { recoveryCode: totpCode } : {}),
      });

      const refreshed = await reload();

      const next = params.get('next');
      const destination =
        response.next === 'CHANGE_PASSWORD'
          ? '/security/change-password'
          : response.next === 'ENROL_MFA'
            ? '/security?enrol=1'
            : next && next.startsWith('/')
              ? next
              : refreshed?.dealer || response.next === 'DEALER_HOME'
                ? '/dealer'
                : '/admin';

      router.replace(destination);
    } catch (caught) {
      if (caught instanceof ApiRequestError) {
        if (caught.info.code === 'MFA_REQUIRED') {
          setNeedsMfa(true);
          setError(null);
        } else if (caught.info.code === 'MFA_INVALID') {
          setNeedsMfa(true);
          setError(caught.info.message);
        } else {
          setError(caught.info.message);
        }
      } else {
        setError('Could not reach the service. Check your connection and try again.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="auth">
      <div className="auth__card">
        <div className="auth__brand">
          <strong>COLI<span>FEES</span> Control</strong>
          <p className="small muted" style={{ marginTop: '0.35rem' }}>Staff and dealer sign-in</p>
        </div>

        <form onSubmit={submit} className="stack" noValidate>
          <Field
            label="Email address"
            name="email"
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            disabled={needsMfa}
          />

          <Field
            label="Password"
            name="password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            disabled={needsMfa}
          />

          {needsMfa ? (
            <Field
              label={useRecoveryCode ? 'Recovery code' : 'Authenticator code'}
              name="totpCode"
              inputMode={useRecoveryCode ? 'text' : 'numeric'}
              autoComplete="one-time-code"
              required
              large
              maxLength={useRecoveryCode ? 16 : 6}
              value={totpCode}
              onChange={(event) => setTotpCode(event.target.value)}
              hint={useRecoveryCode ? 'One of the codes you saved when you turned on two-factor.' : 'The 6-digit code from your authenticator app.'}
              autoFocus
            />
          ) : null}

          {error ? <p className="notice notice-critical" role="alert">{error}</p> : null}

          <button type="submit" className="btn btn-primary btn-block" disabled={busy}>
            {busy ? 'Signing in…' : needsMfa ? 'Verify and sign in' : 'Sign in'}
          </button>

          {needsMfa ? (
            <button
              type="button"
              className="btn btn-ghost btn-sm"
              onClick={() => { setUseRecoveryCode((value) => !value); setTotpCode(''); }}
            >
              {useRecoveryCode ? 'Use an authenticator code instead' : 'Lost your device? Use a recovery code'}
            </button>
          ) : (
            <a href="/forgot-password" className="btn btn-ghost btn-sm">Forgotten your password?</a>
          )}
        </form>

        <p className="small faint" style={{ marginTop: '1.25rem', textAlign: 'center' }}>
          Sign-in attempts are logged. Accounts lock after repeated failures.
        </p>
      </div>
    </div>
  );
}

/**
 * `useSearchParams` reads the post-sign-in destination from the URL, which
 * makes this a client-side read and requires a Suspense boundary for the build
 * to prerender the shell around it.
 */
export default function LoginPage() {
  return (
    <Suspense
      fallback={
        <div className="auth">
          <div className="auth__card">
            <div className="auth__brand">
              <strong>COLI<span>FEES</span> Control</strong>
            </div>
          </div>
        </div>
      }
    >
      <LoginForm />
    </Suspense>
  );
}
