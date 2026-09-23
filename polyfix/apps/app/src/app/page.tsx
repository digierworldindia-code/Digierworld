'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useSession } from '@/lib/session';
import { Loading } from '@/components/ui';

/**
 * Entry point.
 *
 * Sends each person to the area they belong in. The routing decision is made
 * from the session the API returned, not from anything the browser chose, and
 * every destination independently refuses a caller who should not be there.
 */
export default function IndexPage() {
  const { user, loading } = useSession();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace('/login');
      return;
    }
    if (user.mustChangePassword) {
      router.replace('/security/change-password');
      return;
    }
    router.replace(user.dealer ? '/dealer' : '/admin');
  }, [user, loading, router]);

  return (
    <div className="content">
      <Loading rows={4} />
    </div>
  );
}
