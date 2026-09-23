/**
 * Warranty claim risk indicators.
 *
 * What this is: a set of deterministic, explainable checks that surface claims
 * worth a closer look, each with the evidence that triggered it.
 *
 * What this is not: an automated decision. Nothing here rejects a claim,
 * notifies a customer, or penalises a dealer. A HIGH indicator means "read this
 * one carefully", and the reviewing human records the outcome and their reason.
 * That distinction is deliberate — an unexplained algorithmic rejection is both
 * bad practice and, for a warranty obligation, a liability.
 *
 * Every signal returns the numbers behind it so a reviewer can judge the
 * indicator rather than trust it.
 */
import type { TransactionClient } from '@polyfix/database';
import { readSetting } from './sale.service.js';

export type RiskCode =
  | 'EARLY_CLAIM'
  | 'WARRANTY_EXPIRED'
  | 'WARRANTY_NEAR_EXPIRY'
  | 'DEALER_CLAIM_RATIO'
  | 'DEALER_CLAIM_BURST'
  | 'REPEAT_CUSTOMER_CLAIMS'
  | 'REPEAT_ADDRESS'
  | 'DUPLICATE_PHOTO'
  | 'TIMELINE_INCONSISTENT'
  | 'REPEAT_CLAIM_SAME_UNIT';

export interface RiskSignal {
  code: RiskCode;
  weight: number;
  summary: string;
  evidence: Record<string, unknown>;
}

export interface RiskAssessment {
  score: number;
  level: 'LOW' | 'MEDIUM' | 'HIGH';
  signals: RiskSignal[];
  computedAt: string;
}

const MEDIUM_THRESHOLD = 20;
const HIGH_THRESHOLD = 50;
const DAY_MS = 24 * 60 * 60 * 1000;

export function levelFor(score: number): 'LOW' | 'MEDIUM' | 'HIGH' {
  if (score >= HIGH_THRESHOLD) return 'HIGH';
  if (score >= MEDIUM_THRESHOLD) return 'MEDIUM';
  return 'LOW';
}

