import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { noIndexMetadata } from '@/lib/seo';

/**
 * The address encoded in every QR label.
 *
 * It carries a token that identifies one specific mattress, so it is marked
 * noindex: a search engine has no business holding a page keyed to an
 * individual's product. The request is forwarded to the warranty page, which
 * runs the lookup and renders the result.
 */
export const metadata: Metadata = noIndexMetadata(
  'Verify mattress',
  'Check whether a COLIFEES mattress is genuine and whether its warranty is active.',
);

export default async function VerifyRedirectPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>;
}) {
  const { q } = await searchParams;
  const token = typeof q === 'string' && /^[A-Za-z0-9_-]{22,64}$/.test(q) ? q : null;
  redirect(token ? `/warranty?q=${encodeURIComponent(token)}` : '/warranty');
}
