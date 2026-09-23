/**
 * Manufacturing: batches, production runs, mattress records and QR labels.
 */
import type { FastifyInstance } from 'fastify';
import QRCode from 'qrcode';
import { z } from 'zod';
import { getConfig } from '@polyfix/config';
import {
  createBatchSchema,
  produceMattressesSchema,
  mattressListQuery,
  softDeleteSchema,
  scanSchema,
  pagination,
} from '@polyfix/validation';
import { parse, noStore, paginate, skipTake, idParam } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { actorFrom, recordAudit } from '../../services/audit.js';
import { produceMattresses } from '../../services/mattress.service.js';
import { nextBatchCode } from '../../lib/ids.js';

export async function registerManufacturingRoutes(app: FastifyInstance): Promise<void> {
  const config = getConfig();

  // =========================================================================
  // Warehouses
  // =========================================================================
  app.get('/warehouses', { preHandler: app.requirePermission('batch:read') }, async (request, reply) => {
    const warehouses = await request.db((tx) =>
      tx.warehouse.findMany({
        where: { isActive: true },
        orderBy: { name: 'asc' },
        select: { id: true, code: true, name: true, city: true, state: true },
      }),
    );
    noStore(reply);
    return { warehouses };
  });

  // =========================================================================
  // Batches
  // =========================================================================
  app.get('/batches', { preHandler: app.requirePermission('batch:read') }, async (request, reply) => {
    const query = parse(pagination, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const [items, total] = await Promise.all([
        tx.manufacturingBatch.findMany({
          orderBy: { manufacturedOn: 'desc' },
          skip,
          take,
          select: {
            id: true,
            batchCode: true,
            manufacturedOn: true,
            plannedQuantity: true,
            producedQuantity: true,
            lineSupervisor: true,
            qualityCheckedBy: true,
            warehouse: { select: { code: true, name: true } },
            _count: { select: { mattresses: true } },
          },
        }),
        tx.manufacturingBatch.count(),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((b) => ({
        id: b.id,
        batchCode: b.batchCode,
        manufacturedOn: b.manufacturedOn.toISOString().slice(0, 10),
        plannedQuantity: b.plannedQuantity,
        producedQuantity: b.producedQuantity,
        serialisedUnits: b._count.mattresses,
        lineSupervisor: b.lineSupervisor,
        qualityCheckedBy: b.qualityCheckedBy,
        warehouse: b.warehouse.name,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.post('/batches', { preHandler: app.requirePermission('batch:write') }, async (request, reply) => {
    const input = parse(createBatchSchema, request.body);

    const batch = await request.db(async (tx) => {
      const warehouse = await tx.warehouse.findFirst({ where: { id: input.warehouseId, isActive: true } });
      if (!warehouse) throw errors.notFound('warehouse');

      const batchCode = await nextBatchCode(tx);
      const created = await tx.manufacturingBatch.create({
        data: {
          batchCode,
          warehouseId: input.warehouseId,
          manufacturedOn: new Date(input.manufacturedOn),
          plannedQuantity: input.plannedQuantity,
          lineSupervisor: input.lineSupervisor ?? null,
          qualityCheckedBy: input.qualityCheckedBy ?? null,
          notes: input.notes ?? null,
          createdById: request.auth?.userId ?? null,
        },
        select: { id: true, batchCode: true },
      });

      await recordAudit(tx, actorFrom(request), {
        action: 'BATCH_CREATED',
        entity: 'manufacturing_batch',
        entityId: created.id,
        newValue: { batchCode: created.batchCode, plannedQuantity: input.plannedQuantity },
      });

      return created;
    });

    noStore(reply);
    return reply.status(201).send(batch);
  });

  // =========================================================================
  // Production — issues serials and QR tokens
  // =========================================================================
  app.post('/mattresses/produce', { preHandler: app.requirePermission('mattress:create') }, async (request, reply) => {
    const input = parse(produceMattressesSchema, request.body);
    // The whole run is one transaction: either every unit is serialised and
    // recorded, or none is, so a partial failure cannot leave orphan serials.
    const result = await request.db((tx) => produceMattresses(tx, actorFrom(request), input));
    noStore(reply);
    return reply.status(201).send({
      batchCode: result.batchCode,
      produced: result.created.length,
      serialNumbers: result.created.map((c) => c.serialNumber),
    });
  });

  // =========================================================================
  // Mattress records
  // =========================================================================
  app.get('/mattresses', { preHandler: app.requirePermission('mattress:read') }, async (request, reply) => {
    const query = parse(mattressListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where = {
        deletedAt: null,
        ...(query.status ? { currentStatus: query.status } : {}),
        ...(query.dealerId ? { currentDealerId: query.dealerId } : {}),
        ...(query.batchId ? { batchId: query.batchId } : {}),
        ...(query.productVariantId ? { productVariantId: query.productVariantId } : {}),
        // `contains` is compiled to a parameterised LIKE by Prisma; the search
        // term also has its wildcard characters stripped by the schema.
        ...(query.search ? { serialNumber: { contains: query.search, mode: 'insensitive' as const } } : {}),
      };
      const [items, total] = await Promise.all([
        tx.mattress.findMany({
          where,
          orderBy: { manufacturedAt: 'desc' },
          skip,
          take,
          select: {
            id: true,
            serialNumber: true,
            currentStatus: true,
            manufacturedAt: true,
            soldAt: true,
            isReplacement: true,
            productVariant: { select: { sizeLabel: true, product: { select: { name: true } } } },
            currentDealer: { select: { code: true, businessName: true } },
            batch: { select: { batchCode: true } },
          },
        }),
        tx.mattress.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((m) => ({
        id: m.id,
        serialNumber: m.serialNumber,
        status: m.currentStatus,
        product: m.productVariant.product.name,
        size: m.productVariant.sizeLabel,
        batchCode: m.batch.batchCode,
        dealer: m.currentDealer?.businessName ?? null,
        manufacturedOn: m.manufacturedAt.toISOString().slice(0, 10),
        soldOn: m.soldAt?.toISOString().slice(0, 10) ?? null,
        isReplacement: m.isReplacement,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  /** The full digital passport for one unit. */
  app.get<{ Params: { id: string } }>(
    '/mattresses/:id',
    { preHandler: app.requirePermission('mattress:read') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);

      const data = await request.db(async (tx) => {
        const mattress = await tx.mattress.findFirst({
          where: { id },
          select: {
            id: true,
            serialNumber: true,
            qrToken: true,
            currentStatus: true,
            manufacturedAt: true,
            dispatchedAt: true,
            receivedAt: true,
            soldAt: true,
            isReplacement: true,
            replacementForClaimId: true,
            gradeNote: true,
            deletedAt: true,
            deleteReason: true,
            productVariant: {
              select: { sku: true, sizeLabel: true, mrp: true, product: { select: { name: true, slug: true, warrantyYears: true } } },
            },
            batch: { select: { batchCode: true, manufacturedOn: true, warehouse: { select: { name: true } } } },
            currentDealer: { select: { code: true, businessName: true, city: true, state: true } },
            warranty: { select: { status: true, startDate: true, endDate: true, years: true } },
            sale: {
              select: {
                invoiceNumber: true,
                soldAt: true,
                salePrice: true,
                customer: { select: { fullName: true, city: true, state: true } },
              },
            },
            claims: {
              where: { deletedAt: null },
              orderBy: { submittedAt: 'desc' },
              select: { id: true, claimNumber: true, status: true, riskLevel: true, submittedAt: true, reportedIssue: true },
            },
            replacementOf: { select: { replacementMattress: { select: { id: true, serialNumber: true } }, issuedAt: true } },
            replacementIssued: { select: { originalMattress: { select: { id: true, serialNumber: true } }, claim: { select: { claimNumber: true } } } },
            events: {
              orderBy: { occurredAt: 'asc' },
              select: {
                eventType: true,
                fromStatus: true,
                toStatus: true,
                occurredAt: true,
                payload: true,
                actor: { select: { fullName: true } },
                dealerId: true,
              },
            },
          },
        });
        return mattress;
      });

      if (!data) throw errors.notFound('mattress');

      noStore(reply);
      return {
        mattress: {
          id: data.id,
          serialNumber: data.serialNumber,
          status: data.currentStatus,
          product: data.productVariant.product.name,
          productSlug: data.productVariant.product.slug,
          sku: data.productVariant.sku,
          size: data.productVariant.sizeLabel,
          mrp: Number(data.productVariant.mrp),
          batchCode: data.batch.batchCode,
          plant: data.batch.warehouse.name,
          manufacturedOn: data.manufacturedAt.toISOString().slice(0, 10),
          dispatchedOn: data.dispatchedAt?.toISOString().slice(0, 10) ?? null,
          receivedOn: data.receivedAt?.toISOString().slice(0, 10) ?? null,
          soldOn: data.soldAt?.toISOString().slice(0, 10) ?? null,
          dealer: data.currentDealer,
          conditionNote: data.gradeNote,
          isReplacement: data.isReplacement,
          deleted: Boolean(data.deletedAt),
          deleteReason: data.deleteReason,
        },
        warranty: data.warranty,
        sale: data.sale
          ? {
              invoiceNumber: data.sale.invoiceNumber,
              soldAt: data.sale.soldAt.toISOString().slice(0, 10),
              salePrice: Number(data.sale.salePrice),
              customerName: data.sale.customer.fullName,
              customerCity: data.sale.customer.city,
              customerState: data.sale.customer.state,
            }
          : null,
        claims: data.claims,
        // The replacement chain, in both directions.
        replacedBy: data.replacementOf
          ? { serialNumber: data.replacementOf.replacementMattress.serialNumber, issuedAt: data.replacementOf.issuedAt }
          : null,
        replacementFor: data.replacementIssued
          ? {
              serialNumber: data.replacementIssued.originalMattress.serialNumber,
              claimNumber: data.replacementIssued.claim.claimNumber,
            }
          : null,
        timeline: data.events.map((e) => ({
          eventType: e.eventType,
          fromStatus: e.fromStatus,
          toStatus: e.toStatus,
          occurredAt: e.occurredAt,
          actor: e.actor?.fullName ?? 'System',
          detail: e.payload,
        })),
      };
    },
  );

  app.post('/mattresses/lookup', { preHandler: app.requirePermission('mattress:read') }, async (request, reply) => {
    const input = parse(scanSchema, request.body);
    const mattress = await request.db((tx) =>
      tx.mattress.findFirst({
        where: input.serialNumber ? { serialNumber: input.serialNumber } : { qrToken: input.qrToken },
        select: { id: true, serialNumber: true, currentStatus: true },
      }),
    );
    if (!mattress) throw errors.notFound('mattress');
    noStore(reply);
    return mattress;
  });

  /**
   * QR label for a unit. The encoded value is a verification URL on the public
   * website carrying the opaque token — never the serial, so a printed label
   * does not reveal how many units exist or let anyone walk the catalogue.
   */
  app.get<{ Params: { id: string }; Querystring: { format?: string } }>(
    '/mattresses/:id/qr',
    { preHandler: app.requirePermission('mattress:read') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const format = parse(z.enum(['svg', 'png', 'json']).default('svg'), request.query.format ?? 'svg');

      const mattress = await request.db((tx) =>
        tx.mattress.findFirst({ where: { id }, select: { serialNumber: true, qrToken: true } }),
      );
      if (!mattress) throw errors.notFound('mattress');

      const verifyUrl = `${config.env.PUBLIC_WEB_ORIGIN}/warranty/verify?q=${mattress.qrToken}`;

      if (format === 'json') {
        noStore(reply);
        return { serialNumber: mattress.serialNumber, verifyUrl };
      }

      if (format === 'png') {
        const png = await QRCode.toBuffer(verifyUrl, { type: 'png', errorCorrectionLevel: 'M', margin: 1, width: 512 });
        return reply.header('Content-Type', 'image/png').header('Cache-Control', 'private, max-age=3600').send(png);
      }

      const svg = await QRCode.toString(verifyUrl, { type: 'svg', errorCorrectionLevel: 'M', margin: 1 });
      return reply.header('Content-Type', 'image/svg+xml').header('Cache-Control', 'private, max-age=3600').send(svg);
    },
  );

  /**
   * Soft delete. The row stays, flagged with who removed it and why, because a
   * mattress record is warranty evidence and deleting it outright would destroy
   * the answer to a question someone will ask later.
   */
  app.delete<{ Params: { id: string } }>(
    '/mattresses/:id',
    { preHandler: app.requirePermission('mattress:delete') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(softDeleteSchema, request.body);

      await request.db(async (tx) => {
        const mattress = await tx.mattress.findFirst({
          where: { id, deletedAt: null },
          select: { id: true, serialNumber: true, currentStatus: true },
        });
        if (!mattress) throw errors.notFound('mattress');

        if (['SOLD', 'CLAIM_OPEN'].includes(mattress.currentStatus)) {
          throw errors.businessRule(
            'A mattress that has been sold or has an open claim cannot be removed. Its history is part of a live warranty obligation.',
          );
        }

        await tx.mattress.update({
          where: { id },
          data: {
            deletedAt: new Date(),
            deletedById: request.auth?.userId ?? null,
            deleteReason: input.reason,
          },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'MATTRESS_DELETED',
          entity: 'mattress',
          entityId: id,
          previousValue: { serialNumber: mattress.serialNumber, status: mattress.currentStatus },
          newValue: { deleted: true },
          reason: input.reason,
        });
      });

      noStore(reply);
      return { deleted: true, softDelete: true };
    },
  );
}
