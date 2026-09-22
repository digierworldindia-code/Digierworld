/**
 * Sale and warranty activation.
 *
 * Recording a sale does four things atomically: it creates or matches the
 * customer, records the sale, starts the warranty, and moves the mattress to
 * SOLD. Either all of it happens or none of it does — there is no state where a
 * mattress is marked sold without a warranty behind it.
 *
 * Customer contact details are encrypted at rest with AES-256-GCM. Alongside
 * each encrypted column sits a keyed HMAC ("blind index") of the normalised
 * value, which is what the system matches on. That gives duplicate detection
 * and fraud-pattern checks without keeping a searchable plaintext phone book in
 * the database.
 */
import { Prisma, type TransactionClient } from '@colifees/database';
import { getConfig } from '@colifees/config';
import { blindIndex, encryptField, normalisePhone, normaliseForIndex } from '@colifees/auth';
import { errors } from '../lib/errors.js';
import { recordAudit, recordVersion, type AuditActor } from './audit.js';
import { appendEvent, assertTransition, type MattressStatus } from './mattress.service.js';

export interface CustomerInput {
  fullName: string;
  phone: string;
  email?: string | undefined;
  addressLine?: string | undefined;
  city?: string | undefined;
  state?: string | undefined;
  pincode?: string | undefined;
}

/**
 * Finds the dealer's existing record for this phone number, or creates one.
 * Matching is by blind index, so it works without ever decrypting a column.
 */
export async function upsertCustomer(
  tx: TransactionClient,
  actor: AuditActor,
  dealerId: string,
  input: CustomerInput,
): Promise<{ id: string; created: boolean }> {
  const config = getConfig();
  const indexKey = config.env.SIGNING_SECRET;
  const phoneHash = blindIndex(normalisePhone(input.phone), indexKey);

  const existing = await tx.customer.findFirst({
    where: { dealerId, phoneHash, deletedAt: null },
    select: { id: true, fullName: true, city: true, state: true, pincode: true },
  });

  if (existing) {
    // A name that differs from the stored one is a change worth keeping, not a
    // silent overwrite: the previous value is versioned first.
    if (existing.fullName !== input.fullName) {
      await recordVersion(tx, 'customer', existing.id, existing, actor.userId ?? null, 'name updated during sale entry');
      await tx.customer.update({
        where: { id: existing.id },
        data: { fullName: input.fullName, updatedById: actor.userId ?? null },
      });
      await recordAudit(tx, actor, {
        action: 'CUSTOMER_UPDATED',
        entity: 'customer',
        entityId: existing.id,
        previousValue: { fullName: existing.fullName },
        newValue: { fullName: input.fullName },
        reason: 'name differed from stored record at point of sale',
      });
    }
    return { id: existing.id, created: false };
  }

  const customer = await tx.customer.create({
    data: {
      dealerId,
      fullName: input.fullName,
      phoneEncrypted: encryptField(input.phone, config.encryptionKey),
      phoneHash,
      emailEncrypted: input.email ? encryptField(input.email, config.encryptionKey) : null,
      emailHash: input.email ? blindIndex(input.email, indexKey) : null,
      addressLine: input.addressLine ?? null,
      addressHash: input.addressLine ? blindIndex(normaliseForIndex(input.addressLine), indexKey) : null,
      city: input.city ?? null,
      state: input.state ?? null,
      pincode: input.pincode ?? null,
      createdById: actor.userId ?? null,
    },
    select: { id: true },
  });

  await recordAudit(tx, actor, {
    action: 'CUSTOMER_CREATED',
    entity: 'customer',
    entityId: customer.id,
    // The audit trail records that a customer was created, not their contact
    // details.
    newValue: { fullName: input.fullName, city: input.city ?? null },
  });

  return { id: customer.id, created: true };
}

export interface RecordSaleResult {
  saleId: string;
  warrantyId: string;
  serialNumber: string;
  warrantyStart: string;
  warrantyEnd: string;
  warrantyYears: number;
}

