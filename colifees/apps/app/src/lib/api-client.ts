'use client';

/**
 * Console API client.
 *
 * Every call goes to this application's own `/api/bff/...` proxy, which
 * forwards to the private API. Three things are handled centrally here so that
 * no screen has to remember them:
 *
 *   1. the CSRF token is read from its cookie and sent as a header on every
 *      state-changing request
 *   2. a 401 clears local state and returns the person to sign-in rather than
 *      leaving a half-broken screen
 *   3. errors arrive as a typed object with the friendly message and any
 *      field-level detail, so forms can show problems next to the field
 */

export interface ApiError {
  code: string;
  message: string;
  details?: Record<string, string[]>;
  requestId?: string;
  status: number;
}

export class ApiRequestError extends Error {
  constructor(public readonly info: ApiError) {
    super(info.message);
    this.name = 'ApiRequestError';
  }
}

function readCsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/(?:^|;\s*)clf_csrf=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  /** Multipart uploads pass a FormData and skip JSON encoding. */
  formData?: FormData;
  signal?: AbortSignal;
}

export async function api<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = { accept: 'application/json' };

  if (method !== 'GET') {
    const csrf = readCsrfToken();
    if (csrf) headers['x-csrf-token'] = csrf;
  }

  let body: BodyInit | undefined;
  if (options.formData) {
    // The browser sets the multipart boundary; setting content-type by hand
    // here would break the upload.
    body = options.formData;
  } else if (options.body !== undefined) {
    headers['content-type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  const response = await fetch(`/api/bff/${path.replace(/^\//, '')}`, {
    method,
    headers,
    body,
    credentials: 'same-origin',
    cache: 'no-store',
    ...(options.signal ? { signal: options.signal } : {}),
  });

  const contentType = response.headers.get('content-type') ?? '';
  const payload = contentType.includes('application/json') ? await response.json().catch(() => null) : null;

  if (!response.ok) {
    const error = (payload as { error?: Partial<ApiError> } | null)?.error;
    const info: ApiError = {
      code: error?.code ?? 'UNKNOWN',
      message: error?.message ?? 'Something went wrong. Please try again.',
      status: response.status,
      ...(error?.details ? { details: error.details } : {}),
      ...(error?.requestId ? { requestId: error.requestId } : {}),
    };

    if (response.status === 401 && typeof window !== 'undefined' && !window.location.pathname.startsWith('/login')) {
      const next = encodeURIComponent(window.location.pathname + window.location.search);
      window.location.href = `/login?next=${next}`;
    }

    throw new ApiRequestError(info);
  }

  return payload as T;
}

export const get = <T>(path: string) => api<T>(path);
export const post = <T>(path: string, body?: unknown) => api<T>(path, { method: 'POST', body });
export const patch = <T>(path: string, body?: unknown) => api<T>(path, { method: 'PATCH', body });
export const put = <T>(path: string, body?: unknown) => api<T>(path, { method: 'PUT', body });
export const del = <T>(path: string, body?: unknown) => api<T>(path, { method: 'DELETE', body });
export const upload = <T>(path: string, formData: FormData) => api<T>(path, { method: 'POST', formData });

export interface Page<T> {
  items: T[];
  page: number;
  pageSize: number;
  total: number;
  totalPages: number;
}
