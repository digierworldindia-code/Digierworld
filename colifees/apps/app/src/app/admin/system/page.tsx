'use client';

import { useState } from 'react';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { Kpi, Loading, ErrorNotice, Field, formatDate } from '@/components/ui';

interface Health {
  database: { version: string; size: string; activeConnections: number };
  migrations: { applied: { name: string; appliedAt: string | null }[]; pending: number };
  records: { mattresses: number; dealers: number; claims: number; audits: number };
  lastBackup: { kind: string; startedAt: string; finishedAt: string | null; bytes: number | null; offsiteCopied: boolean; ageHours: number } | null;
  rowLevelSecurity: { policyCount: number; dealerTablesWithoutRls: string[]; healthy: boolean };
  warnings: string[];
}

/**
 * System and database tools.
 *
 * Everything here needs a permission that is additionally gated on two-factor
 * authentication, so a stolen password alone cannot trigger a backup, an export
 * or a query. The SQL console is read-only: statements are shape-checked before
 * they are sent, executed inside a READ ONLY transaction with a statement
 * timeout, and every execution is written to the audit trail with its text.
 */
export default function SystemPage() {
  const { can, user } = useSession();
  const { data, error, loading, refresh } = useApi<Health>('ops/system/health');

  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [sql, setSql] = useState('SELECT current_status, count(*) AS units\nFROM mattresses\nWHERE deleted_at IS NULL\nGROUP BY current_status\nORDER BY units DESC;');
  const [queryResult, setQueryResult] = useState<{ rows: Record<string, unknown>[]; rowCount: number; truncated: boolean; durationMs: number } | null>(null);

  const backup = async () => {
    setBusy(true); setActionError(null); setMessage(null);
    try {
      const response = await post<{ status: string; bytes: number; checksum: string; note: string }>('ops/system/backups', { kind: 'MANUAL' });
      setMessage(`Backup ${response.status.toLowerCase()} · ${(response.bytes / 1024 / 1024).toFixed(1)} MB · ${response.note}`);
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not start the backup.');
    } finally { setBusy(false); }
  };

  const runQuery = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true); setActionError(null); setQueryResult(null);
    try {
      setQueryResult(await post('ops/system/query', { sql, maxRows: 100 }));
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not run the query.');
    } finally { setBusy(false); }
  };

  if (loading) return <Loading rows={5} />;
  if (error) return <ErrorNotice message={error.message} requestId={error.requestId} />;
  if (!data) return null;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>System</h1>
          <p>Database health, migrations, backups and technical tools.</p>
        </div>
      </div>

      {data.warnings.length > 0 ? (
        <div className="notice notice-caution" style={{ marginBottom: '1rem' }}>
          <p><strong>Needs attention</strong></p>
          <ul style={{ marginTop: '0.4rem', paddingLeft: '1.1rem' }}>
            {data.warnings.map((warning) => <li key={warning} className="small">{warning}</li>)}
          </ul>
        </div>
      ) : null}

      {message ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{message}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      <div className="grid grid-4">
        <Kpi label="PostgreSQL" value={data.database.version} hint={`${data.database.size} on disk`} />
        <Kpi label="Connections" value={data.database.activeConnections} hint="Active right now" />
        <Kpi label="Migrations" value={data.migrations.applied.length} hint={data.migrations.pending > 0 ? `${data.migrations.pending} pending` : 'All applied'} alert={data.migrations.pending > 0} />
        <Kpi label="Audit entries" value={data.records.audits.toLocaleString('en-IN')} hint="Append-only" />
      </div>

      <div className="split" style={{ marginTop: '1.5rem' }}>
        <div className="stack">
          <section className="card" aria-labelledby="rls-heading">
            <h2 id="rls-heading" style={{ marginBottom: '0.75rem' }}>Row Level Security</h2>
            {data.rowLevelSecurity.healthy ? (
              <p className="notice notice-positive">
                {data.rowLevelSecurity.policyCount} policies active. Every table holding dealer data has
                Row Level Security enabled.
              </p>
            ) : (
              <p className="notice notice-critical">
                These tables hold dealer data with no Row Level Security:{' '}
                {data.rowLevelSecurity.dealerTablesWithoutRls.join(', ')}. Re-run migration 0002.
              </p>
            )}
          </section>

          {can('system:sql:read') ? (
            <section className="card stack" aria-labelledby="sql-heading">
              <h2 id="sql-heading">Read-only SQL</h2>
              <p className="small muted">
                SELECT and WITH only, one statement, inside a READ ONLY transaction with a 10 second
                timeout. Every query is recorded in the audit trail against your name.
              </p>

              {!user?.mfaEnabled ? (
                <p className="notice notice-caution">
                  Turn on two-factor authentication to use this tool.
                </p>
              ) : null}

              <form onSubmit={runQuery} className="stack">
                <Field label="Query" name="sql" as="textarea" value={sql}
                  onChange={(event) => setSql(event.target.value)}
                  style={{ minHeight: '9rem', fontFamily: 'var(--mono)', fontSize: '0.85rem' }} />
                <button type="submit" className="btn btn-primary" style={{ alignSelf: 'flex-start' }}
                  disabled={busy || !user?.mfaEnabled}>
                  {busy ? 'Running…' : 'Run query'}
                </button>
              </form>

              {queryResult ? (
                <div>
                  <p className="small muted">
                    {queryResult.rowCount} row{queryResult.rowCount === 1 ? '' : 's'} in {queryResult.durationMs} ms
                    {queryResult.truncated ? ' (showing the first 100)' : ''}
                  </p>
                  <div className="table-wrap" style={{ marginTop: '0.5rem', maxHeight: '22rem' }}>
                    <table className="data">
                      <thead>
                        <tr>
                          {Object.keys(queryResult.rows[0] ?? {}).map((column) => (
                            <th key={column} scope="col">{column}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {queryResult.rows.map((row, index) => (
                          <tr key={index}>
                            {Object.values(row).map((value, columnIndex) => (
                              <td key={columnIndex} className="mono small">{value === null ? '—' : String(value)}</td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ) : null}
            </section>
          ) : null}
        </div>

        <div className="stack">
          <section className="card" aria-labelledby="backup-heading">
            <h2 id="backup-heading" style={{ marginBottom: '0.75rem' }}>Backups</h2>
            {data.lastBackup ? (
              <dl className="dl">
                <div><dt>Last successful</dt><dd>{formatDate(data.lastBackup.startedAt, true)}</dd></div>
                <div><dt>Age</dt><dd>{data.lastBackup.ageHours} hours</dd></div>
                <div><dt>Kind</dt><dd>{data.lastBackup.kind.toLowerCase()}</dd></div>
                <div><dt>Size</dt><dd>{data.lastBackup.bytes ? `${(data.lastBackup.bytes / 1024 / 1024).toFixed(1)} MB` : '—'}</dd></div>
                <div>
                  <dt>Copied off-site</dt>
                  <dd>{data.lastBackup.offsiteCopied ? 'yes' : <span style={{ color: 'var(--caution)' }}>not yet</span>}</dd>
                </div>
              </dl>
            ) : (
              <p className="notice notice-caution">No successful backup has been recorded.</p>
            )}

            {can('system:backup') ? (
              <button type="button" className="btn btn-primary btn-block" style={{ marginTop: '1rem' }}
                onClick={backup} disabled={busy || !user?.mfaEnabled}>
                {busy ? 'Running…' : 'Run a backup now'}
              </button>
            ) : null}

            <p className="small faint" style={{ marginTop: '0.6rem' }}>
              Scheduled backups run from cron using the same script. A dump kept only on this server
              is not a backup.
            </p>
          </section>

          <section className="card" aria-labelledby="records-heading">
            <h2 id="records-heading" style={{ marginBottom: '0.75rem' }}>Records</h2>
            <dl className="dl">
              <div><dt>Mattresses</dt><dd>{data.records.mattresses.toLocaleString('en-IN')}</dd></div>
              <div><dt>Dealers</dt><dd>{data.records.dealers.toLocaleString('en-IN')}</dd></div>
              <div><dt>Claims</dt><dd>{data.records.claims.toLocaleString('en-IN')}</dd></div>
            </dl>
          </section>

          <section className="card" aria-labelledby="migrations-heading">
            <h2 id="migrations-heading" style={{ marginBottom: '0.75rem' }}>Recent migrations</h2>
            <div className="stack-sm">
              {data.migrations.applied.map((migration) => (
                <div key={migration.name} className="small">
                  <span className="mono">{migration.name}</span>
                  <div className="faint">{migration.appliedAt ? formatDate(migration.appliedAt, true) : 'pending'}</div>
                </div>
              ))}
            </div>
          </section>
        </div>
      </div>
    </>
  );
}
