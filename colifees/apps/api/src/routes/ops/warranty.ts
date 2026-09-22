/**
 * Warranty claim review.
 *
 * Risk indicators appear only on these staff endpoints. They are never
 * returned to a dealer, because an indicator is a prompt for a human to look
 * closer, not a verdict to be argued with.
 */
import type { FastifyInstance } from 'fastify';
import {
  claimListQuery,
  claimReviewSchema,
  claimInfoRequestSchema,
  claimDecisionSchema,
  issueReplacementSchema,
  softDeleteSchema,
  pagination,
} from '@colifees/validation';
import { parse, noStore, paginate, skipTake, idParam } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { actorFrom, recordAudit } from '../../services/audit.js';
import { addReviewNote, requestMoreInformation, decideClaim, issueReplacement } from '../../services/claim.service.js';
import { refreshClaimRisk } from '../../services/risk.service.js';
import { listClaimMedia, attachClaimMedia } from '../../services/media.service.js';

export async function registerWarrantyRoutes(app: FastifyInstance): Promise<void> {
  // =========================================================================
  // Claim queue
  // =========================================================================
  app.get('/claims', { preHandler: app.requirePermission('claim:read') }, async (request, reply) => {
    const query = parse(claimListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);
    const canSeeRisk = request.auth?.permissions.includes('risk:view') ?? false;

    const result = await request.db(async (tx) => {
      const where = {
        deletedAt: null,
        ...(query.status ? { status: query.status } : {}),
        ...(query.riskLevel ? { riskLevel: query.riskLevel } : {}),
        ...(query.dealerId ? { dealerId: query.dealerId } : {}),
        ...(query.search
          ? {
              OR: [
                { claimNumber: { contains: query.search.toUpperCase() } },
                { mattress: { serialNumber: { contains: query.search.toUpperCase() } } },
              ],
            }
          : {}),
      };
      const [items, total] = await Promise.all([
        tx.warrantyClaim.findMany({
          where,
          orderBy: [{ status: 'asc' }, { submittedAt: 'desc' }],
          skip,
          take,
          select: {
            id: true, claimNumber: true, status: true, issueCategory: true, reportedIssue: true,
            submittedAt: true, decisionAt: true, riskLevel: true, riskScore: true,
            dealer: { select: { code: true, businessName: true, city: true } },
            mattress: { select: { serialNumber: true, productVariant: { select: { product: { select: { name: true } } } } } },
            _count: { select: { media: true } },
          },
        }),
        tx.warrantyClaim.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((c) => ({
        id: c.id,
        claimNumber: c.claimNumber,
        status: c.status,
        issueCategory: c.issueCategory,
        reportedIssue: c.reportedIssue,
        submittedAt: c.submittedAt,
        decisionAt: c.decisionAt,
        dealer: c.dealer.businessName,
        dealerCode: c.dealer.code,
        dealerCity: c.dealer.city,
        serialNumber: c.mattress.serialNumber,
        product: c.mattress.productVariant.product.name,
        photoCount: c._count.media,
        // Gated on a separate permission, not merely on being staff.
        ...(canSeeRisk ? { riskLevel: c.riskLevel, riskScore: c.riskScore } : {}),
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.get<{ Params: { id: string } }>(
    '/claims/:id',
    { preHandler: app.requirePermission('claim:read') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const canSeeRisk = request.auth?.permissions.includes('risk:view') ?? false;
      const canSeeMedia = request.auth?.permissions.includes('claim:media:view') ?? false;

      const data = await request.db(async (tx) => {
        const claim = await tx.warrantyClaim.findFirst({
          where: { id, deletedAt: null },
          select: {
            id: true, claimNumber: true, status: true, issueCategory: true, reportedIssue: true,
            description: true, submittedAt: true, decisionAt: true, decisionReason: true, resolution: true,
            riskLevel: true, riskScore: true, riskSignals: true, riskComputedAt: true,
            dealer: { select: { id: true, code: true, businessName: true, city: true, state: true, phone: true } },
            customer: { select: { fullName: true, city: true, state: true } },
            submittedBy: { select: { fullName: true } },
            reviewedBy: { select: { fullName: true } },
            warranty: { select: { startDate: true, endDate: true, years: true, status: true } },
            mattress: {
              select: {
                id: true, serialNumber: true, manufacturedAt: true, dispatchedAt: true, receivedAt: true, soldAt: true,
                productVariant: { select: { sizeLabel: true, product: { select: { name: true, warrantyYears: true } } } },
                batch: { select: { batchCode: true } },
              },
            },
            replacement: {
              select: { issuedAt: true, remarks: true, replacementMattress: { select: { id: true, serialNumber: true } } },
            },
            events: {
              orderBy: { createdAt: 'asc' },
              select: {
                eventType: true, fromStatus: true, toStatus: true, note: true, isInternal: true, createdAt: true,
                actor: { select: { fullName: true } },
              },
            },
          },
        });
        if (!claim) return null;

        const media = canSeeMedia ? await listClaimMedia(tx, claim.id) : [];

        // Dealer context that makes an indicator interpretable rather than
        // merely alarming.
        const [dealerSales, dealerClaims] = await Promise.all([
          tx.sale.count({ where: { dealerId: claim.dealer.id, deletedAt: null } }),
          tx.warrantyClaim.count({ where: { dealerId: claim.dealer.id, deletedAt: null } }),
        ]);

        return { claim, media, dealerContext: { sales: dealerSales, claims: dealerClaims } };
      });

      if (!data) throw errors.notFound('claim');
      const { claim } = data;

      noStore(reply);
      return {
        claim: {
          id: claim.id,
          claimNumber: claim.claimNumber,
          status: claim.status,
          issueCategory: claim.issueCategory,
          reportedIssue: claim.reportedIssue,
          description: claim.description,
          submittedAt: claim.submittedAt,
          submittedBy: claim.submittedBy.fullName,
          decisionAt: claim.decisionAt,
          decisionReason: claim.decisionReason,
          decidedBy: claim.reviewedBy?.fullName ?? null,
          resolution: claim.resolution,
        },
        dealer: claim.dealer,
        customer: claim.customer,
        warranty: claim.warranty,
        mattress: {
          id: claim.mattress.id,
          serialNumber: claim.mattress.serialNumber,
          product: claim.mattress.productVariant.product.name,
          size: claim.mattress.productVariant.sizeLabel,
          batchCode: claim.mattress.batch.batchCode,
          manufacturedOn: claim.mattress.manufacturedAt.toISOString().slice(0, 10),
          dispatchedOn: claim.mattress.dispatchedAt?.toISOString().slice(0, 10) ?? null,
          receivedOn: claim.mattress.receivedAt?.toISOString().slice(0, 10) ?? null,
          soldOn: claim.mattress.soldAt?.toISOString().slice(0, 10) ?? null,
        },
        replacement: claim.replacement
          ? {
              serialNumber: claim.replacement.replacementMattress.serialNumber,
              issuedAt: claim.replacement.issuedAt,
              remarks: claim.replacement.remarks,
            }
          : null,
        timeline: claim.events,
        media: data.media,
        ...(canSeeRisk
          ? {
              risk: {
                level: claim.riskLevel,
                score: claim.riskScore,
                signals: claim.riskSignals,
                computedAt: claim.riskComputedAt,
                dealerContext: data.dealerContext,
                disclaimer:
                  'These indicators highlight claims worth a closer look. They are not a decision, and no claim is approved or rejected automatically.',
              },
            }
          : {}),
      };
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/recompute-risk',
    { preHandler: app.requirePermission('risk:view') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const assessment = await request.db((tx) => refreshClaimRisk(tx, id));
      noStore(reply);
      return assessment;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/notes',
    { preHandler: app.requirePermission('claim:review') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(claimReviewSchema, request.body);
      await request.db((tx) => addReviewNote(tx, actorFrom(request), id, input));
      noStore(reply);
      return { added: true, visibleToDealer: !input.isInternal };
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/request-information',
    { preHandler: app.requirePermission('claim:review') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(claimInfoRequestSchema, request.body);
      await request.db((tx) => requestMoreInformation(tx, actorFrom(request), id, input.message));
      noStore(reply);
      return { requested: true };
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/decision',
    { preHandler: app.requirePermission('claim:decide') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(claimDecisionSchema, request.body);
      const result = await request.db((tx) => decideClaim(tx, actorFrom(request), id, input));
      noStore(reply);
      return result;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/replacement',
    { preHandler: app.requirePermission('claim:replace') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(issueReplacementSchema, request.body);
      const result = await request.db((tx) => issueReplacement(tx, actorFrom(request), id, input));
      noStore(reply);
      return {
        ...result,
        message: `${result.originalSerial} is now marked replaced and stays in the record. ${result.replacementSerial} carries the warranty to ${result.replacementWarrantyEnd}.`,
      };
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/media',
    { preHandler: app.requirePermission('claim:review') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      // Staff have no dealer scope, so null is passed deliberately.
      const result = await attachClaimMedia(app, request, id, null);
      noStore(reply);
      return result;
    },
  );

  // =========================================================================
  // Warranties
  // =========================================================================
  app.get('/warranties', { preHandler: app.requirePermission('warranty:read') }, async (request, reply) => {
    const query = parse(pagination, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where = { deletedAt: null };
      const [items, total] = await Promise.all([
        tx.warranty.findMany({
          where,
          orderBy: { endDate: 'asc' },
          skip,
          take,
          select: {
            id: true, startDate: true, endDate: true, years: true, status: true,
            dealer: { select: { businessName: true } },
            mattress: { select: { serialNumber: true, productVariant: { select: { product: { select: { name: true } } } } } },
          },
        }),
        tx.warranty.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((w) => ({
        id: w.id,
        serialNumber: w.mattress.serialNumber,
        product: w.mattress.productVariant.product.name,
        dealer: w.dealer.businessName,
        startDate: w.startDate.toISOString().slice(0, 10),
        endDate: w.endDate.toISOString().slice(0, 10),
        years: w.years,
        status: w.status,
        daysRemaining: Math.ceil((w.endDate.getTime() - Date.now()) / (24 * 60 * 60 * 1000)),
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.post<{ Params: { id: string } }>(
    '/warranties/:id/void',
    { preHandler: app.requirePermission('warranty:void') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(softDeleteSchema, request.body);

      await request.db(async (tx) => {
        const warranty = await tx.warranty.findFirst({ where: { id, deletedAt: null }, select: { id: true, status: true, mattressId: true } });
        if (!warranty) throw errors.notFound('warranty');
        if (warranty.status === 'VOID') throw errors.conflict('This warranty is already void.');

        await tx.warranty.update({
          where: { id },
          data: { status: 'VOID', voidReason: input.reason, updatedById: request.auth?.userId ?? null },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'WARRANTY_VOIDED',
          entity: 'warranty',
          entityId: id,
          previousValue: { status: warranty.status },
          newValue: { status: 'VOID' },
          reason: input.reason,
        });
      });

      noStore(reply);
      return { voided: true };
    },
  );
}
