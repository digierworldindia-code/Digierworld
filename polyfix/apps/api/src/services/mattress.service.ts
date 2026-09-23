/**
 * Mattress lifecycle: manufacture, dispatch, dealer receipt.
 *
 * Every transition is a guarded state change, not a free-form update. A
 * handler cannot set `currentStatus` directly; it calls a transition, which
 * checks the current state, writes the new one, and appends a passport event in
 * the same transaction. The database backs this up with CHECK constraints, so
 * an impossible combination (sold with no sale date, in dealer custody with no
 * dealer) cannot be stored even by a bug or a manual query.
 */
import type { TransactionClient } from '@polyfix/database';
import { errors } from '../lib/errors.js';
import { nextSerialNumbers, nextDispatchCode, newQrToken } from '../lib/ids.js';
import { recordAudit, type AuditActor } from './audit.js';

export type MattressStatus =
  | 'MANUFACTURED'
  | 'IN_DISPATCH'
  | 'DISPATCHED'
  | 'DEALER_RECEIVED'
  | 'SOLD'
  | 'CLAIM_OPEN'
  | 'REPLACED'
  | 'RETURNED'
  | 'SCRAPPED';

/**
 * The only legal moves. Anything not listed here is rejected before it reaches
 * the database.
 */
const ALLOWED_TRANSITIONS: Record<MattressStatus, MattressStatus[]> = {
  MANUFACTURED: ['IN_DISPATCH', 'SCRAPPED'],
  IN_DISPATCH: ['DISPATCHED', 'MANUFACTURED'],
  DISPATCHED: ['DEALER_RECEIVED', 'RETURNED'],
  DEALER_RECEIVED: ['SOLD', 'RETURNED', 'SCRAPPED'],
  SOLD: ['CLAIM_OPEN', 'RETURNED'],
  CLAIM_OPEN: ['SOLD', 'REPLACED'],
  REPLACED: [],
  RETURNED: ['IN_DISPATCH', 'SCRAPPED'],
  SCRAPPED: [],
};

export function assertTransition(from: MattressStatus, to: MattressStatus): void {
  if (!ALLOWED_TRANSITIONS[from].includes(to)) {
    throw errors.businessRule(
      `A mattress that is ${humanise(from)} cannot become ${humanise(to)}.`,
      `illegal transition ${from} -> ${to}`,
    );
  }
}

function humanise(status: MattressStatus): string {
  return status.toLowerCase().replace(/_/g, ' ');
}

export interface PassportEvent {
  eventType: string;
  fromStatus?: MattressStatus | null;
  toStatus?: MattressStatus | null;
  dealerId?: string | null;
  payload?: Record<string, unknown>;
}

export async function appendEvent(
  tx: TransactionClient,
  mattressId: string,
  actorUserId: string | null,
  event: PassportEvent,
): Promise<void> {
  await tx.mattressEvent.create({
    data: {
      mattressId,
      eventType: event.eventType,
      fromStatus: event.fromStatus ?? null,
      toStatus: event.toStatus ?? null,
      actorUserId,
      dealerId: event.dealerId ?? null,
      payload: (event.payload ?? undefined) as never,
    },
  });
}

