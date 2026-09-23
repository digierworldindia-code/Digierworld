/**
 * Request-scoped database context — the second half of dealer isolation.
 *
 * Application code filters queries by dealer. That alone is one typo away from
 * a data leak, so every request also runs inside a transaction that publishes
 * the caller's identity to PostgreSQL:
 *
 *     app.user_id    -- who is asking
 *     app.dealer_id  -- 'ALL' for staff, or the dealer's UUID
 *
 * Row Level Security policies (migration `security_hardening`) read
 * `app.dealer_id` and fail **closed**: if the setting is absent the policy
 * expression evaluates to NULL and no rows are visible at all. A query written
 * without this wrapper therefore returns nothing rather than everything.
 */
import type { PrismaClient, Prisma } from '@prisma/client';

export type TransactionClient = Prisma.TransactionClient;

export interface DbActor {
  /** Authenticated user id, or null for public/unauthenticated traffic. */
  userId: string | null;
  /**
   * Dealer the caller is confined to. `null` means "staff", which publishes
   * the STAFF_SCOPE token. It must be derived from the session on the server —
   * never from a request body, query string or header.
   */
  dealerId: string | null;
}

/**
 * The token that grants unrestricted (staff) visibility.
 *
 * It is a deliberate, recognisable value rather than the empty string.
 * PostgreSQL reverts a transaction-local custom setting to the empty string
 * once the setting exists in the session, so on a pooled connection the empty
 * string is exactly the value a *forgotten* scope leaves behind. Giving it
 * meaning would turn an oversight into unrestricted access. See migration
 * 0005 for the demonstration.
 */
export const STAFF_SCOPE = 'ALL' as const;

export interface WithDbContextOptions {
  /** Passed through to Prisma's interactive transaction. */
  timeoutMs?: number;
  maxWaitMs?: number;
  isolationLevel?: Prisma.TransactionIsolationLevel;
}

/**
 * Runs `fn` inside a transaction with the caller's scope applied at the
 * database level. Always use the `tx` handed to the callback; reaching for the
 * outer client inside the callback bypasses the context.
 */
export async function withDbContext<T>(
  prisma: PrismaClient,
  actor: DbActor,
  fn: (tx: TransactionClient) => Promise<T>,
  options: WithDbContextOptions = {},
): Promise<T> {
  return prisma.$transaction(
    async (tx) => {
      await applyDbContext(tx, actor);
      return fn(tx);
    },
    {
      timeout: options.timeoutMs ?? 15_000,
      maxWait: options.maxWaitMs ?? 5_000,
      ...(options.isolationLevel ? { isolationLevel: options.isolationLevel } : {}),
    },
  );
}

/**
 * Sets the transaction-local settings the RLS policies read. Values are bound
 * as parameters, so a dealer id can never be used to inject SQL.
 */
export async function applyDbContext(tx: TransactionClient, actor: DbActor): Promise<void> {
  const dealerScope = actor.dealerId ?? STAFF_SCOPE;
  const userScope = actor.userId ?? '';
  await tx.$executeRaw`SELECT set_config('app.dealer_id', ${dealerScope}, true)`;
  await tx.$executeRaw`SELECT set_config('app.user_id', ${userScope}, true)`;
}

/**
 * Runs a unit of work with staff-level visibility.
 *
 * Needed by the few paths that must read dealer-scoped tables *before* a
 * caller's scope is known — above all authentication, which has to look up the
 * user's dealer link in order to decide what their scope is. Without this the
 * lookup runs unscoped, the `dealer_users` row is correctly hidden, and the
 * dealer is silently treated as having no dealer at all.
 *
 * Use it only where that reasoning applies. Anything acting on behalf of a
 * signed-in caller belongs in `withDbContext` with that caller's scope.
 */
export async function withStaffContext<T>(
  prisma: PrismaClient,
  fn: (tx: TransactionClient) => Promise<T>,
  options: WithDbContextOptions = {},
): Promise<T> {
  return withDbContext(prisma, { userId: null, dealerId: null }, fn, options);
}

/**
 * Read-only context for unauthenticated public endpoints (warranty lookup,
 * published catalogue). Staff-level row visibility is granted at the database
 * layer, and the route is responsible for selecting only publishable columns —
 * public responses are assembled from an explicit allow-list, never by
 * spreading a database row.
 */
export const PUBLIC_ACTOR: DbActor = { userId: null, dealerId: null };
