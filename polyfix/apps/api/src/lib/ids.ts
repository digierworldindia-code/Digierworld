/**
 * Identifier issue.
 *
 * Serials, claim numbers, dispatch codes and batch codes all come from
 * PostgreSQL sequences through the functions created in migration 0002. No
 * caller — not the browser, not a service, not a script — proposes one.
 *
 * Two consequences worth stating:
 *   - identifiers cannot collide under concurrency, because a sequence is
 *     atomic and does not roll back
 *   - a client cannot pre-register a serial that does not exist yet, so a
 *     forged label has nothing to match against
 */
import type { TransactionClient } from '@polyfix/database';
import { randomToken } from '@polyfix/auth';

export async function nextSerialNumbers(tx: TransactionClient, count: number): Promise<string[]> {
  if (count < 1 || count > 5000) throw new Error(`refusing to issue ${count} serials in one call`);
  const rows = await tx.$queryRaw<{ serial: string }[]>`
    SELECT colifees_next_serial() AS serial FROM generate_series(1, ${count})
  `;
  return rows.map((r) => r.serial);
}

export async function nextClaimNumber(tx: TransactionClient): Promise<string> {
  const rows = await tx.$queryRaw<{ value: string }[]>`SELECT colifees_next_claim_number() AS value`;
  const value = rows[0]?.value;
  if (!value) throw new Error('claim number sequence returned nothing');
  return value;
}

export async function nextDispatchCode(tx: TransactionClient): Promise<string> {
  const rows = await tx.$queryRaw<{ value: string }[]>`SELECT colifees_next_dispatch_code() AS value`;
  const value = rows[0]?.value;
  if (!value) throw new Error('dispatch code sequence returned nothing');
  return value;
}

export async function nextBatchCode(tx: TransactionClient): Promise<string> {
  const rows = await tx.$queryRaw<{ value: string }[]>`SELECT colifees_next_batch_code() AS value`;
  const value = rows[0]?.value;
  if (!value) throw new Error('batch code sequence returned nothing');
  return value;
}

export async function nextDealerCode(tx: TransactionClient): Promise<string> {
  const rows = await tx.$queryRaw<{ value: string }[]>`SELECT colifees_next_dealer_code() AS value`;
  const value = rows[0]?.value;
  if (!value) throw new Error('dealer code sequence returned nothing');
  return value;
}

/**
 * The value encoded in the QR label. 192 bits of randomness, so the catalogue
 * cannot be walked by guessing, and distinct from the serial so that printing a
 * QR does not reveal how many units have been produced.
 */
export function newQrToken(): string {
  return randomToken(24);
}
