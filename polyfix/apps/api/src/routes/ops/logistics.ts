/**
 * Dispatch management.
 */
import type { FastifyInstance } from 'fastify';
import { createDispatchSchema, sendDispatchSchema, dispatchListQuery, softDeleteSchema } from '@polyfix/validation';
import { parse, noStore, paginate, skipTake, idParam } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { actorFrom, recordAudit } from '../../services/audit.js';
import { createDispatch, sendDispatch } from '../../services/mattress.service.js';

export async function registerLogisticsRoutes(app: FastifyInstance): Promise<void> {
  app.get('/dispatches', { preHandler: app.requirePermission('dispatch:read') }, async (request, reply) => {
    const query = parse(dispatchListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where = {
        deletedAt: null,
        ...(query.status ? { status: query.status } : {}),
        ...(query.dealerId ? { dealerId: query.dealerId } : {}),
        ...(query.search ? { dispatchCode: { contains: query.search.toUpperCase() } } : {}),
      };
      const [items, total] = await Promise.all([
        tx.dispatch.findMany({
          where,
          orderBy: { createdAt: 'desc' },
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
            dealer: { select: { code: true, businessName: true, city: true } },
            warehouse: { select: { name: true } },
            _count: { select: { items: true } },
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
        dealer: d.dealer.businessName,
        dealerCode: d.dealer.code,
        dealerCity: d.dealer.city,
        warehouse: d.warehouse.name,
        itemCount: d._count.items,
        dispatchedAt: d.dispatchedAt,
        expectedAt: d.expectedAt,
        transporter: d.transporter,
        lrNumber: d.lrNumber,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.get<{ Params: { id: string } }>(
    '/dispatches/:id',
    { preHandler: app.requirePermission('dispatch:read') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const dispatch = await request.db((tx) =>
        tx.dispatch.findFirst({
          where: { id },
          select: {
            id: true,
            dispatchCode: true,
            status: true,
            dispatchedAt: true,
            expectedAt: true,
            transporter: true,
            lrNumber: true,
            vehicleNumber: true,
            remarks: true,
            dealer: { select: { id: true, code: true, businessName: true, city: true, state: true } },
            warehouse: { select: { name: true, city: true } },
            createdBy: { select: { fullName: true } },
            items: {
              select: {
                mattress: {
                  select: {
                    id: true,
                    serialNumber: true,
                    currentStatus: true,
                    productVariant: { select: { sizeLabel: true, product: { select: { name: true } } } },
                  },
                },
              },
            },
            receipts: {
              select: {
                receivedAt: true,
                remarks: true,
                receivedBy: { select: { fullName: true } },
                items: { select: { condition: true, remarks: true, mattress: { select: { serialNumber: true } } } },
              },
            },
          },
        }),
      );
      if (!dispatch) throw errors.notFound('dispatch');

      noStore(reply);
      return {
        dispatch: {
          id: dispatch.id,
          dispatchCode: dispatch.dispatchCode,
          status: dispatch.status,
          dispatchedAt: dispatch.dispatchedAt,
          expectedAt: dispatch.expectedAt,
          transporter: dispatch.transporter,
          lrNumber: dispatch.lrNumber,
          vehicleNumber: dispatch.vehicleNumber,
          remarks: dispatch.remarks,
          dealer: dispatch.dealer,
          warehouse: dispatch.warehouse,
          createdBy: dispatch.createdBy?.fullName ?? null,
        },
        items: dispatch.items.map((i) => ({
          id: i.mattress.id,
          serialNumber: i.mattress.serialNumber,
          status: i.mattress.currentStatus,
          product: i.mattress.productVariant.product.name,
          size: i.mattress.productVariant.sizeLabel,
        })),
        receipts: dispatch.receipts.map((r) => ({
          receivedAt: r.receivedAt,
          receivedBy: r.receivedBy.fullName,
          remarks: r.remarks,
          lines: r.items.map((l) => ({
            serialNumber: l.mattress.serialNumber,
            condition: l.condition,
            remarks: l.remarks,
          })),
        })),
      };
    },
  );

  app.post('/dispatches', { preHandler: app.requirePermission('dispatch:create') }, async (request, reply) => {
    const input = parse(createDispatchSchema, request.body);
    const result = await request.db((tx) => createDispatch(tx, actorFrom(request), input));
    noStore(reply);
    return reply.status(201).send(result);
  });

  app.post<{ Params: { id: string } }>(
    '/dispatches/:id/send',
    { preHandler: app.requirePermission('dispatch:update') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(sendDispatchSchema, request.body ?? {});
      const result = await request.db((tx) => sendDispatch(tx, actorFrom(request), id, input));
      noStore(reply);
      return result;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/dispatches/:id/cancel',
    { preHandler: app.requirePermission('dispatch:cancel') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(softDeleteSchema, request.body);

      const result = await request.db(async (tx) => {
        const dispatch = await tx.dispatch.findFirst({
          where: { id, deletedAt: null },
          include: { items: { include: { mattress: { select: { id: true, currentStatus: true, currentWarehouseId: true } } } } },
        });
        if (!dispatch) throw errors.notFound('dispatch');
        if (dispatch.status !== 'DRAFT') {
          throw errors.businessRule('Only a draft dispatch can be cancelled. Once sent, record a return instead.');
        }

        // Units go back to free stock at the warehouse they came from.
        for (const item of dispatch.items) {
          await tx.mattress.update({
            where: { id: item.mattressId },
            data: { currentStatus: 'MANUFACTURED', currentWarehouseId: dispatch.warehouseId },
          });
          await tx.mattressEvent.create({
            data: {
              mattressId: item.mattressId,
              eventType: 'DISPATCH_CANCELLED',
              fromStatus: 'IN_DISPATCH',
              toStatus: 'MANUFACTURED',
              actorUserId: request.auth?.userId ?? null,
              payload: { dispatchCode: dispatch.dispatchCode, reason: input.reason },
            },
          });
        }

        await tx.dispatch.update({
          where: { id },
          data: {
            status: 'CANCELLED',
            deletedAt: new Date(),
            deletedById: request.auth?.userId ?? null,
            deleteReason: input.reason,
          },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'DISPATCH_CANCELLED',
          entity: 'dispatch',
          entityId: id,
          previousValue: { status: 'DRAFT', itemCount: dispatch.items.length },
          newValue: { status: 'CANCELLED' },
          reason: input.reason,
        });

        return { dispatchCode: dispatch.dispatchCode, released: dispatch.items.length };
      });

      noStore(reply);
      return result;
    },
  );
}
