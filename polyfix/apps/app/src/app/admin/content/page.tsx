'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useApi } from '@/lib/use-api';
import { useSession } from '@/lib/session';
import { post, ApiRequestError } from '@/lib/api-client';
import { StatusBadge, Loading, ErrorNotice, EmptyState, formatDate } from '@/components/ui';

interface ProductRow {
  id: string; slug: string; name: string; category: string; status: string; isFeatured: boolean;
  warrantyYears: number; comfortLevel: string; firmnessScore: number; sortOrder: number;
  updatedAt: string; variantCount: number; liveOnWebsite: boolean;
}

interface PageRow { id: string; slug: string; title: string; status: string; publishedAt: string | null; updatedAt: string }

/**
 * Website content.
 *
 * Products and pages an administrator can publish without touching code. The
 * API refuses to publish a product that has no sizes or no SEO record, so an
 * incomplete page cannot reach the public site and start ranking badly.
 */
export default function ContentPage() {
  const { can } = useSession();
  const products = useApi<{ products: ProductRow[] }>('ops/products');
  const pages = useApi<{ pages: PageRow[] }>('ops/pages');
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const setStatus = async (id: string, status: string) => {
    setBusy(id); setActionError(null);
    try {
      await post(`ops/products/${id}/publish`, { status });
      products.refresh();
    } catch (caught) {
      setActionError(caught instanceof ApiRequestError ? caught.info.message : 'Could not change the status.');
    } finally { setBusy(null); }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Products and content</h1>
          <p>What the public website shows. Changes appear within five minutes.</p>
        </div>
        <Link href="/admin/seo" className="btn btn-default btn-sm">SEO settings</Link>
      </div>

      {actionError ? <div style={{ marginBottom: '1rem' }}><ErrorNotice message={actionError} /></div> : null}

      <section className="card card-flush" style={{ marginBottom: '1.5rem' }} aria-labelledby="products-heading">
        <div className="panel-head"><h2 id="products-heading">Products</h2></div>

        {products.loading ? <div style={{ padding: '1rem' }}><Loading rows={4} /></div> : null}
        {products.error ? <div style={{ padding: '1rem' }}><ErrorNotice message={products.error.message} /></div> : null}

        {products.data ? (
          products.data.products.length === 0 ? (
            <EmptyState title="No products" />
          ) : (
            <div className="table-wrap">
              <table className="data responsive">
                <thead>
                  <tr>
                    <th scope="col">Product</th><th scope="col">Category</th><th scope="col">Sizes</th>
                    <th scope="col">Warranty</th><th scope="col">Status</th><th scope="col">Updated</th>
                    <th scope="col"><span className="visually-hidden">Action</span></th>
                  </tr>
                </thead>
                <tbody>
                  {products.data.products.map((row) => (
                    <tr key={row.id}>
                      <td data-label="Product" className="strong">
                        {row.name}
                        <div className="small muted mono">/mattresses/{row.slug}</div>
                      </td>
                      <td data-label="Category">{row.category}<div className="small muted">{row.comfortLevel} · {row.firmnessScore}/10</div></td>
                      <td data-label="Sizes">{row.variantCount}</td>
                      <td data-label="Warranty">{row.warrantyYears} yrs</td>
                      <td data-label="Status">
                        <StatusBadge status={row.status} />
                        {row.liveOnWebsite ? <div className="small" style={{ color: 'var(--positive)' }}>live</div> : null}
                      </td>
                      <td data-label="Updated" className="small muted nowrap">{formatDate(row.updatedAt)}</td>
                      <td data-label="Action">
                        {can('product:publish') ? (
                          row.status === 'PUBLISHED' ? (
                            <button type="button" className="btn btn-default btn-sm" disabled={busy === row.id}
                              onClick={() => setStatus(row.id, 'DRAFT')}>Unpublish</button>
                          ) : (
                            <button type="button" className="btn btn-primary btn-sm" disabled={busy === row.id}
                              onClick={() => setStatus(row.id, 'PUBLISHED')}>Publish</button>
                          )
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        ) : null}
      </section>

      <section className="card card-flush" aria-labelledby="pages-heading">
        <div className="panel-head"><h2 id="pages-heading">Pages</h2></div>

        {pages.loading ? <div style={{ padding: '1rem' }}><Loading rows={3} /></div> : null}
        {pages.data ? (
          <div className="table-wrap">
            <table className="data responsive">
              <thead>
                <tr><th scope="col">Page</th><th scope="col">Status</th><th scope="col">Published</th><th scope="col">Updated</th></tr>
              </thead>
              <tbody>
                {pages.data.pages.map((row) => (
                  <tr key={row.id}>
                    <td data-label="Page" className="strong">{row.title}<div className="small muted mono">/{row.slug}</div></td>
                    <td data-label="Status"><StatusBadge status={row.status} /></td>
                    <td data-label="Published" className="small muted nowrap">{formatDate(row.publishedAt)}</td>
                    <td data-label="Updated" className="small muted nowrap">{formatDate(row.updatedAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>
    </>
  );
}
