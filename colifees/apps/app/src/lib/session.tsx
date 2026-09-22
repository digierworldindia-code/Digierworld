'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { get, ApiRequestError } from './api-client';

/**
 * Signed-in user state.
 *
 * The permission list held here drives what the console *shows*. It is not a
 * security boundary: every action is authorised again by the API, which reads
 * roles and permissions from the database on each request. Hiding a button is a
 * courtesy to the user; refusing the request is the control.
 */
export interface SessionUser {
  id: string;
  email: string;
  fullName: string;
  phone: string | null;
  roles: string[];
  permissions: string[];
  mfaEnabled: boolean;
  mfaSatisfied: boolean;
  mustChangePassword: boolean;
  lastLoginAt: string | null;
  dealer: { id: string; code: string; businessName: string; city: string; state: string } | null;
}

interface SessionState {
  user: SessionUser | null;
  loading: boolean;
  /** Returns the refreshed user so callers can route on it immediately. */
  reload: () => Promise<SessionUser | null>;
  can: (permission: string) => boolean;
  canAny: (...permissions: string[]) => boolean;
  isDealer: boolean;
}

const SessionContext = createContext<SessionState | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<SessionUser | null>(null);
  const [loading, setLoading] = useState(true);

  const reload = useCallback(async (): Promise<SessionUser | null> => {
    try {
      const response = await get<{ user: SessionUser }>('auth/me');
      setUser(response.user);
      // Returned as well as stored: a caller that routes immediately after
      // reloading would otherwise read the previous render's stale value and
      // send a dealer to the staff console.
      return response.user;
    } catch (error) {
      // A 401 here is expected on the sign-in page and is not an error state.
      if (!(error instanceof ApiRequestError) || error.info.status !== 401) {
        console.error('could not load session', error);
      }
      setUser(null);
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void reload();
  }, [reload]);

  const value = useMemo<SessionState>(
    () => ({
      user,
      loading,
      reload,
      can: (permission) => user?.permissions.includes(permission) ?? false,
      canAny: (...permissions) => permissions.some((permission) => user?.permissions.includes(permission) ?? false),
      isDealer: user?.dealer !== null && user?.dealer !== undefined,
    }),
    [user, loading, reload],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionState {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession must be used inside SessionProvider');
  return context;
}
