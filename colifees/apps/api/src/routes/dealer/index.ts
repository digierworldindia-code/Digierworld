/**
 * Dealer portal endpoints.
 *
 * Mobile-first in shape as well as in UI: the scan endpoint answers with
 * everything the next screen needs and with the list of actions this dealer is
 * actually allowed to take on that unit, so the phone never has to ask twice.
 *
 * Dealer scope is applied three times over, and not one of them trusts the
 * client:
 *   1. `requireDealer` reads the dealer id from the session, never the request
 *   2. queries filter on that dealer id explicitly
 *   3. PostgreSQL Row Level Security filters again inside the transaction
 *
 * Any one of those would do on a good day. All three means a mistake in one
 * layer is not a data breach.
 */
import type { FastifyInstance, FastifyRequest } from 'fastify';
import {
  scanSchema,
  receiveDispatchSchema,
  recordSaleSchema,
  createClaimSchema,
  claimListQuery,
  mattressListQuery,
  saleListQuery,
  pagination,
} from '@colifees/validation';
import { getConfig } from '@colifees/config';
import type { Prisma } from '@colifees/database';
import { parse, noStore, paginate, skipTake, idParam } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { actorFrom } from '../../services/audit.js';
import { receiveDispatch } from '../../services/mattress.service.js';
import { recordSale } from '../../services/sale.service.js';
import { createClaim } from '../../services/claim.service.js';
import { attachClaimMedia, listClaimMedia } from '../../services/media.service.js';

/** The one place a dealer id enters a request. */
function requireDealer(request: FastifyRequest): string {
  const dealerId = request.auth?.dealerId;
  if (!dealerId) {
    throw errors.forbidden('user is not linked to a dealer', 'This area is for COLIFEES dealers.');
  }
  return dealerId;
}

