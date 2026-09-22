'use client';

import { useState } from 'react';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { put, ApiRequestError } from '@/lib/api-client';
import { Loading, ErrorNotice, Field } from '@/components/ui';

interface SeoRecord {
  id: string; scope: 'GLOBAL' | 'PAGE' | 'PRODUCT'; entityKey: string | null;
  title: string; description: string; canonicalPath: string | null;
  ogTitle: string | null; ogDescription: string | null; ogImageUrl: string | null;
  twitterCard: string; robotsIndex: boolean; robotsFollow: boolean; keywords: string[];
  sitemapInclude: boolean; sitemapPriority: string; sitemapChangefreq: string;
}

/**
 * SEO settings.
 *
 * Titles, descriptions, canonical paths, social cards, indexing and sitemap
 * entries, all editable without a deploy. The character counts are the only
 * opinionated part: a title over about 60 characters and a description over
 * about 155 get truncated in results, so the counter turns amber before the
 * damage is done.
 */
export default function SeoPage() {
  const { can } = useSession();
  const { data, error, loading, refresh } = useApi<{ seo: SeoRecord[] }>('ops/seo');
  const [editing, setEditing] = useState<string | null>(null);
  const [form, setForm] = useState<Partial<SeoRecord>>({});
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const startEdit = (record: SeoRecord) => {
    setEditing(record.id);
    setForm(record);
    setMessage(null);
  };

  const save = async (record: SeoRecord) => {
    setBusy(true); setActionError(null);
    try {
      await put(`ops/seo/${record.scope}/${record.entityKey ?? 'site'}`, {
        title: form.title,
        description: form.description,
        ...(form.canonicalPath ? { canonicalPath: form.canonicalPath } : {}),
        ...(form.ogTitle ? { ogTitle: form.ogTitle } : {}),
        ...(form.ogDescription ? { ogDescription: form.ogDescription } : {}),
        ...(form.ogImageUrl ? { ogImageUrl: form.ogImageUrl } : {}),
        twitterCard: form.twitterCard ?? 'summary_large_image',
        robotsIndex: form.robotsIndex ?? true,
        robotsFollow: form.robotsFollow ?? true,
        keywords: form.keywords ?? [],
        sitemapInclude: form.sitemapInclude ?? true,
        sitemapPriority: Number(form.sitemapPriority ?? 0.5),
        sitemapChangefreq: form.sitemapChangefreq ?? 'monthly',
      });
      setMessage('Saved. The public site picks this up within five minutes.');
      setEditing(null);
      refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not save.');
    } finally { setBusy(false); }
  };

  if (loading) return <Loading rows={5} />;
  if (error) return <ErrorNotice message={error.message} requestId={error.requestId} />;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>SEO</h1>
          <p>Titles, descriptions and indexing for every public page.</p>
        </div>
      </div>

      {message ? <p className="notice notice-positive" role="status" style={{ marginBottom: '1rem' }}>{message}</p> : null}
      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      <div className="stack">
        {(data?.seo ?? []).map((record) => {
          const isEditing = editing === record.id;
          const titleLength = (isEditing ? form.title : record.title)?.length ?? 0;
          const descriptionLength = (isEditing ? form.description : record.description)?.length ?? 0;

          return (
            <article className="card" key={record.id}>
              <div className="row-between">
                <div>
                  <h2 style={{ fontSize: '1rem' }}>
                    {record.scope === 'GLOBAL' ? 'Site defaults' : `${record.scope.toLowerCase()}: ${record.entityKey}`}
                  </h2>
                  <p className="small muted mono">{record.canonicalPath ?? '—'}</p>
                </div>
                <div className="row">
                  {!record.robotsIndex ? <span className="badge badge-caution">noindex</span> : null}
                  {!record.sitemapInclude ? <span className="badge badge-neutral">not in sitemap</span> : null}
                  {can('seo:write') && !isEditing ? (
                    <button type="button" className="btn btn-default btn-sm" onClick={() => startEdit(record)}>Edit</button>
                  ) : null}
                </div>
              </div>

              {isEditing ? (
                <div className="stack" style={{ marginTop: '1rem' }}>
                  <Field label="Meta title" name="title" value={form.title ?? ''} maxLength={200}
                    onChange={(event) => setForm((c) => ({ ...c, title: event.target.value }))}
                    hint={`${titleLength} characters · search results usually show about 60`} />

                  <Field label="Meta description" name="description" as="textarea" value={form.description ?? ''} maxLength={320}
                    onChange={(event) => setForm((c) => ({ ...c, description: event.target.value }))}
                    hint={`${descriptionLength} characters · search results usually show about 155`} />

                  <Field label="Canonical path" name="canonicalPath" value={form.canonicalPath ?? ''}
                    onChange={(event) => setForm((c) => ({ ...c, canonicalPath: event.target.value }))}
                    hint="Site-relative, for example /mattresses/aurea-hybrid" />

                  <Field label="Social image URL" name="ogImageUrl" value={form.ogImageUrl ?? ''}
                    onChange={(event) => setForm((c) => ({ ...c, ogImageUrl: event.target.value }))} />

                  <Field label="Keywords" name="keywords" value={(form.keywords ?? []).join(', ')}
                    onChange={(event) => setForm((c) => ({ ...c, keywords: event.target.value.split(',').map((k) => k.trim()).filter(Boolean) }))}
                    hint="Comma separated. Used for internal tracking; search engines ignore the keywords meta tag." />

                  <div className="row">
                    <label className="row" style={{ gap: '0.4rem' }}>
                      <input type="checkbox" checked={form.robotsIndex ?? true}
                        onChange={(event) => setForm((c) => ({ ...c, robotsIndex: event.target.checked }))} />
                      <span className="small">Allow search engines to index this page</span>
                    </label>
                    <label className="row" style={{ gap: '0.4rem' }}>
                      <input type="checkbox" checked={form.sitemapInclude ?? true}
                        onChange={(event) => setForm((c) => ({ ...c, sitemapInclude: event.target.checked }))} />
                      <span className="small">Include in sitemap.xml</span>
                    </label>
                  </div>

                  <div className="row">
                    <button type="button" className="btn btn-primary" disabled={busy} onClick={() => save(record)}>
                      {busy ? 'Saving…' : 'Save'}
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={() => setEditing(null)}>Cancel</button>
                  </div>
                </div>
              ) : (
                <div style={{ marginTop: '0.75rem' }}>
                  <p className="strong small">{record.title}</p>
                  <p className="small muted">{record.description}</p>
                </div>
              )}
            </article>
          );
        })}
      </div>
    </>
  );
}
