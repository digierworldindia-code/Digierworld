'use client';

import { useCallback, useEffect, useState } from 'react';
import { get, ApiRequestError, type ApiError } from './api-client';

/**
 * Data fetching for console screens.
 *
 * Deliberately small: a request, a loading flag, an error and a refresh. The
 * console has no offline mode and no optimistic updates, because a warehouse or
 * warranty screen showing a state the server has not accepted is worse than a
 * screen that waits.
 */
export interface QueryState<T> {
  data: T | null;
  error: ApiError | null;
  loading: boolean;
  refresh: () => void;
}

export function useApi<T>(path: string | null, deps: unknown[] = []): QueryState<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(Boolean(path));
  const [nonce, setNonce] = useState(0);

  const refresh = useCallback(() => setNonce((value) => value + 1), []);

  useEffect(() => {
    if (!path) {
      setLoading(false);
      return;
    }

    const controller = new AbortController();
    let active = true;

    setLoading(true);
    setError(null);

    get<T>(path)
      .then((result) => {
        if (active) setData(result);
      })
      .catch((caught: unknown) => {
        if (!active) return;
        if (caught instanceof ApiRequestError) setError(caught.info);
        else setError({ code: 'NETWORK', message: 'Could not reach the service.', status: 0 });
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
      controller.abort();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, nonce, ...deps]);

  return { data, error, loading, refresh };
}

/** Builds a query string, dropping empty values so URLs stay clean. */
export function query(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    search.set(key, String(value));
  }
  const result = search.toString();
  return result ? `?${result}` : '';
}
