'use client';

import { useState } from 'react';
import { useApi, query } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, Pagination, Field, formatDate } from '@/components/ui';
import type { Page } from '@/lib/api-client';

interface UserRow {
  id: string; email: string; fullName: string; phone: string | null; status: string;
  mfaEnabled: boolean; locked: boolean; roles: string[]; dealer: string | null;
  lastLoginAt: string | null; createdAt: string;
}

const ROLES = ['ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'REPORTING', 'DEALER'];

/**
 * Users and roles.
 *
 * New accounts are created with a generated one-time password shown once on
 * screen. It is never emailed by this system and never stored in readable form:
 * whoever creates the account hands it over in person or by phone, and the
 * holder must replace it at first sign-in.
 */
export default function UsersPage() {
  const { can, user: currentUser } = useSession();
  const [page, setPage] = useState(1);
  const { data, error, loading, refresh } = useApi<Page<UserRow>>(`ops/users${query({ page, pageSize: 25 })}`, [page]);

  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ email: '', fullName: '', phone: '', roleKey: 'WAREHOUSE', dealerId: '' });
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [credential, setCredential] = useState<{ email: string; password: string } | null>(null);

  const dealers = useApi<Page<{ id: string; code: string; businessName: string; city: string }>>(
    form.roleKey === 'DEALER' ? 'ops/dealers?pageSize=100&status=ACTIVE' : null,
    [form.roleKey],
  );

  const create = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true); setActionError(null); setCredential(null);
    try {
      const response = await post<{ user: { email: string }; temporaryPassword: string }>('ops/users', {
        email: form.email.trim().toLowerCase(),
        fullName: form.fullName.trim(),
        ...(form.phone ? { phone: form.phone } : {}),
        roleKeys: [form.roleKey],
        ...(form.roleKey === 'DEALER' && form.dealerId ? { dealerId: form.dealerId } : {}),
      });
      setCredential({ email: response.user.email, password: response.temporaryPassword });
      setCreating(false);
      setForm({ email: '', fullName: '', phone: '', roleKey: 'WAREHOUSE', dealerId: '' });
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not create the account.');
    } finally { setBusy(false); }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Users and roles</h1>
          <p>Who can sign in, and what each of them may do.</p>
        </div>
        {can('user:write') && !creating ? (
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)}>New user</button>
        ) : null}
      </div>

      {credential ? (
        <div className="notice notice-caution" role="status" style={{ marginBottom: '1rem' }}>
          <p><strong>Account created for {credential.email}.</strong></p>
          <p className="mono" style={{ fontSize: '1.05rem', margin: '0.5rem 0' }}>{credential.password}</p>
          <p className="small">
            This password is shown once. Hand it over in person or by phone, not by email. The holder
            must change it at first sign-in.
          </p>
          <button type="button" className="btn btn-default btn-sm" style={{ marginTop: '0.5rem' }} onClick={() => setCredential(null)}>
            I have noted it
          </button>
        </div>
      ) : null}

      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      {creating ? (
        <form onSubmit={create} className="card stack" style={{ marginBottom: '1.25rem' }} noValidate>
          <h2 style={{ fontSize: '1rem' }}>New user</h2>
          <div className="form-grid form-grid-2">
            <Field label="Full name" name="fullName" required value={form.fullName}
              onChange={(event) => setForm((c) => ({ ...c, fullName: event.target.value }))} />
            <Field label="Email address" name="email" type="email" required value={form.email}
              onChange={(event) => setForm((c) => ({ ...c, email: event.target.value }))} />
          </div>
          <div className="form-grid form-grid-2">
            <Field label="Phone" name="phone" type="tel" value={form.phone}
              onChange={(event) => setForm((c) => ({ ...c, phone: event.target.value }))} />
            <Field label="Role" name="roleKey" as="select" required value={form.roleKey}
              onChange={(event) => setForm((c) => ({ ...c, roleKey: event.target.value, dealerId: '' }))}
              options={ROLES.map((role) => ({ value: role, label: role.replace(/_/g, ' ').toLowerCase() }))}
              hint="You cannot create an account more senior than your own." />
          </div>

          {form.roleKey === 'DEALER' ? (
            <Field label="Dealer" name="dealerId" as="select" required value={form.dealerId}
              onChange={(event) => setForm((c) => ({ ...c, dealerId: event.target.value }))}
              options={[{ value: '', label: 'Select a dealer' }, ...(dealers.data?.items ?? []).map((d) => ({ value: d.id, label: `${d.businessName} (${d.code})` }))]}
              hint="A dealer login is confined to this dealer's records and cannot hold a staff role." />
          ) : null}

          <div className="row">
            <button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Creating…' : 'Create user'}</button>
            <button type="button" className="btn btn-ghost" onClick={() => setCreating(false)}>Cancel</button>
          </div>
        </form>
      ) : null}

      {loading ? <Loading rows={5} /> : null}
      {error ? <ErrorNotice message={error.message} requestId={error.requestId} /> : null}

      {data && !loading ? (
        <div className="card card-flush">
          <div className="table-wrap">
            <table className="data responsive">
              <thead>
                <tr>
                  <th scope="col">Name</th><th scope="col">Roles</th><th scope="col">Status</th>
                  <th scope="col">Two-factor</th><th scope="col">Last signed in</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((row) => (
                  <tr key={row.id}>
                    <td data-label="Name" className="strong">
                      {row.fullName}{row.id === currentUser?.id ? <span className="small muted"> (you)</span> : null}
                      <div className="small muted">{row.email}</div>
                      {row.dealer ? <div className="small muted">{row.dealer}</div> : null}
                    </td>
                    <td data-label="Roles" className="small">
                      {row.roles.map((role) => role.replace(/_/g, ' ').toLowerCase()).join(', ')}
                    </td>
                    <td data-label="Status">
                      <StatusBadge status={row.status} />
                      {row.locked ? <div className="small" style={{ color: 'var(--critical)' }}>locked</div> : null}
                    </td>
                    <td data-label="Two-factor">
                      {row.mfaEnabled
                        ? <span className="badge badge-positive">on</span>
                        : <span className="badge badge-caution">off</span>}
                    </td>
                    <td data-label="Last signed in" className="small muted nowrap">{formatDate(row.lastLoginAt, true)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div style={{ padding: '0 1.25rem 1rem' }}>
            <Pagination page={data.page} totalPages={data.totalPages} total={data.total} onChange={setPage} />
          </div>
        </div>
      ) : null}
    </>
  );
}
