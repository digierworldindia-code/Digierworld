/**
 * Warranty claims: submission, review, decision and replacement.
 *
 * The replacement flow is the part worth reading closely. When a claim is
 * approved and a replacement unit is issued, the system keeps both mattresses
 * and the link between them:
 *
 *     original mattress  ──▶  claim  ──▶  replacement mattress
 *        (status REPLACED)                  (its own lifecycle begins)
 *
 * The original is never deleted, never re-sold and never re-warrantied. Its
 * record, its events and its claim history stay exactly as they were, which is
 * what makes a later question — "what happened to CLF26000001?" — answerable
 * years afterwards.
 */
import { Prisma, type TransactionClient } from '@polyfix/database';
import { errors } from '../lib/errors.js';
import { nextClaimNumber } from '../lib/ids.js';
import { recordAudit, type AuditActor } from './audit.js';
import { appendEvent, assertTransition, type MattressStatus } from './mattress.service.js';
import { refreshClaimRisk, type RiskAssessment } from './risk.service.js';
import { readSetting } from './sale.service.js';

export type ClaimStatus =
  | 'SUBMITTED'
  | 'UNDER_REVIEW'
  | 'INFO_REQUESTED'
  | 'APPROVED'
  | 'REJECTED'
  | 'REPLACED'
  | 'CLOSED'
  | 'WITHDRAWN';

