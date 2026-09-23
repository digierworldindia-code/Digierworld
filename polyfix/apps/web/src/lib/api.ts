import 'server-only';

/**
 * Server-side API access.
 *
 * `server-only` at the top is load-bearing: importing this module from a client
 * component is a build error, so the internal address and the service token
 * cannot end up in the browser bundle by accident.
 *
 * Read calls are cached and revalidated on a timer, so a burst of traffic hits
 * the cache rather than the database. Write calls (form submissions) are
 * proxied through this app's own route handlers, never issued by the browser.
 */
const API_URL = (process.env.INTERNAL_API_URL ?? 'http://127.0.0.1:4000').replace(/\/$/, '');
const INTERNAL_TOKEN = process.env.INTERNAL_API_TOKEN ?? '';

export interface ApiOptions {
  /** Seconds before the cached copy is considered stale. */
  revalidate?: number;
  tags?: string[];
}

export class ApiUnavailableError extends Error {
  constructor(public readonly path: string, public readonly status?: number) {
    super(`content service unavailable for ${path}`);
    this.name = 'ApiUnavailableError';
  }
}

export async function apiGet<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const response = await fetch(`${API_URL}/public${path}`, {
    headers: {
      accept: 'application/json',
      ...(INTERNAL_TOKEN ? { 'x-internal-token': INTERNAL_TOKEN } : {}),
    },
    next: { revalidate: options.revalidate ?? 300, ...(options.tags ? { tags: options.tags } : {}) },
  }).catch(() => null);

  if (!response) throw new ApiUnavailableError(path);
  if (!response.ok) throw new ApiUnavailableError(path, response.status);
  return (await response.json()) as T;
}

/**
 * Read that tolerates the API being down.
 *
 * A marketing page should degrade rather than disappear: if the content service
 * is unreachable, the page still renders with its fallback copy and the build
 * still succeeds. Anything that genuinely needs live data (warranty
 * verification) uses `apiGet` and surfaces the failure instead.
 */
export async function apiGetOr<T>(path: string, fallback: T, options: ApiOptions = {}): Promise<T> {
  try {
    return await apiGet<T>(path, options);
  } catch {
    return fallback;
  }
}

export async function apiPost<T>(path: string, body: unknown): Promise<{ ok: boolean; status: number; data: T | null }> {
  const response = await fetch(`${API_URL}/public${path}`, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      accept: 'application/json',
      ...(INTERNAL_TOKEN ? { 'x-internal-token': INTERNAL_TOKEN } : {}),
    },
    body: JSON.stringify(body),
    cache: 'no-store',
  }).catch(() => null);

  if (!response) return { ok: false, status: 503, data: null };

  let data: T | null = null;
  try {
    data = (await response.json()) as T;
  } catch {
    data = null;
  }

  return { ok: response.ok, status: response.status, data };
}

// ---------------------------------------------------------------------------
// Shapes returned by the public API
// ---------------------------------------------------------------------------
export interface SeoPayload {
  title: string;
  description: string;
  canonicalPath: string | null;
  ogTitle: string | null;
  ogDescription: string | null;
  ogImageUrl: string | null;
  twitterCard: string;
  robotsIndex: boolean;
  robotsFollow: boolean;
  keywords: string[];
}

export interface SitePayload {
  seo: {
    title: string;
    description: string;
    keywords: string[];
    ogImageUrl: string | null;
    robotsIndex: boolean;
    robotsFollow: boolean;
  } | null;
  settings: Record<string, unknown>;
  faqs: { id: string; question: string; answer: string; category: string }[];
}

export interface PagePayload {
  page: {
    slug: string;
    title: string;
    hero: Record<string, any>;
    sections: Record<string, any>[];
    publishedAt: string | null;
  };
  seo: SeoPayload | null;
}

export interface ProductSize {
  sku?: string;
  label: string;
  widthIn: number;
  lengthIn: number;
  heightIn: number;
  mrp: number;
}

export interface ProductSummary {
  slug: string;
  name: string;
  category: string;
  tagline: string | null;
  shortDescription: string;
  comfortLevel: string;
  firmnessScore: number;
  warrantyYears: number;
  isFeatured: boolean;
  headline: string | null;
  subheadline: string | null;
  gallery: { src: string; alt: string; width: number; height: number }[];
  fromPrice: number | null;
  sizes: ProductSize[];
}

export interface ProductDetail extends Omit<ProductSummary, 'fromPrice'> {
  description: string;
  materials: string[];
  features: string[];
  specifications: Record<string, string>;
  careInstructions: string | null;
  trialNights: number | null;
  bodyHtml: string | null;
  highlights: { title: string; body: string }[];
  faqs: { question: string; answer: string }[];
}

export interface DealerSummary {
  businessName: string;
  address: string;
  city: string;
  state: string;
  pincode: string;
  phone: string;
  isShowroom: boolean;
  latitude: number | null;
  longitude: number | null;
}

export interface VerificationResult {
  found: boolean;
  genuine: boolean;
  serialNumber?: string;
  product?: { name: string; slug: string; category: string; size: string; comfortLevel: string };
  manufacturedOn?: string;
  warranty?: {
    status: 'NOT_ACTIVATED' | 'ACTIVE' | 'EXPIRED' | 'VOID' | 'SUPERSEDED';
    startDate: string | null;
    endDate: string | null;
    years: number | null;
    daysRemaining: number | null;
  };
  note: string;
}

export const getSite = () => apiGetOr<SitePayload>('/content/site', { seo: null, settings: {}, faqs: [] }, { revalidate: 300, tags: ['site'] });

export const getPage = (slug: string) =>
  apiGetOr<PagePayload | null>(`/content/pages/${slug}`, null, { revalidate: 300, tags: ['pages', `page:${slug}`] });

export const getProducts = () =>
  apiGetOr<{ products: ProductSummary[] }>('/content/products', { products: [] }, { revalidate: 300, tags: ['products'] });

export const getProduct = (slug: string) =>
  apiGetOr<{ product: ProductDetail; seo: SeoPayload | null } | null>(`/content/products/${slug}`, null, {
    revalidate: 300,
    tags: ['products', `product:${slug}`],
  });

export const getDealers = (query = '') =>
  apiGetOr<{ dealers: DealerSummary[] }>(`/dealers${query}`, { dealers: [] }, { revalidate: 600, tags: ['dealers'] });

export const getDealerRegions = () =>
  apiGetOr<{ regions: { state: string; city: string; dealers: number }[] }>('/dealers/regions', { regions: [] }, { revalidate: 900 });

export const getSitemapEntries = () =>
  apiGetOr<{ entries: { path: string; lastModified: string; priority: number; changefreq: string }[] }>(
    '/sitemap',
    { entries: [] },
    { revalidate: 3600 },
  );