export async function registerDealerRoutes(app: FastifyInstance): Promise<void> {
  const config = getConfig();

  // =========================================================================
  // Home screen
  // =========================================================================
  app.get('/summary', { preHandler: app.requirePermission('dashboard:view') }, async (request, reply) => {
    const dealerId = requireDealer(request);

    const summary = await request.db(async (tx) => {
      const [inStock, incoming, soldThisMonth, openClaims, dealer] = await Promise.all([
        tx.mattress.count({ where: { currentDealerId: dealerId, currentStatus: 'DEALER_RECEIVED', deletedAt: null } }),
        tx.dispatch.count({ where: { dealerId, status: { in: ['DISPATCHED', 'PARTIALLY_RECEIVED'] }, deletedAt: null } }),
        tx.sale.count({
          where: {
            dealerId,
            deletedAt: null,
            soldAt: { gte: new Date(new Date().getFullYear(), new Date().getMonth(), 1) },
          },
        }),
        tx.warrantyClaim.count({
          where: { dealerId, deletedAt: null, status: { in: ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED'] } },
        }),
        tx.dealer.findFirst({ where: { id: dealerId }, select: { businessName: true, code: true, city: true, state: true } }),
      ]);

      const unread = await tx.notification.count({ where: { dealerId, readAt: null } });
      return { inStock, incoming, soldThisMonth, openClaims, dealer, unread };
    });

    noStore(reply);
    return summary;
  });

  // =========================================================================
  // Scan — the primary dealer action
  // =========================================================================
  app.post('/scan', { preHandler: app.requirePermission('mattress:read') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const input = parse(scanSchema, request.body);

    const result = await request.db(async (tx) => {
      const mattress = await tx.mattress.findFirst({
        where: input.serialNumber ? { serialNumber: input.serialNumber } : { qrToken: input.qrToken },
        select: {
          id: true,
          serialNumber: true,
          currentStatus: true,
          currentDealerId: true,
          manufacturedAt: true,
          receivedAt: true,
          soldAt: true,
          deletedAt: true,
          gradeNote: true,
          isReplacement: true,
          productVariant: {
            select: {
              sizeLabel: true,
              mrp: true,
              product: { select: { name: true, slug: true, comfortLevel: true, warrantyYears: true } },
            },
          },
          warranty: { select: { status: true, startDate: true, endDate: true } },
          sale: { select: { invoiceNumber: true, soldAt: true, customer: { select: { fullName: true, city: true } } } },
          claims: {
            where: { deletedAt: null },
            orderBy: { submittedAt: 'desc' },
            take: 3,
            select: { id: true, claimNumber: true, status: true, submittedAt: true, reportedIssue: true },
          },
        },
      });

      // Row Level Security has already hidden other dealers' units, so a miss
      // here means "not yours or not real" — and the dealer is told the same
      // thing either way.
      if (!mattress || mattress.deletedAt) return null;
      return mattress;
    });

    if (!result) {
      throw errors.notFound(
        'mattress',
        'scan returned no row for this dealer scope',
      );
    }

    const actions: string[] = [];
    if (result.currentStatus === 'DISPATCHED') actions.push('RECEIVE');
    if (result.currentStatus === 'DEALER_RECEIVED') actions.push('SELL');
    if (result.currentStatus === 'SOLD') actions.push('RAISE_CLAIM');
    if (result.claims.length > 0) actions.push('VIEW_CLAIMS');

    noStore(reply);
    return {
      mattress: {
        serialNumber: result.serialNumber,
        status: result.currentStatus,
        product: result.productVariant.product.name,
        productSlug: result.productVariant.product.slug,
        size: result.productVariant.sizeLabel,
        comfortLevel: result.productVariant.product.comfortLevel,
        mrp: Number(result.productVariant.mrp),
        manufacturedOn: result.manufacturedAt.toISOString().slice(0, 10),
        receivedOn: result.receivedAt?.toISOString().slice(0, 10) ?? null,
        soldOn: result.soldAt?.toISOString().slice(0, 10) ?? null,
        isReplacement: result.isReplacement,
        conditionNote: result.gradeNote,
      },
      warranty: result.warranty
        ? {
            status: result.warranty.status,
            startDate: result.warranty.startDate.toISOString().slice(0, 10),
            endDate: result.warranty.endDate.toISOString().slice(0, 10),
          }
        : null,
      sale: result.sale
        ? {
            invoiceNumber: result.sale.invoiceNumber,
            soldAt: result.sale.soldAt.toISOString().slice(0, 10),
            customerName: result.sale.customer.fullName,
            customerCity: result.sale.customer.city,
          }
        : null,
      claims: result.claims.map((c) => ({
        id: c.id,
        claimNumber: c.claimNumber,
        status: c.status,
        submittedAt: c.submittedAt,
        reportedIssue: c.reportedIssue,
      })),
      actions,
    };
  });

  // =========================================================================
  // Inventory
  // =========================================================================
  app.get('/inventory', { preHandler: app.requirePermission('mattress:read') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const query = parse(mattressListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const page = await request.db(async (tx) => {
      const where: Prisma.MattressWhereInput = {
        currentDealerId: dealerId,
        deletedAt: null,
        ...(query.status
          ? { currentStatus: query.status }
          : { currentStatus: { in: ['DISPATCHED', 'DEALER_RECEIVED'] } }),
        ...(query.search ? { serialNumber: { contains: query.search, mode: 'insensitive' } } : {}),
      };
      const [items, total] = await Promise.all([
        tx.mattress.findMany({
          where,
          orderBy: { receivedAt: 'desc' },
          skip,
          take,
          select: {
            id: true,
            serialNumber: true,
            currentStatus: true,
            receivedAt: true,
            gradeNote: true,
            productVariant: { select: { sizeLabel: true, mrp: true, product: { select: { name: true } } } },
          },
        }),
        tx.mattress.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      page.items.map((m) => ({
        id: m.id,
        serialNumber: m.serialNumber,
        status: m.currentStatus,
        product: m.productVariant.product.name,
        size: m.productVariant.sizeLabel,
        mrp: Number(m.productVariant.mrp),
        receivedOn: m.receivedAt?.toISOString().slice(0, 10) ?? null,
        conditionNote: m.gradeNote,
      })),
      page.total,
      query.page,
      query.pageSize,
    );
  });

  app.get('/inventory/summary', { preHandler: app.requirePermission('mattress:read') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const rows = await request.db((tx) =>
      tx.mattress.groupBy({
        by: ['currentStatus'],
        where: { currentDealerId: dealerId, deletedAt: null },
        _count: { _all: true },
      }),
    );
    noStore(reply);
    return { byStatus: rows.map((r) => ({ status: r.currentStatus, count: r._count._all })) };
  });

  // =========================================================================
  // Incoming consignments
  // =========================================================================
  app.get('/incoming', { preHandler: app.requirePermission('dispatch:read') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const query = parse(pagination, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where: Prisma.DispatchWhereInput = {
        dealerId,
        deletedAt: null,
        status: { in: ['DISPATCHED', 'PARTIALLY_RECEIVED'] },
      };
      const [items, total] = await Promise.all([
        tx.dispatch.findMany({
          where,
          orderBy: { dispatchedAt: 'desc' },
          skip,
          take,
          select: {
            id: true,
            dispatchCode: true,
            status: true,
            dispatchedAt: true,
            expectedAt: true,
            transporter: true,
            lrNumber: true,
            vehicleNumber: true,
            _count: { select: { items: true } },
            items: {
              select: {
                mattress: {
                  select: {
                    serialNumber: true,
                    currentStatus: true,
                    productVariant: { select: { sizeLabel: true, product: { select: { name: true } } } },
                  },
                },
              },
            },
          },
        }),
        tx.dispatch.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((d) => ({
        id: d.id,
        dispatchCode: d.dispatchCode,
        status: d.status,
        dispatchedAt: d.dispatchedAt,
        expectedAt: d.expectedAt,
        transporter: d.transporter,
        lrNumber: d.lrNumber,
        vehicleNumber: d.vehicleNumber,
        itemCount: d._count.items,
        items: d.items.map((i) => ({
          serialNumber: i.mattress.serialNumber,
          status: i.mattress.currentStatus,
          product: i.mattress.productVariant.product.name,
          size: i.mattress.productVariant.sizeLabel,
        })),
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.post('/receive', { preHandler: app.requirePermission('receipt:create') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const input = parse(receiveDispatchSchema, request.body);
    const result = await request.db((tx) => receiveDispatch(tx, actorFrom(request), dealerId, input));
    noStore(reply);
    return result;
  });

  // =========================================================================
  // Sales
  // =========================================================================
  app.post('/sales', { preHandler: app.requirePermission('sale:create') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const input = parse(recordSaleSchema, request.body);
    const result = await request.db((tx) =>
      recordSale(tx, actorFrom(request), dealerId, {
        ...input,
        customer: { ...input.customer, email: input.customer.email || undefined },
      }),
    );
    noStore(reply);
    return {
      ...result,
      message: `Sale recorded. Warranty is active until ${result.warrantyEnd}.`,
    };
  });

  app.get('/sales', { preHandler: app.requirePermission('sale:read') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const query = parse(saleListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where: Prisma.SaleWhereInput = {
        dealerId,
        deletedAt: null,
        ...(query.from || query.to
          ? {
              soldAt: {
                ...(query.from ? { gte: new Date(query.from) } : {}),
                ...(query.to ? { lte: new Date(`${query.to}T23:59:59.999Z`) } : {}),
              },
            }
          : {}),
        ...(query.search ? { invoiceNumber: { contains: query.search, mode: 'insensitive' } } : {}),
      };
      const [items, total] = await Promise.all([
        tx.sale.findMany({
          where,
          orderBy: { soldAt: 'desc' },
          skip,
          take,
          select: {
            id: true,
            invoiceNumber: true,
            soldAt: true,
            salePrice: true,
            paymentMode: true,
            mattress: { select: { serialNumber: true, productVariant: { select: { sizeLabel: true, product: { select: { name: true } } } } } },
            customer: { select: { fullName: true, city: true } },
            warranty: { select: { endDate: true, status: true } },
          },
        }),
        tx.sale.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((s) => ({
        id: s.id,
        invoiceNumber: s.invoiceNumber,
        soldAt: s.soldAt.toISOString().slice(0, 10),
        salePrice: Number(s.salePrice),
        paymentMode: s.paymentMode,
        serialNumber: s.mattress.serialNumber,
        product: s.mattress.productVariant.product.name,
        size: s.mattress.productVariant.sizeLabel,
        customerName: s.customer.fullName,
        customerCity: s.customer.city,
        warrantyEnd: s.warranty?.endDate.toISOString().slice(0, 10) ?? null,
        warrantyStatus: s.warranty?.status ?? null,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  // =========================================================================
  // Warranty claims
  // =========================================================================
  app.post('/claims', { preHandler: app.requirePermission('claim:create') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const input = parse(createClaimSchema, request.body);
    const result = await request.db((tx) => createClaim(tx, actorFrom(request), dealerId, input));
    noStore(reply);
    return {
      id: result.id,
      claimNumber: result.claimNumber,
      // Risk indicators are an internal review aid. The dealer is told their
      // claim was received, and nothing about how it scored.
      message: 'Claim submitted. Add photographs of the issue and the law label to help the review.',
      uploadsRemaining: config.env.MAX_UPLOADS_PER_CLAIM,
    };
  });

  app.get('/claims', { preHandler: app.requirePermission('claim:read') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const query = parse(claimListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where: Prisma.WarrantyClaimWhereInput = {
        dealerId,
        deletedAt: null,
        ...(query.status ? { status: query.status } : {}),
        ...(query.search ? { claimNumber: { contains: query.search.toUpperCase() } } : {}),
      };
      const [items, total] = await Promise.all([
        tx.warrantyClaim.findMany({
          where,
          orderBy: { submittedAt: 'desc' },
          skip,
          take,
          select: {
            id: true,
            claimNumber: true,
            status: true,
            issueCategory: true,
            reportedIssue: true,
            submittedAt: true,
            decisionAt: true,
            decisionReason: true,
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
        decisionReason: c.decisionReason,
        serialNumber: c.mattress.serialNumber,
        product: c.mattress.productVariant.product.name,
        photoCount: c._count.media,
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
      const dealerId = requireDealer(request);
      const { id } = parse(idParam, request.params);

      const claim = await request.db(async (tx) => {
        const record = await tx.warrantyClaim.findFirst({
          where: { id, dealerId, deletedAt: null },
          select: {
            id: true,
            claimNumber: true,
            status: true,
            issueCategory: true,
            reportedIssue: true,
            description: true,
            submittedAt: true,
            decisionAt: true,
            decisionReason: true,
            resolution: true,
            mattress: { select: { serialNumber: true, productVariant: { select: { sizeLabel: true, product: { select: { name: true } } } } } },
            replacement: { select: { replacementMattress: { select: { serialNumber: true } }, issuedAt: true } },
            // Internal review notes are excluded by a Row Level Security policy
            // on claim_events, not by this select alone.
            events: {
              orderBy: { createdAt: 'asc' },
              select: { eventType: true, fromStatus: true, toStatus: true, note: true, createdAt: true },
            },
          },
        });
        if (!record) return null;
        const media = await listClaimMedia(tx, record.id);
        return { record, media };
      });

      if (!claim) throw errors.notFound('claim');

      noStore(reply);
      return {
        claim: {
          id: claim.record.id,
          claimNumber: claim.record.claimNumber,
          status: claim.record.status,
          issueCategory: claim.record.issueCategory,
          reportedIssue: claim.record.reportedIssue,
          description: claim.record.description,
          submittedAt: claim.record.submittedAt,
          decisionAt: claim.record.decisionAt,
          decisionReason: claim.record.decisionReason,
          resolution: claim.record.resolution,
          serialNumber: claim.record.mattress.serialNumber,
          product: claim.record.mattress.productVariant.product.name,
          size: claim.record.mattress.productVariant.sizeLabel,
          replacementSerial: claim.record.replacement?.replacementMattress.serialNumber ?? null,
          replacementIssuedAt: claim.record.replacement?.issuedAt ?? null,
        },
        timeline: claim.record.events,
        media: claim.media,
      };
    },
  );

  app.post<{ Params: { id: string } }>(
    '/claims/:id/media',
    {
      preHandler: app.requirePermission('claim:create'),
      config: { rateLimit: { max: 30, timeWindow: '10 minutes' } },
    },
    async (request, reply) => {
      const dealerId = requireDealer(request);
      const { id } = parse(idParam, request.params);
      const result = await attachClaimMedia(app, request, id, dealerId);
      noStore(reply);
      return result;
    },
  );

  // =========================================================================
  // Notifications
  // =========================================================================
  app.get('/notifications', { preHandler: app.requirePermission('dashboard:view') }, async (request, reply) => {
    const dealerId = requireDealer(request);
    const notifications = await request.db((tx) =>
      tx.notification.findMany({
        where: { dealerId },
        orderBy: { createdAt: 'desc' },
        take: 50,
        select: { id: true, type: true, title: true, body: true, entity: true, entityId: true, readAt: true, createdAt: true },
      }),
    );
    noStore(reply);
    return { notifications };
  });

  app.post<{ Params: { id: string } }>(
    '/notifications/:id/read',
    { preHandler: app.requirePermission('dashboard:view') },
    async (request, reply) => {
      const dealerId = requireDealer(request);
      const { id } = parse(idParam, request.params);
      await request.db((tx) =>
        tx.notification.updateMany({ where: { id, dealerId, readAt: null }, data: { readAt: new Date() } }),
      );
      noStore(reply);
      return { read: true };
    },
  );
}