const CLAIM_TRANSITIONS: Record<ClaimStatus, ClaimStatus[]> = {
  SUBMITTED: ['UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'WITHDRAWN'],
  UNDER_REVIEW: ['INFO_REQUESTED', 'APPROVED', 'REJECTED', 'WITHDRAWN'],
  INFO_REQUESTED: ['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'WITHDRAWN'],
  APPROVED: ['REPLACED', 'CLOSED'],
  REJECTED: ['CLOSED'],
  REPLACED: ['CLOSED'],
  CLOSED: [],
  WITHDRAWN: [],
};

function assertClaimTransition(from: ClaimStatus, to: ClaimStatus): void {
  if (!CLAIM_TRANSITIONS[from].includes(to)) {
    throw errors.businessRule(
      `A claim that is ${from.toLowerCase().replace(/_/g, ' ')} cannot move to ${to.toLowerCase().replace(/_/g, ' ')}.`,
      `illegal claim transition ${from} -> ${to}`,
    );
  }
}

async function addClaimEvent(
  tx: TransactionClient,
  claimId: string,
  actorUserId: string | null,
  event: { eventType: string; fromStatus?: ClaimStatus | null; toStatus?: ClaimStatus | null; note?: string | null; isInternal?: boolean },
): Promise<void> {
  await tx.claimEvent.create({
    data: {
      claimId,
      eventType: event.eventType,
      fromStatus: event.fromStatus ?? null,
      toStatus: event.toStatus ?? null,
      actorUserId,
      note: event.note ?? null,
      isInternal: event.isInternal ?? false,
    },
  });
}

// ---------------------------------------------------------------------------
// Submission
// ---------------------------------------------------------------------------
export async function createClaim(
  tx: TransactionClient,
  actor: AuditActor,
  dealerId: string,
  input: { serialNumber: string; issueCategory: string; reportedIssue: string; description: string },
): Promise<{ id: string; claimNumber: string; risk: RiskAssessment }> {
  const mattress = await tx.mattress.findFirst({
    where: { serialNumber: input.serialNumber, deletedAt: null },
    select: {
      id: true,
      serialNumber: true,
      currentStatus: true,
      currentDealerId: true,
      soldAt: true,
      warranty: { select: { id: true, status: true, endDate: true, customerId: true, startDate: true } },
    },
  });

  if (!mattress) throw errors.notFound('mattress');
  if (mattress.currentDealerId !== dealerId) throw errors.notFound('mattress', 'serial held by another dealer');

  if (mattress.currentStatus === 'CLAIM_OPEN') {
    throw errors.conflict('There is already an open claim on this mattress.');
  }
  if (mattress.currentStatus !== 'SOLD') {
    throw errors.businessRule('A warranty claim can only be raised on a mattress that has been sold.');
  }

  const warranty = mattress.warranty;
  if (!warranty) throw errors.businessRule('No warranty record exists for this mattress.');
  if (warranty.status === 'VOID') {
    throw errors.businessRule('The warranty on this mattress has been voided.');
  }

  // Claiming after expiry is *allowed to be submitted* — an out-of-warranty
  // claim is a reviewable business decision, and refusing to record it would
  // simply push the conversation off-system. It is flagged prominently instead.
  const minimumDays = Number(await readSetting(tx, 'warranty.claim_window_days', 0));
  if (minimumDays > 0 && mattress.soldAt) {
    const days = Math.floor((Date.now() - mattress.soldAt.getTime()) / (24 * 60 * 60 * 1000));
    if (days < minimumDays) {
      throw errors.businessRule(
        `Claims can be raised from ${minimumDays} days after the sale. This mattress was sold ${days} day(s) ago.`,
      );
    }
  }

  const claimNumber = await nextClaimNumber(tx);
  const claim = await tx.warrantyClaim.create({
    data: {
      claimNumber,
      mattressId: mattress.id,
      warrantyId: warranty.id,
      dealerId,
      customerId: warranty.customerId,
      issueCategory: input.issueCategory as never,
      reportedIssue: input.reportedIssue,
      description: input.description,
      status: 'SUBMITTED',
      submittedByUserId: actor.userId ?? '',
    },
    select: { id: true, claimNumber: true },
  });

  assertTransition(mattress.currentStatus as MattressStatus, 'CLAIM_OPEN');
  await tx.mattress.update({
    where: { id: mattress.id },
    data: { currentStatus: 'CLAIM_OPEN', updatedById: actor.userId ?? null },
  });

  await appendEvent(tx, mattress.id, actor.userId ?? null, {
    eventType: 'CLAIM_RAISED',
    fromStatus: 'SOLD',
    toStatus: 'CLAIM_OPEN',
    dealerId,
    payload: { claimNumber, issueCategory: input.issueCategory },
  });

  await addClaimEvent(tx, claim.id, actor.userId ?? null, {
    eventType: 'SUBMITTED',
    toStatus: 'SUBMITTED',
    note: input.reportedIssue,
    isInternal: false,
  });

  const risk = await refreshClaimRisk(tx, claim.id);

  await recordAudit(tx, actor, {
    action: 'CLAIM_SUBMITTED',
    entity: 'warranty_claim',
    entityId: claim.id,
    newValue: {
      claimNumber,
      serialNumber: mattress.serialNumber,
      issueCategory: input.issueCategory,
      riskLevel: risk.level,
      riskScore: risk.score,
    },
  });

  return { id: claim.id, claimNumber: claim.claimNumber, risk };
}

// ---------------------------------------------------------------------------
// Review
// ---------------------------------------------------------------------------
export async function addReviewNote(
  tx: TransactionClient,
  actor: AuditActor,
  claimId: string,
  input: { note: string; isInternal: boolean },
): Promise<void> {
  const claim = await tx.warrantyClaim.findFirst({
    where: { id: claimId, deletedAt: null },
    select: { id: true, status: true, claimNumber: true },
  });
  if (!claim) throw errors.notFound('claim');

  const status = claim.status as ClaimStatus;
  if (status === 'SUBMITTED') {
    assertClaimTransition(status, 'UNDER_REVIEW');
    await tx.warrantyClaim.update({ where: { id: claimId }, data: { status: 'UNDER_REVIEW' } });
  }

  await addClaimEvent(tx, claimId, actor.userId ?? null, {
    eventType: 'REVIEW_NOTE',
    fromStatus: status,
    toStatus: status === 'SUBMITTED' ? 'UNDER_REVIEW' : status,
    note: input.note,
    isInternal: input.isInternal,
  });

  await recordAudit(tx, actor, {
    action: 'CLAIM_NOTE_ADDED',
    entity: 'warranty_claim',
    entityId: claimId,
    newValue: { claimNumber: claim.claimNumber, isInternal: input.isInternal },
  });
}

export async function requestMoreInformation(
  tx: TransactionClient,
  actor: AuditActor,
  claimId: string,
  message: string,
): Promise<void> {
  const claim = await tx.warrantyClaim.findFirst({
    where: { id: claimId, deletedAt: null },
    select: { id: true, status: true, claimNumber: true, dealerId: true },
  });
  if (!claim) throw errors.notFound('claim');

  assertClaimTransition(claim.status as ClaimStatus, 'INFO_REQUESTED');
  await tx.warrantyClaim.update({ where: { id: claimId }, data: { status: 'INFO_REQUESTED' } });

  await addClaimEvent(tx, claimId, actor.userId ?? null, {
    eventType: 'INFO_REQUESTED',
    fromStatus: claim.status as ClaimStatus,
    toStatus: 'INFO_REQUESTED',
    note: message,
    isInternal: false,
  });

  await tx.notification.create({
    data: {
      dealerId: claim.dealerId,
      type: 'CLAIM_INFO_REQUESTED',
      title: `More information needed on ${claim.claimNumber}`,
      body: message,
      entity: 'warranty_claim',
      entityId: claimId,
    },
  });

  await recordAudit(tx, actor, {
    action: 'CLAIM_INFO_REQUESTED',
    entity: 'warranty_claim',
    entityId: claimId,
    newValue: { claimNumber: claim.claimNumber },
  });
}

// ---------------------------------------------------------------------------
// Decision
// ---------------------------------------------------------------------------
export async function decideClaim(
  tx: TransactionClient,
  actor: AuditActor,
  claimId: string,
  input: { decision: 'APPROVED' | 'REJECTED'; decisionReason: string; resolution?: string | undefined },
): Promise<{ claimNumber: string; status: string }> {
  const claim = await tx.warrantyClaim.findFirst({
    where: { id: claimId, deletedAt: null },
    select: {
      id: true,
      claimNumber: true,
      status: true,
      dealerId: true,
      mattressId: true,
      riskLevel: true,
      riskScore: true,
      mattress: { select: { currentStatus: true, serialNumber: true } },
    },
  });
  if (!claim) throw errors.notFound('claim');

  assertClaimTransition(claim.status as ClaimStatus, input.decision);

  await tx.warrantyClaim.update({
    where: { id: claimId },
    data: {
      status: input.decision,
      reviewedByUserId: actor.userId ?? null,
      decisionAt: new Date(),
      decisionReason: input.decisionReason,
      resolution: input.resolution ?? null,
    },
  });

  // A rejected claim returns the mattress to its sold state; an approved one
  // stays open until a replacement is issued or the claim is closed.
  if (input.decision === 'REJECTED') {
    assertTransition(claim.mattress.currentStatus as MattressStatus, 'SOLD');
    await tx.mattress.update({ where: { id: claim.mattressId }, data: { currentStatus: 'SOLD' } });
    await appendEvent(tx, claim.mattressId, actor.userId ?? null, {
      eventType: 'CLAIM_REJECTED',
      fromStatus: 'CLAIM_OPEN',
      toStatus: 'SOLD',
      dealerId: claim.dealerId,
      payload: { claimNumber: claim.claimNumber },
    });
  }

  await addClaimEvent(tx, claimId, actor.userId ?? null, {
    eventType: input.decision,
    fromStatus: claim.status as ClaimStatus,
    toStatus: input.decision,
    note: input.decisionReason,
    isInternal: false,
  });

  await tx.notification.create({
    data: {
      dealerId: claim.dealerId,
      type: `CLAIM_${input.decision}`,
      title: `Claim ${claim.claimNumber} ${input.decision.toLowerCase()}`,
      body: input.decisionReason,
      entity: 'warranty_claim',
      entityId: claimId,
    },
  });

  await recordAudit(tx, actor, {
    action: `CLAIM_${input.decision}`,
    entity: 'warranty_claim',
    entityId: claimId,
    previousValue: { status: claim.status },
    newValue: {
      status: input.decision,
      serialNumber: claim.mattress.serialNumber,
      // The indicators in force at decision time are part of the record, so a
      // later reviewer can see what the decision-maker was shown.
      riskLevelAtDecision: claim.riskLevel,
      riskScoreAtDecision: claim.riskScore,
    },
    reason: input.decisionReason,
  });

  return { claimNumber: claim.claimNumber, status: input.decision };
}

// ---------------------------------------------------------------------------
// Replacement
// ---------------------------------------------------------------------------
export async function issueReplacement(
  tx: TransactionClient,
  actor: AuditActor,
  claimId: string,
  input: { replacementSerialNumber: string; remarks?: string | undefined },
): Promise<{
  claimNumber: string;
  originalSerial: string;
  replacementSerial: string;
  replacementWarrantyEnd: string;
}> {
  const claim = await tx.warrantyClaim.findFirst({
    where: { id: claimId, deletedAt: null },
    select: {
      id: true,
      claimNumber: true,
      status: true,
      dealerId: true,
      customerId: true,
      mattressId: true,
      mattress: { select: { id: true, serialNumber: true, currentStatus: true } },
      warranty: { select: { id: true, endDate: true, years: true, termsVersion: true } },
      replacement: { select: { id: true } },
    },
  });
  if (!claim) throw errors.notFound('claim');
  if (claim.status !== 'APPROVED') {
    throw errors.businessRule('A replacement can only be issued against an approved claim.');
  }
  if (claim.replacement) {
    throw errors.conflict('A replacement has already been issued for this claim.');
  }

  const replacement = await tx.mattress.findFirst({
    where: { serialNumber: input.replacementSerialNumber, deletedAt: null },
    select: {
      id: true,
      serialNumber: true,
      currentStatus: true,
      currentDealerId: true,
      productVariant: { select: { product: { select: { warrantyYears: true } } } },
    },
  });
  if (!replacement) throw errors.notFound('replacement mattress');
  if (replacement.id === claim.mattressId) {
    throw errors.businessRule('The replacement must be a different mattress from the one being claimed.');
  }
  if (!['MANUFACTURED', 'DEALER_RECEIVED', 'RETURNED'].includes(replacement.currentStatus)) {
    throw errors.businessRule(
      `The replacement unit is ${replacement.currentStatus.toLowerCase().replace(/_/g, ' ')} and is not free stock.`,
    );
  }

  const now = new Date();

  // --- the replacement takes custody at the dealer --------------------------
  await tx.mattress.update({
    where: { id: replacement.id },
    data: {
      currentStatus: 'DEALER_RECEIVED',
      currentDealerId: claim.dealerId,
      currentWarehouseId: null,
      receivedAt: now,
      isReplacement: true,
      replacementForClaimId: claim.id,
      updatedById: actor.userId ?? null,
    },
  });
  await appendEvent(tx, replacement.id, actor.userId ?? null, {
    eventType: 'ISSUED_AS_REPLACEMENT',
    toStatus: 'DEALER_RECEIVED',
    dealerId: claim.dealerId,
    payload: { claimNumber: claim.claimNumber, replacesSerial: claim.mattress.serialNumber },
  });

  // --- hand-over to the customer, recorded at zero value --------------------
  // A replacement is still a transfer to a named customer on a dated document,
  // so it is recorded as a sale at zero price rather than as an untracked
  // movement. That keeps one code path for warranty activation and keeps the
  // dealer's stock figures honest.
  const sale = await tx.sale.create({
    data: {
      dealerId: claim.dealerId,
      mattressId: replacement.id,
      customerId: claim.customerId,
      invoiceNumber: `REP-${claim.claimNumber}`,
      soldAt: now,
      salePrice: new Prisma.Decimal(0),
      paymentMode: 'OTHER',
      soldByUserId: actor.userId ?? '',
      remarks: `Warranty replacement issued against claim ${claim.claimNumber} for ${claim.mattress.serialNumber}`,
      createdById: actor.userId ?? null,
    },
    select: { id: true },
  });

  // The replacement carries the remainder of the original term, never a fresh
  // full term — the obligation is the one that was originally sold.
  const remainingEnd = claim.warranty?.endDate ?? new Date(now.getTime());
  const startDate = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  const endDate = remainingEnd > startDate ? remainingEnd : new Date(startDate.getTime() + 24 * 60 * 60 * 1000);

  await tx.warranty.create({
    data: {
      mattressId: replacement.id,
      saleId: sale.id,
      dealerId: claim.dealerId,
      customerId: claim.customerId,
      startDate,
      endDate,
      years: claim.warranty?.years ?? replacement.productVariant.product.warrantyYears,
      status: 'ACTIVE',
      termsVersion: claim.warranty?.termsVersion ?? 'v1.0',
      createdById: actor.userId ?? null,
    },
  });

  await tx.mattress.update({
    where: { id: replacement.id },
    data: { currentStatus: 'SOLD', soldAt: now },
  });
  await appendEvent(tx, replacement.id, actor.userId ?? null, {
    eventType: 'REPLACEMENT_HANDED_OVER',
    fromStatus: 'DEALER_RECEIVED',
    toStatus: 'SOLD',
    dealerId: claim.dealerId,
    payload: { claimNumber: claim.claimNumber, warrantyEnd: endDate.toISOString().slice(0, 10) },
  });

  // --- the original is retired, not removed ---------------------------------
  assertTransition(claim.mattress.currentStatus as MattressStatus, 'REPLACED');
  await tx.mattress.update({
    where: { id: claim.mattressId },
    data: { currentStatus: 'REPLACED', updatedById: actor.userId ?? null },
  });
  await appendEvent(tx, claim.mattressId, actor.userId ?? null, {
    eventType: 'REPLACED',
    fromStatus: 'CLAIM_OPEN',
    toStatus: 'REPLACED',
    dealerId: claim.dealerId,
    payload: { claimNumber: claim.claimNumber, replacedBySerial: replacement.serialNumber },
  });

  if (claim.warranty) {
    await tx.warranty.update({
      where: { id: claim.warranty.id },
      data: { status: 'SUPERSEDED' },
    });
  }

  await tx.replacement.create({
    data: {
      claimId: claim.id,
      originalMattressId: claim.mattressId,
      replacementMattressId: replacement.id,
      dealerId: claim.dealerId,
      approvedByUserId: actor.userId ?? '',
      remarks: input.remarks ?? null,
    },
  });

  assertClaimTransition(claim.status as ClaimStatus, 'REPLACED');
  await tx.warrantyClaim.update({
    where: { id: claim.id },
    data: { status: 'REPLACED', closedAt: now },
  });

  await addClaimEvent(tx, claim.id, actor.userId ?? null, {
    eventType: 'REPLACEMENT_ISSUED',
    fromStatus: 'APPROVED',
    toStatus: 'REPLACED',
    note: `Replaced with ${replacement.serialNumber}`,
    isInternal: false,
  });

  await recordAudit(tx, actor, {
    action: 'REPLACEMENT_ISSUED',
    entity: 'warranty_claim',
    entityId: claim.id,
    newValue: {
      claimNumber: claim.claimNumber,
      originalSerial: claim.mattress.serialNumber,
      replacementSerial: replacement.serialNumber,
      warrantyEnd: endDate.toISOString().slice(0, 10),
    },
    reason: input.remarks ?? null,
  });

  return {
    claimNumber: claim.claimNumber,
    originalSerial: claim.mattress.serialNumber,
    replacementSerial: replacement.serialNumber,
    replacementWarrantyEnd: endDate.toISOString().slice(0, 10),
  };
}