// ---------------------------------------------------------------------------
// Manufacture
// ---------------------------------------------------------------------------
export async function produceMattresses(
  tx: TransactionClient,
  actor: AuditActor,
  input: { batchId: string; items: { productVariantId: string; quantity: number }[] },
): Promise<{ batchCode: string; created: { serialNumber: string; productVariantId: string }[] }> {
  const batch = await tx.manufacturingBatch.findUnique({
    where: { id: input.batchId },
    select: { id: true, batchCode: true, warehouseId: true, manufacturedOn: true, producedQuantity: true },
  });
  if (!batch) throw errors.notFound('manufacturing batch');

  const total = input.items.reduce((sum, i) => sum + i.quantity, 0);
  if (total > 2000) {
    throw errors.businessRule('Produce at most 2000 units in a single operation.');
  }

  // Variants are resolved before any serial is issued, so a bad id cannot burn
  // sequence values.
  const variantIds = [...new Set(input.items.map((i) => i.productVariantId))];
  const variants = await tx.productVariant.findMany({
    where: { id: { in: variantIds }, deletedAt: null },
    select: { id: true },
  });
  if (variants.length !== variantIds.length) {
    throw errors.notFound('product variant', 'one or more variant ids did not resolve');
  }

  const serials = await nextSerialNumbers(tx, total);
  const manufacturedAt = batch.manufacturedOn;
  const rows: { serialNumber: string; qrToken: string; productVariantId: string }[] = [];

  let cursor = 0;
  for (const item of input.items) {
    for (let i = 0; i < item.quantity; i += 1) {
      const serialNumber = serials[cursor];
      cursor += 1;
      if (!serialNumber) throw errors.internal('ran out of issued serial numbers');
      rows.push({ serialNumber, qrToken: newQrToken(), productVariantId: item.productVariantId });
    }
  }

  await tx.mattress.createMany({
    data: rows.map((row) => ({
      serialNumber: row.serialNumber,
      qrToken: row.qrToken,
      productVariantId: row.productVariantId,
      batchId: batch.id,
      currentStatus: 'MANUFACTURED' as const,
      currentWarehouseId: batch.warehouseId,
      manufacturedAt,
      createdById: actor.userId ?? null,
    })),
  });

  const created = await tx.mattress.findMany({
    where: { serialNumber: { in: rows.map((r) => r.serialNumber) } },
    select: { id: true, serialNumber: true, productVariantId: true },
  });

  for (const mattress of created) {
    await appendEvent(tx, mattress.id, actor.userId ?? null, {
      eventType: 'MANUFACTURED',
      toStatus: 'MANUFACTURED',
      payload: { batchCode: batch.batchCode },
    });
  }

  await tx.manufacturingBatch.update({
    where: { id: batch.id },
    data: { producedQuantity: batch.producedQuantity + total },
  });

  await recordAudit(tx, actor, {
    action: 'MATTRESS_PRODUCED',
    entity: 'manufacturing_batch',
    entityId: batch.id,
    newValue: { batchCode: batch.batchCode, quantity: total, firstSerial: rows[0]?.serialNumber, lastSerial: rows[rows.length - 1]?.serialNumber },
  });

  return {
    batchCode: batch.batchCode,
    created: created.map((c) => ({ serialNumber: c.serialNumber, productVariantId: c.productVariantId })),
  };
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
export async function createDispatch(
  tx: TransactionClient,
  actor: AuditActor,
  input: {
    warehouseId: string;
    dealerId: string;
    serialNumbers: string[];
    expectedAt?: string | undefined;
    transporter?: string | undefined;
    lrNumber?: string | undefined;
    vehicleNumber?: string | undefined;
    remarks?: string | undefined;
  },
): Promise<{ id: string; dispatchCode: string; itemCount: number }> {
  const dealer = await tx.dealer.findFirst({
    where: { id: input.dealerId, deletedAt: null },
    select: { id: true, status: true, businessName: true },
  });
  if (!dealer) throw errors.notFound('dealer');
  if (dealer.status !== 'ACTIVE') {
    throw errors.businessRule(`${dealer.businessName} is not an active dealer, so stock cannot be dispatched to them.`);
  }

  const warehouse = await tx.warehouse.findFirst({ where: { id: input.warehouseId, isActive: true } });
  if (!warehouse) throw errors.notFound('warehouse');

  const unique = [...new Set(input.serialNumbers)];
  if (unique.length !== input.serialNumbers.length) {
    throw errors.businessRule('The same serial number appears more than once in this dispatch.');
  }

  const mattresses = await tx.mattress.findMany({
    where: { serialNumber: { in: unique }, deletedAt: null },
    select: { id: true, serialNumber: true, currentStatus: true, currentWarehouseId: true },
  });

  const found = new Set(mattresses.map((m) => m.serialNumber));
  const missing = unique.filter((s) => !found.has(s));
  if (missing.length > 0) {
    throw errors.businessRule(
      `${missing.length} serial number(s) are not in stock records: ${missing.slice(0, 5).join(', ')}${missing.length > 5 ? '…' : ''}`,
    );
  }

  const notAvailable = mattresses.filter(
    (m) => !(m.currentStatus === 'MANUFACTURED' || m.currentStatus === 'RETURNED'),
  );
  if (notAvailable.length > 0) {
    throw errors.businessRule(
      `${notAvailable.length} unit(s) are not available to dispatch: ${notAvailable
        .slice(0, 5)
        .map((m) => `${m.serialNumber} (${humanise(m.currentStatus as MattressStatus)})`)
        .join(', ')}`,
    );
  }

  const wrongWarehouse = mattresses.filter((m) => m.currentWarehouseId !== input.warehouseId);
  if (wrongWarehouse.length > 0) {
    throw errors.businessRule(
      `${wrongWarehouse.length} unit(s) are held at a different warehouse and cannot be dispatched from this one.`,
    );
  }

  const dispatchCode = await nextDispatchCode(tx);
  const dispatch = await tx.dispatch.create({
    data: {
      dispatchCode,
      warehouseId: input.warehouseId,
      dealerId: input.dealerId,
      status: 'DRAFT',
      expectedAt: input.expectedAt ? new Date(input.expectedAt) : null,
      transporter: input.transporter ?? null,
      lrNumber: input.lrNumber ?? null,
      vehicleNumber: input.vehicleNumber ?? null,
      remarks: input.remarks ?? null,
      createdById: actor.userId ?? null,
    },
  });

  await tx.dispatchItem.createMany({
    data: mattresses.map((m) => ({ dispatchId: dispatch.id, mattressId: m.id })),
  });

  for (const mattress of mattresses) {
    assertTransition(mattress.currentStatus as MattressStatus, 'IN_DISPATCH');
    await tx.mattress.update({
      where: { id: mattress.id },
      data: { currentStatus: 'IN_DISPATCH', updatedById: actor.userId ?? null },
    });
    await appendEvent(tx, mattress.id, actor.userId ?? null, {
      eventType: 'ADDED_TO_DISPATCH',
      fromStatus: mattress.currentStatus as MattressStatus,
      toStatus: 'IN_DISPATCH',
      dealerId: input.dealerId,
      payload: { dispatchCode },
    });
  }

  await recordAudit(tx, actor, {
    action: 'DISPATCH_CREATED',
    entity: 'dispatch',
    entityId: dispatch.id,
    newValue: { dispatchCode, dealerId: input.dealerId, itemCount: mattresses.length },
  });

  return { id: dispatch.id, dispatchCode, itemCount: mattresses.length };
}

export async function sendDispatch(
  tx: TransactionClient,
  actor: AuditActor,
  dispatchId: string,
  input: { dispatchedAt?: string | undefined; transporter?: string | undefined; lrNumber?: string | undefined; vehicleNumber?: string | undefined },
): Promise<{ dispatchCode: string; itemCount: number }> {
  const dispatch = await tx.dispatch.findFirst({
    where: { id: dispatchId, deletedAt: null },
    include: { items: { include: { mattress: { select: { id: true, currentStatus: true } } } } },
  });
  if (!dispatch) throw errors.notFound('dispatch');
  if (dispatch.status !== 'DRAFT') {
    throw errors.businessRule(`This dispatch has already been sent (${dispatch.status.toLowerCase()}).`);
  }
  if (dispatch.items.length === 0) {
    throw errors.businessRule('Add at least one mattress before sending this dispatch.');
  }

  const dispatchedAt = input.dispatchedAt ? new Date(input.dispatchedAt) : new Date();

  await tx.dispatch.update({
    where: { id: dispatchId },
    data: {
      status: 'DISPATCHED',
      dispatchedAt,
      transporter: input.transporter ?? dispatch.transporter,
      lrNumber: input.lrNumber ?? dispatch.lrNumber,
      vehicleNumber: input.vehicleNumber ?? dispatch.vehicleNumber,
      updatedById: actor.userId ?? null,
    },
  });

  for (const item of dispatch.items) {
    assertTransition(item.mattress.currentStatus as MattressStatus, 'DISPATCHED');
    await tx.mattress.update({
      where: { id: item.mattressId },
      data: {
        currentStatus: 'DISPATCHED',
        dispatchedAt,
        // Custody moves to the dealer at dispatch. This is also what makes the
        // consignment visible to the dealer under Row Level Security, so they
        // can see what is coming before it arrives.
        currentDealerId: dispatch.dealerId,
        updatedById: actor.userId ?? null,
      },
    });
    await appendEvent(tx, item.mattressId, actor.userId ?? null, {
      eventType: 'DISPATCHED',
      fromStatus: 'IN_DISPATCH',
      toStatus: 'DISPATCHED',
      dealerId: dispatch.dealerId,
      payload: { dispatchCode: dispatch.dispatchCode, transporter: input.transporter ?? dispatch.transporter },
    });
  }

  await recordAudit(tx, actor, {
    action: 'DISPATCH_SENT',
    entity: 'dispatch',
    entityId: dispatchId,
    previousValue: { status: 'DRAFT' },
    newValue: { status: 'DISPATCHED', dispatchedAt, itemCount: dispatch.items.length },
  });

  return { dispatchCode: dispatch.dispatchCode, itemCount: dispatch.items.length };
}

// ---------------------------------------------------------------------------
// Dealer receipt
// ---------------------------------------------------------------------------
export async function receiveDispatch(
  tx: TransactionClient,
  actor: AuditActor,
  dealerId: string,
  input: {
    dispatchId: string;
    items: { serialNumber: string; condition: 'OK' | 'DAMAGED' | 'MISSING'; remarks?: string | undefined }[];
    remarks?: string | undefined;
  },
): Promise<{ receiptId: string; received: number; damaged: number; missing: number; dispatchStatus: string }> {
  // Row Level Security already limits this read to the caller's own dealer;
  // the explicit dealerId filter states the same intent in the query, so the
  // rule is visible to anyone reading the code.
  const dispatch = await tx.dispatch.findFirst({
    where: { id: input.dispatchId, dealerId, deletedAt: null },
    include: { items: { include: { mattress: { select: { id: true, serialNumber: true, currentStatus: true } } } } },
  });
  if (!dispatch) throw errors.notFound('dispatch');
  if (dispatch.status !== 'DISPATCHED' && dispatch.status !== 'PARTIALLY_RECEIVED') {
    throw errors.businessRule('This consignment is not awaiting receipt.');
  }

  const bySerial = new Map(dispatch.items.map((i) => [i.mattress.serialNumber, i.mattress]));
  const unknown = input.items.filter((i) => !bySerial.has(i.serialNumber));
  if (unknown.length > 0) {
    throw errors.businessRule(
      `${unknown.length} scanned unit(s) are not part of this consignment: ${unknown.slice(0, 5).map((u) => u.serialNumber).join(', ')}`,
    );
  }

  const receipt = await tx.dealerReceipt.create({
    data: {
      dispatchId: dispatch.id,
      dealerId,
      receivedByUserId: actor.userId ?? '',
      remarks: input.remarks ?? null,
    },
  });

  let received = 0;
  let damaged = 0;
  let missing = 0;

  for (const line of input.items) {
    const mattress = bySerial.get(line.serialNumber);
    if (!mattress) continue;

    await tx.dealerReceiptItem.create({
      data: {
        receiptId: receipt.id,
        mattressId: mattress.id,
        condition: line.condition,
        remarks: line.remarks ?? null,
      },
    });

    if (line.condition === 'MISSING') {
      missing += 1;
      await appendEvent(tx, mattress.id, actor.userId ?? null, {
        eventType: 'RECEIPT_MISSING',
        fromStatus: mattress.currentStatus as MattressStatus,
        dealerId,
        payload: { dispatchCode: dispatch.dispatchCode, remarks: line.remarks },
      });
      continue;
    }

    assertTransition(mattress.currentStatus as MattressStatus, 'DEALER_RECEIVED');
    await tx.mattress.update({
      where: { id: mattress.id },
      data: {
        currentStatus: 'DEALER_RECEIVED',
        receivedAt: new Date(),
        currentDealerId: dealerId,
        currentWarehouseId: null,
        ...(line.condition === 'DAMAGED' ? { gradeNote: `Received damaged: ${line.remarks ?? 'no detail given'}`.slice(0, 200) } : {}),
        updatedById: actor.userId ?? null,
      },
    });
    await appendEvent(tx, mattress.id, actor.userId ?? null, {
      eventType: line.condition === 'DAMAGED' ? 'RECEIVED_DAMAGED' : 'DEALER_RECEIVED',
      fromStatus: 'DISPATCHED',
      toStatus: 'DEALER_RECEIVED',
      dealerId,
      payload: { dispatchCode: dispatch.dispatchCode, condition: line.condition },
    });

    received += 1;
    if (line.condition === 'DAMAGED') damaged += 1;
  }

  // The consignment is only complete when every line has been accounted for.
  const accounted = await tx.dealerReceiptItem.count({
    where: { receipt: { dispatchId: dispatch.id } },
  });
  const dispatchStatus = accounted >= dispatch.items.length ? 'RECEIVED' : 'PARTIALLY_RECEIVED';
  await tx.dispatch.update({ where: { id: dispatch.id }, data: { status: dispatchStatus } });

  await recordAudit(tx, actor, {
    action: 'DISPATCH_RECEIVED',
    entity: 'dispatch',
    entityId: dispatch.id,
    newValue: { dispatchCode: dispatch.dispatchCode, received, damaged, missing, dispatchStatus },
  });

  return { receiptId: receipt.id, received, damaged, missing, dispatchStatus };
}
