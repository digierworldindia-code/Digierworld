import { z } from 'zod';
import {
  isoDate,
  money,
  pagination,
  phone,
  pincode,
  qrToken,
  reason,
  safeText,
  searchQuery,
  serialNumber,
  trimmed,
  uuid,
} from './primitives.js';

// ---------------------------------------------------------------------------
// Manufacturing
// ---------------------------------------------------------------------------
export const createBatchSchema = z.object({
  warehouseId: uuid,
  manufacturedOn: isoDate,
  plannedQuantity: z.number().int().min(1).max(5000),
  lineSupervisor: trimmed(120).optional(),
  qualityCheckedBy: trimmed(120).optional(),
  notes: safeText(1000).optional(),
});

/**
 * Producing mattresses. The client chooses *what* and *how many*; serial
 * numbers are issued by the database, never supplied by the caller.
 */
export const produceMattressesSchema = z.object({
  batchId: uuid,
  items: z
    .array(
      z.object({
        productVariantId: uuid,
        quantity: z.number().int().min(1).max(500),
      }),
    )
    .min(1)
    .max(20),
});

export const mattressListQuery = pagination.extend({
  status: z
    .enum(['MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED'])
    .optional(),
  dealerId: uuid.optional(),
  batchId: uuid.optional(),
  productVariantId: uuid.optional(),
  search: searchQuery,
});

/** Accepts either form of scan input; exactly one must be present. */
export const scanSchema = z
  .object({
    serialNumber: serialNumber.optional(),
    qrToken: qrToken.optional(),
  })
  .refine((v) => Boolean(v.serialNumber) !== Boolean(v.qrToken), {
    message: 'provide either a serial number or a QR token, not both',
  });

export const softDeleteSchema = z.object({ reason });

// ---------------------------------------------------------------------------
// Dispatch and receipt
// ---------------------------------------------------------------------------
export const createDispatchSchema = z.object({
  warehouseId: uuid,
  dealerId: uuid,
  expectedAt: isoDate.optional(),
  transporter: trimmed(120).optional(),
  lrNumber: trimmed(60).optional(),
  vehicleNumber: trimmed(30).optional(),
  remarks: safeText(1000).optional(),
  // Serial numbers rather than ids: the warehouse scans labels.
  serialNumbers: z.array(serialNumber).min(1).max(500),
});

export const sendDispatchSchema = z.object({
  dispatchedAt: isoDate.optional(),
  transporter: trimmed(120).optional(),
  lrNumber: trimmed(60).optional(),
  vehicleNumber: trimmed(30).optional(),
});

export const receiveDispatchSchema = z.object({
  dispatchId: uuid,
  items: z
    .array(
      z.object({
        serialNumber,
        condition: z.enum(['OK', 'DAMAGED', 'MISSING']).default('OK'),
        remarks: safeText(300).optional(),
      }),
    )
    .min(1)
    .max(500),
  remarks: safeText(1000).optional(),
});

export const dispatchListQuery = pagination.extend({
  status: z.enum(['DRAFT', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED']).optional(),
  dealerId: uuid.optional(),
  search: searchQuery,
});

// ---------------------------------------------------------------------------
// Sale
// ---------------------------------------------------------------------------
export const recordSaleSchema = z.object({
  serialNumber,
  invoiceNumber: trimmed(60),
  soldAt: isoDate,
  salePrice: money,
  paymentMode: z.enum(['CASH', 'CARD', 'UPI', 'BANK_TRANSFER', 'FINANCE', 'OTHER']),
  customer: z.object({
    fullName: trimmed(160),
    phone,
    email: z.string().trim().toLowerCase().email().max(255).optional().or(z.literal('')),
    addressLine: safeText(300).optional(),
    city: trimmed(80).optional(),
    state: trimmed(80).optional(),
    pincode: pincode.optional(),
  }),
  remarks: safeText(1000).optional(),
});

export const saleListQuery = pagination.extend({
  dealerId: uuid.optional(),
  from: isoDate.optional(),
  to: isoDate.optional(),
  search: searchQuery,
});

// ---------------------------------------------------------------------------
// Warranty claims
// ---------------------------------------------------------------------------
export const createClaimSchema = z.object({
  serialNumber,
  issueCategory: z.enum([
    'SAGGING',
    'FABRIC_TEAR',
    'FOAM_DEGRADATION',
    'SPRING_FAILURE',
    'STITCHING',
    'SIZE_MISMATCH',
    'TRANSIT_DAMAGE',
    'OTHER',
  ]),
  reportedIssue: trimmed(200),
  description: safeText(4000, { min: 20, minMessage: 'describe the issue in at least 20 characters' }),
});

export const claimListQuery = pagination.extend({
  status: z
    .enum(['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN'])
    .optional(),
  riskLevel: z.enum(['LOW', 'MEDIUM', 'HIGH']).optional(),
  dealerId: uuid.optional(),
  search: searchQuery,
});

export const claimReviewSchema = z.object({
  note: safeText(2000),
  /** Internal notes stay invisible to the dealer, enforced by RLS. */
  isInternal: z.boolean().default(true),
});

export const claimInfoRequestSchema = z.object({
  message: safeText(2000, { min: 10 }),
});

export const claimDecisionSchema = z.object({
  decision: z.enum(['APPROVED', 'REJECTED']),
  decisionReason: safeText(2000, { min: 10, minMessage: 'record why this decision was taken' }),
  resolution: safeText(2000).optional(),
});

export const issueReplacementSchema = z.object({
  /** Serial of the replacement unit, which must be free stock at a warehouse. */
  replacementSerialNumber: serialNumber,
  remarks: safeText(1000).optional(),
});

// ---------------------------------------------------------------------------
// Dealers
// ---------------------------------------------------------------------------
export const createDealerSchema = z.object({
  businessName: trimmed(180),
  ownerName: trimmed(160),
  email: z.string().trim().toLowerCase().email().max(255).optional().or(z.literal('')),
  phone,
  gstNumber: z.string().trim().toUpperCase().max(20).optional().or(z.literal('')),
  addressLine1: trimmed(200),
  addressLine2: trimmed(200).optional(),
  city: trimmed(80),
  state: trimmed(80),
  pincode,
  publicListed: z.boolean().default(false),
  isShowroom: z.boolean().default(true),
  notes: safeText(2000).optional(),
});

export const updateDealerSchema = createDealerSchema.partial().extend({
  status: z.enum(['PENDING', 'ACTIVE', 'SUSPENDED', 'TERMINATED']).optional(),
  statusReason: safeText(1000).optional(),
});

export const dealerListQuery = pagination.extend({
  status: z.enum(['PENDING', 'ACTIVE', 'SUSPENDED', 'TERMINATED']).optional(),
  state: trimmed(80).optional(),
  search: searchQuery,
});

export const reviewApplicationSchema = z.object({
  decision: z.enum(['APPROVED', 'REJECTED', 'INFO_REQUESTED']),
  notes: safeText(2000).optional(),
  /** Provided when approving, to create the dealer record. */
  dealer: createDealerSchema.partial().optional(),
});

export type CreateDispatchInput = z.infer<typeof createDispatchSchema>;
export type RecordSaleInput = z.infer<typeof recordSaleSchema>;
export type CreateClaimInput = z.infer<typeof createClaimSchema>;