export async function assessClaimRisk(tx: TransactionClient, claimId: string): Promise<RiskAssessment> {
  const claim = await tx.warrantyClaim.findUnique({
    where: { id: claimId },
    select: {
      id: true,
      dealerId: true,
      customerId: true,
      mattressId: true,
      submittedAt: true,
      warranty: { select: { startDate: true, endDate: true, status: true } },
      mattress: {
        select: {
          serialNumber: true,
          manufacturedAt: true,
          dispatchedAt: true,
          receivedAt: true,
          soldAt: true,
        },
      },
      customer: { select: { phoneHash: true, addressHash: true } },
      media: { select: { sha256: true } },
    },
  });

  if (!claim) return { score: 0, level: 'LOW', signals: [], computedAt: new Date().toISOString() };

  const earlyClaimDays = Number(await readSetting(tx, 'risk.early_claim_days', 30));
  const ratioThreshold = Number(await readSetting(tx, 'risk.dealer_claim_ratio_threshold', 0.15));
  const repeatCustomerThreshold = Number(await readSetting(tx, 'risk.repeat_customer_claim_threshold', 2));

  const signals: RiskSignal[] = [];
  const submittedAt = claim.submittedAt.getTime();

  // --- 1. how soon after the sale ------------------------------------------
  if (claim.mattress.soldAt) {
    const daysSinceSale = Math.floor((submittedAt - claim.mattress.soldAt.getTime()) / DAY_MS);
    if (daysSinceSale >= 0 && daysSinceSale <= earlyClaimDays) {
      signals.push({
        code: 'EARLY_CLAIM',
        weight: 25,
        summary: `Claim raised ${daysSinceSale} day(s) after the sale.`,
        evidence: { daysSinceSale, threshold: earlyClaimDays },
      });
    }
  }

  // --- 2. warranty window ---------------------------------------------------
  if (claim.warranty) {
    const end = claim.warranty.endDate.getTime();
    if (submittedAt > end) {
      signals.push({
        code: 'WARRANTY_EXPIRED',
        weight: 40,
        summary: 'The warranty period had already ended when this claim was raised.',
        evidence: { warrantyEnd: claim.warranty.endDate.toISOString().slice(0, 10) },
      });
    } else if (end - submittedAt <= 60 * DAY_MS) {
      signals.push({
        code: 'WARRANTY_NEAR_EXPIRY',
        weight: 10,
        summary: 'Claim raised within 60 days of warranty expiry.',
        evidence: {
          warrantyEnd: claim.warranty.endDate.toISOString().slice(0, 10),
          daysRemaining: Math.floor((end - submittedAt) / DAY_MS),
        },
      });
    }
  }

  // --- 3. dealer claim ratio -------------------------------------------------
  const [dealerSales, dealerClaims] = await Promise.all([
    tx.sale.count({ where: { dealerId: claim.dealerId, deletedAt: null } }),
    tx.warrantyClaim.count({ where: { dealerId: claim.dealerId, deletedAt: null } }),
  ]);
  // Below 20 sales the ratio is too noisy to mean anything.
  if (dealerSales >= 20) {
    const ratio = dealerClaims / dealerSales;
    if (ratio > ratioThreshold) {
      signals.push({
        code: 'DEALER_CLAIM_RATIO',
        weight: 20,
        summary: `This dealer has claimed on ${(ratio * 100).toFixed(1)}% of their sales.`,
        evidence: { dealerSales, dealerClaims, ratio: Number(ratio.toFixed(4)), threshold: ratioThreshold },
      });
    }
  }

  // --- 4. burst of claims from one dealer -----------------------------------
  const recentDealerClaims = await tx.warrantyClaim.count({
    where: {
      dealerId: claim.dealerId,
      deletedAt: null,
      submittedAt: { gte: new Date(submittedAt - 30 * DAY_MS) },
    },
  });
  if (recentDealerClaims >= 10) {
    signals.push({
      code: 'DEALER_CLAIM_BURST',
      weight: 15,
      summary: `${recentDealerClaims} claims from this dealer in the last 30 days.`,
      evidence: { recentDealerClaims, windowDays: 30 },
    });
  }

  // --- 5. the same customer claiming repeatedly ------------------------------
  if (claim.customer?.phoneHash) {
    const priorCustomerClaims = await tx.warrantyClaim.count({
      where: {
        id: { not: claim.id },
        deletedAt: null,
        customer: { phoneHash: claim.customer.phoneHash },
      },
    });
    if (priorCustomerClaims >= repeatCustomerThreshold) {
      signals.push({
        code: 'REPEAT_CUSTOMER_CLAIMS',
        weight: 20,
        summary: `This customer contact number is linked to ${priorCustomerClaims} other claim(s).`,
        evidence: { priorCustomerClaims, threshold: repeatCustomerThreshold },
      });
    }
  }

  // --- 6. the same address across different customers -------------------------
  if (claim.customer?.addressHash) {
    const sameAddressClaims = await tx.warrantyClaim.count({
      where: {
        id: { not: claim.id },
        deletedAt: null,
        customer: { addressHash: claim.customer.addressHash, id: { not: claim.customerId } },
      },
    });
    if (sameAddressClaims >= 2) {
      signals.push({
        code: 'REPEAT_ADDRESS',
        weight: 10,
        summary: `${sameAddressClaims} claim(s) from other customers share this delivery address.`,
        evidence: { sameAddressClaims },
      });
    }
  }

  // --- 7. photographs reused from another claim -------------------------------
  const hashes = claim.media.map((m) => m.sha256).filter(Boolean);
  if (hashes.length > 0) {
    const reused = await tx.claimMedia.findMany({
      where: { sha256: { in: hashes }, claimId: { not: claim.id } },
      select: { sha256: true, claimId: true },
      take: 10,
    });
    if (reused.length > 0) {
      signals.push({
        code: 'DUPLICATE_PHOTO',
        weight: 30,
        summary: `${reused.length} uploaded image(s) are byte-identical to images on another claim.`,
        evidence: { matchingClaimCount: new Set(reused.map((r) => r.claimId)).size },
      });
    }
  }

  // --- 8. an impossible timeline ---------------------------------------------
  // Compared by DAY, not by clock time. A sale carries the date from the
  // invoice, which arrives as midnight, while the receipt carries the moment
  // the consignment was confirmed. Comparing the two directly would flag every
  // ordinary same-day sale — stock received at 09:40 and sold at 16:20 — as an
  // impossible timeline, and an indicator that fires on normal trading is worse
  // than no indicator at all.
  const m = claim.mattress;
  const timelineProblems: string[] = [];
  const day = (value: Date | null): number | null =>
    value ? Date.UTC(value.getUTCFullYear(), value.getUTCMonth(), value.getUTCDate()) : null;

  const soldDay = day(m.soldAt);
  const receivedDay = day(m.receivedAt);
  const dispatchedDay = day(m.dispatchedAt);
  const submittedDay = day(claim.submittedAt);

  if (soldDay !== null && receivedDay !== null && soldDay < receivedDay) {
    timelineProblems.push('sold before the dealer received it');
  }
  if (receivedDay !== null && dispatchedDay !== null && receivedDay < dispatchedDay) {
    timelineProblems.push('received before it was dispatched');
  }
  if (soldDay !== null && submittedDay !== null && submittedDay < soldDay) {
    timelineProblems.push('claim raised before the sale date');
  }
  if (timelineProblems.length > 0) {
    signals.push({
      code: 'TIMELINE_INCONSISTENT',
      weight: 25,
      summary: `The lifecycle dates do not line up: ${timelineProblems.join('; ')}.`,
      evidence: { timelineProblems },
    });
  }

  // --- 9. this unit has been claimed on before --------------------------------
  const priorUnitClaims = await tx.warrantyClaim.count({
    where: { mattressId: claim.mattressId, id: { not: claim.id }, deletedAt: null },
  });
  if (priorUnitClaims > 0) {
    signals.push({
      code: 'REPEAT_CLAIM_SAME_UNIT',
      weight: 15,
      summary: `${priorUnitClaims} previous claim(s) have been raised on this same mattress.`,
      evidence: { priorUnitClaims },
    });
  }

  const score = Math.min(100, signals.reduce((sum, s) => sum + s.weight, 0));
  return { score, level: levelFor(score), signals, computedAt: new Date().toISOString() };
}

/** Recomputes and stores the assessment. Called on submission and on each upload. */
export async function refreshClaimRisk(tx: TransactionClient, claimId: string): Promise<RiskAssessment> {
  const assessment = await assessClaimRisk(tx, claimId);
  await tx.warrantyClaim.update({
    where: { id: claimId },
    data: {
      riskScore: assessment.score,
      riskLevel: assessment.level,
      riskSignals: assessment.signals as never,
      riskComputedAt: new Date(),
    },
  });
  return assessment;
}