export async function recordSale(
  tx: TransactionClient,
  actor: AuditActor,
  dealerId: string,
  input: {
    serialNumber: string;
    invoiceNumber: string;
    soldAt: string;
    salePrice: number;
    paymentMode: string;
    customer: CustomerInput;
    remarks?: string | undefined;
  },
): Promise<RecordSaleResult> {
  const mattress = await tx.mattress.findFirst({
    where: { serialNumber: input.serialNumber, deletedAt: null },
    select: {
      id: true,
      serialNumber: true,
      currentStatus: true,
      currentDealerId: true,
      receivedAt: true,
      productVariant: { select: { id: true, product: { select: { warrantyYears: true, name: true } } } },
    },
  });

  // A serial the dealer does not hold is invisible to them under Row Level
  // Security, so this reads as "not found" rather than "belongs to someone
  // else" — which is also what we want an attacker to learn.
  if (!mattress) throw errors.notFound('mattress');

  if (mattress.currentDealerId !== dealerId) {
    throw errors.notFound('mattress', 'serial belongs to another dealer');
  }
  if (mattress.currentStatus !== 'DEALER_RECEIVED') {
    throw errors.businessRule(
      mattress.currentStatus === 'SOLD'
        ? 'This mattress has already been sold.'
        : `This mattress cannot be sold while it is ${mattress.currentStatus.toLowerCase().replace(/_/g, ' ')}.`,
    );
  }

  const soldAt = new Date(input.soldAt);
  if (Number.isNaN(soldAt.getTime())) throw errors.businessRule('The sale date is not a valid date.');
  // A little tolerance for time zones, but not a date in the future.
  if (soldAt.getTime() > Date.now() + 24 * 60 * 60 * 1000) {
    throw errors.businessRule('The sale date cannot be in the future.');
  }
  if (mattress.receivedAt && soldAt.getTime() < mattress.receivedAt.getTime() - 24 * 60 * 60 * 1000) {
    throw errors.businessRule('The sale date cannot be before the date this mattress was received.');
  }

  const duplicate = await tx.sale.findFirst({
    where: { dealerId, invoiceNumber: input.invoiceNumber, deletedAt: null },
    select: { id: true },
  });
  if (duplicate) {
    throw errors.conflict('That invoice number has already been used for another sale.');
  }

  const customer = await upsertCustomer(tx, actor, dealerId, input.customer);

  const sale = await tx.sale.create({
    data: {
      dealerId,
      mattressId: mattress.id,
      customerId: customer.id,
      invoiceNumber: input.invoiceNumber,
      soldAt,
      salePrice: new Prisma.Decimal(input.salePrice),
      paymentMode: input.paymentMode,
      soldByUserId: actor.userId ?? '',
      remarks: input.remarks ?? null,
      createdById: actor.userId ?? null,
    },
    select: { id: true },
  });

  const years = mattress.productVariant.product.warrantyYears;
  const startDate = new Date(Date.UTC(soldAt.getUTCFullYear(), soldAt.getUTCMonth(), soldAt.getUTCDate()));
  const endDate = new Date(startDate);
  endDate.setUTCFullYear(endDate.getUTCFullYear() + years);

  const termsVersion = await readSetting(tx, 'warranty.terms_version', 'v1.0');

  const warranty = await tx.warranty.create({
    data: {
      mattressId: mattress.id,
      saleId: sale.id,
      dealerId,
      customerId: customer.id,
      startDate,
      endDate,
      years,
      status: 'ACTIVE',
      termsVersion,
      createdById: actor.userId ?? null,
    },
    select: { id: true },
  });

  assertTransition(mattress.currentStatus as MattressStatus, 'SOLD');
  await tx.mattress.update({
    where: { id: mattress.id },
    data: { currentStatus: 'SOLD', soldAt, updatedById: actor.userId ?? null },
  });

  await appendEvent(tx, mattress.id, actor.userId ?? null, {
    eventType: 'SOLD',
    fromStatus: 'DEALER_RECEIVED',
    toStatus: 'SOLD',
    dealerId,
    payload: { invoiceNumber: input.invoiceNumber, warrantyYears: years },
  });

  await recordAudit(tx, actor, {
    action: 'SALE_RECORDED',
    entity: 'sale',
    entityId: sale.id,
    newValue: {
      serialNumber: mattress.serialNumber,
      invoiceNumber: input.invoiceNumber,
      soldAt: soldAt.toISOString(),
      salePrice: input.salePrice,
      warrantyEnd: endDate.toISOString().slice(0, 10),
    },
  });

  return {
    saleId: sale.id,
    warrantyId: warranty.id,
    serialNumber: mattress.serialNumber,
    warrantyStart: startDate.toISOString().slice(0, 10),
    warrantyEnd: endDate.toISOString().slice(0, 10),
    warrantyYears: years,
  };
}

export async function readSetting<T>(tx: TransactionClient, key: string, fallback: T): Promise<T> {
  const row = await tx.systemSetting.findUnique({ where: { key }, select: { value: true } });
  if (!row) return fallback;
  return row.value as unknown as T;
}
