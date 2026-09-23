/**
 * Dealer network and platform users.
 *
 * Two privilege-escalation guards worth pointing out:
 *   - a user may never grant a role senior to their own (see `rankOf`)
 *   - a user may never change their own roles, so an admin cannot quietly
 *     promote themselves to super admin
 */
import type { FastifyInstance } from 'fastify';
import { ROLE_METADATA, hashPassword, checkPasswordPolicy, randomToken, type RoleKey } from '@polyfix/auth';
import {
  createDealerSchema,
  updateDealerSchema,
  dealerListQuery,
  reviewApplicationSchema,
  createUserSchema,
  updateUserSchema,
  assignRolesSchema,
  softDeleteSchema,
  pagination,
} from '@polyfix/validation';
import { parse, noStore, paginate, skipTake, idParam } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { securityLog } from '../../lib/logger.js';
import { actorFrom, recordAudit, recordVersion } from '../../services/audit.js';
import { nextDealerCode } from '../../lib/ids.js';

function rankOf(roles: readonly string[]): number {
  return Math.min(...roles.map((r) => ROLE_METADATA[r as RoleKey]?.rank ?? 999));
}

export async function registerNetworkRoutes(app: FastifyInstance): Promise<void> {
  // =========================================================================
  // Dealers
  // =========================================================================
  app.get('/dealers', { preHandler: app.requirePermission('dealer:read') }, async (request, reply) => {
    const query = parse(dealerListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where = {
        deletedAt: null,
        ...(query.status ? { status: query.status } : {}),
        ...(query.state ? { state: { equals: query.state, mode: 'insensitive' as const } } : {}),
        ...(query.search
          ? {
              OR: [
                { businessName: { contains: query.search, mode: 'insensitive' as const } },
                { code: { contains: query.search.toUpperCase() } },
                { city: { contains: query.search, mode: 'insensitive' as const } },
              ],
            }
          : {}),
      };
      const [items, total] = await Promise.all([
        tx.dealer.findMany({
          where,
          orderBy: { businessName: 'asc' },
          skip,
          take,
          select: {
            id: true,
            code: true,
            businessName: true,
            ownerName: true,
            phone: true,
            city: true,
            state: true,
            status: true,
            publicListed: true,
            onboardedAt: true,
            _count: { select: { mattresses: true, sales: true, claims: true } },
          },
        }),
        tx.dealer.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((d) => ({
        id: d.id,
        code: d.code,
        businessName: d.businessName,
        ownerName: d.ownerName,
        phone: d.phone,
        city: d.city,
        state: d.state,
        status: d.status,
        publicListed: d.publicListed,
        onboardedAt: d.onboardedAt,
        unitsHeld: d._count.mattresses,
        sales: d._count.sales,
        claims: d._count.claims,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.get<{ Params: { id: string } }>(
    '/dealers/:id',
    { preHandler: app.requirePermission('dealer:read') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const dealer = await request.db(async (tx) => {
        const record = await tx.dealer.findFirst({
          where: { id },
          select: {
            id: true, code: true, businessName: true, ownerName: true, email: true, phone: true,
            gstNumber: true, addressLine1: true, addressLine2: true, city: true, state: true, pincode: true,
            status: true, publicListed: true, isShowroom: true, onboardedAt: true, notes: true, createdAt: true,
            users: { select: { user: { select: { id: true, email: true, fullName: true, status: true, lastLoginAt: true } }, isPrimary: true } },
          },
        });
        if (!record) return null;

        const [inStock, sold, claims, approvedClaims] = await Promise.all([
          tx.mattress.count({ where: { currentDealerId: id, currentStatus: 'DEALER_RECEIVED', deletedAt: null } }),
          tx.sale.count({ where: { dealerId: id, deletedAt: null } }),
          tx.warrantyClaim.count({ where: { dealerId: id, deletedAt: null } }),
          tx.warrantyClaim.count({ where: { dealerId: id, deletedAt: null, status: { in: ['APPROVED', 'REPLACED'] } } }),
        ]);

        return { record, stats: { inStock, sold, claims, approvedClaims, claimRate: sold > 0 ? Number((claims / sold).toFixed(4)) : null } };
      });

      if (!dealer) throw errors.notFound('dealer');
      noStore(reply);
      return {
        dealer: dealer.record,
        users: dealer.record.users.map((u) => ({ ...u.user, isPrimary: u.isPrimary })),
        performance: dealer.stats,
      };
    },
  );

  app.post('/dealers', { preHandler: app.requirePermission('dealer:write') }, async (request, reply) => {
    const input = parse(createDealerSchema, request.body);

    const dealer = await request.db(async (tx) => {
      const code = await nextDealerCode(tx);
      const created = await tx.dealer.create({
        data: {
          code,
          businessName: input.businessName,
          ownerName: input.ownerName,
          email: input.email || null,
          phone: input.phone,
          gstNumber: input.gstNumber || null,
          addressLine1: input.addressLine1,
          addressLine2: input.addressLine2 ?? null,
          city: input.city,
          state: input.state,
          pincode: input.pincode,
          publicListed: input.publicListed,
          isShowroom: input.isShowroom,
          notes: input.notes ?? null,
          status: 'ACTIVE',
          onboardedAt: new Date(),
          createdById: request.auth?.userId ?? null,
        },
        select: { id: true, code: true, businessName: true },
      });

      await recordAudit(tx, actorFrom(request), {
        action: 'DEALER_CREATED',
        entity: 'dealer',
        entityId: created.id,
        newValue: { code: created.code, businessName: created.businessName, city: input.city, state: input.state },
      });

      return created;
    });

    noStore(reply);
    return reply.status(201).send(dealer);
  });

  app.patch<{ Params: { id: string } }>(
    '/dealers/:id',
    { preHandler: app.requirePermission('dealer:write') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(updateDealerSchema, request.body);

      // Suspending or terminating a dealer is a different decision from editing
      // their address, and needs a different permission.
      if (input.status && input.status !== 'ACTIVE' && !request.auth?.permissions.includes('dealer:suspend')) {
        throw errors.forbidden('dealer:suspend required to change dealer status');
      }
      if (input.status && input.status !== 'ACTIVE' && !input.statusReason) {
        throw errors.validation({ statusReason: ['Give a reason when suspending or terminating a dealer.'] });
      }

      const updated = await request.db(async (tx) => {
        const before = await tx.dealer.findFirst({ where: { id, deletedAt: null } });
        if (!before) throw errors.notFound('dealer');

        await recordVersion(tx, 'dealer', id, before, request.auth?.userId ?? null, input.statusReason ?? 'dealer details updated');

        const record = await tx.dealer.update({
          where: { id },
          data: {
            ...(input.businessName ? { businessName: input.businessName } : {}),
            ...(input.ownerName ? { ownerName: input.ownerName } : {}),
            ...(input.email !== undefined ? { email: input.email || null } : {}),
            ...(input.phone ? { phone: input.phone } : {}),
            ...(input.gstNumber !== undefined ? { gstNumber: input.gstNumber || null } : {}),
            ...(input.addressLine1 ? { addressLine1: input.addressLine1 } : {}),
            ...(input.addressLine2 !== undefined ? { addressLine2: input.addressLine2 ?? null } : {}),
            ...(input.city ? { city: input.city } : {}),
            ...(input.state ? { state: input.state } : {}),
            ...(input.pincode ? { pincode: input.pincode } : {}),
            ...(input.publicListed !== undefined ? { publicListed: input.publicListed } : {}),
            ...(input.isShowroom !== undefined ? { isShowroom: input.isShowroom } : {}),
            ...(input.notes !== undefined ? { notes: input.notes ?? null } : {}),
            ...(input.status ? { status: input.status } : {}),
            updatedById: request.auth?.userId ?? null,
          },
          select: { id: true, code: true, businessName: true, status: true },
        });

        await recordAudit(tx, actorFrom(request), {
          action: input.status && input.status !== before.status ? 'DEALER_STATUS_CHANGED' : 'DEALER_UPDATED',
          entity: 'dealer',
          entityId: id,
          previousValue: { status: before.status, businessName: before.businessName, publicListed: before.publicListed },
          newValue: { status: record.status, businessName: record.businessName, publicListed: input.publicListed },
          reason: input.statusReason ?? null,
        });

        // Suspending a dealer must also cut off their people, immediately.
        if (input.status && input.status !== 'ACTIVE') {
          const dealerUsers = await tx.dealerUser.findMany({ where: { dealerId: id }, select: { userId: true } });
          const ids = dealerUsers.map((d) => d.userId);
          if (ids.length > 0) {
            await tx.session.updateMany({
              where: { userId: { in: ids }, revokedAt: null },
              data: { revokedAt: new Date(), revokedReason: `dealer ${input.status.toLowerCase()}` },
            });
            securityLog('DEALER_SESSIONS_REVOKED', { dealerId: id, userCount: ids.length, status: input.status });
          }
        }

        return record;
      });

      noStore(reply);
      return updated;
    },
  );

  // =========================================================================
  // Dealership applications
  // =========================================================================
  app.get('/applications', { preHandler: app.requirePermission('dealer_application:read') }, async (request, reply) => {
    const query = parse(pagination, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const [items, total] = await Promise.all([
        tx.dealerApplication.findMany({
          orderBy: { createdAt: 'desc' },
          skip,
          take,
          select: {
            id: true, businessName: true, ownerName: true, mobile: true, email: true,
            city: true, state: true, gstNumber: true, hasExistingBusiness: true,
            status: true, spamScore: true, createdAt: true, reviewNotes: true,
            createdDealer: { select: { code: true } },
          },
        }),
        tx.dealerApplication.count(),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(result.items, result.total, query.page, query.pageSize);
  });

  app.post<{ Params: { id: string } }>(
    '/applications/:id/review',
    { preHandler: app.requirePermission('dealer_application:review') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(reviewApplicationSchema, request.body);

      const result = await request.db(async (tx) => {
        const application = await tx.dealerApplication.findUnique({ where: { id } });
        if (!application) throw errors.notFound('application');
        if (application.status === 'APPROVED') {
          throw errors.conflict('This application has already been approved.');
        }

        let createdDealerId: string | null = null;

        if (input.decision === 'APPROVED') {
          const code = await nextDealerCode(tx);
          const dealer = await tx.dealer.create({
            data: {
              code,
              businessName: input.dealer?.businessName ?? application.businessName,
              ownerName: input.dealer?.ownerName ?? application.ownerName,
              email: input.dealer?.email || application.email,
              phone: input.dealer?.phone ?? application.mobile,
              gstNumber: input.dealer?.gstNumber || application.gstNumber,
              addressLine1: input.dealer?.addressLine1 ?? application.address.slice(0, 200),
              city: input.dealer?.city ?? application.city,
              state: input.dealer?.state ?? application.state,
              pincode: input.dealer?.pincode ?? '000000',
              status: 'ACTIVE',
              publicListed: input.dealer?.publicListed ?? false,
              onboardedAt: new Date(),
              createdById: request.auth?.userId ?? null,
            },
            select: { id: true, code: true },
          });
          createdDealerId = dealer.id;
        }

        await tx.dealerApplication.update({
          where: { id },
          data: {
            status: input.decision,
            reviewNotes: input.notes ?? null,
            reviewedById: request.auth?.userId ?? null,
            reviewedAt: new Date(),
            ...(createdDealerId ? { createdDealerId } : {}),
          },
        });

        await recordAudit(tx, actorFrom(request), {
          action: `DEALER_APPLICATION_${input.decision}`,
          entity: 'dealer_application',
          entityId: id,
          previousValue: { status: application.status },
          newValue: { status: input.decision, createdDealerId },
          reason: input.notes ?? null,
        });

        return { status: input.decision, dealerId: createdDealerId };
      });

      noStore(reply);
      return {
        ...result,
        // Creating the dealer and creating their login are separate steps on
        // purpose: an approval should not silently mint credentials.
        nextStep:
          result.status === 'APPROVED'
            ? 'Dealer record created. Create a dealer login for them from Users when you are ready.'
            : null,
      };
    },
  );

  // =========================================================================
  // Platform users
  // =========================================================================
  app.get('/users', { preHandler: app.requirePermission('user:read') }, async (request, reply) => {
    const query = parse(pagination, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const [items, total] = await Promise.all([
        tx.user.findMany({
          where: { deletedAt: null },
          orderBy: { createdAt: 'desc' },
          skip,
          take,
          // The password hash and MFA secret are not selected. They have no
          // business leaving the database.
          select: {
            id: true, email: true, fullName: true, phone: true, status: true,
            mfaEnabled: true, lastLoginAt: true, createdAt: true, lockedUntil: true,
            roles: { select: { role: { select: { key: true, name: true } } } },
            dealerLink: { select: { dealer: { select: { code: true, businessName: true } } } },
          },
        }),
        tx.user.count({ where: { deletedAt: null } }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(
      result.items.map((u) => ({
        id: u.id,
        email: u.email,
        fullName: u.fullName,
        phone: u.phone,
        status: u.status,
        mfaEnabled: u.mfaEnabled,
        locked: Boolean(u.lockedUntil && u.lockedUntil > new Date()),
        roles: u.roles.map((r) => r.role.key),
        dealer: u.dealerLink?.dealer.businessName ?? null,
        lastLoginAt: u.lastLoginAt,
        createdAt: u.createdAt,
      })),
      result.total,
      query.page,
      query.pageSize,
    );
  });

  app.post('/users', { preHandler: app.requirePermission('user:write') }, async (request, reply) => {
    const input = parse(createUserSchema, request.body);
    const actorRank = rankOf(request.auth?.roles ?? []);
    const targetRank = rankOf(input.roleKeys);

    // You cannot create an account more powerful than your own.
    if (targetRank < actorRank) {
      securityLog('PRIVILEGE_ESCALATION_BLOCKED', {
        userId: request.auth?.userId,
        attemptedRoles: input.roleKeys,
        actorRoles: request.auth?.roles,
      });
      throw errors.forbidden('cannot grant a role senior to your own');
    }
    if (input.roleKeys.includes('DEALER') && !input.dealerId) {
      throw errors.validation({ dealerId: ['A dealer user must be linked to a dealer.'] });
    }
    if (input.roleKeys.includes('DEALER') && input.roleKeys.length > 1) {
      throw errors.validation({
        roleKeys: ['A dealer login cannot also hold a staff role. Create a separate account for staff duties.'],
      });
    }

    const created = await request.db(async (tx) => {
      const existing = await tx.user.findUnique({ where: { email: input.email }, select: { id: true } });
      if (existing) throw errors.conflict('An account with that email address already exists.');

      // A generated, single-use password: the creator never chooses it, and the
      // holder must replace it at first sign-in.
      const temporaryPassword = `${randomToken(9)}Aa1!`;
      const policy = checkPasswordPolicy(temporaryPassword, [input.email]);
      if (!policy.ok) throw errors.internal('generated temporary password failed policy');

      const roles = await tx.role.findMany({ where: { key: { in: input.roleKeys as RoleKey[] } } });
      if (roles.length !== input.roleKeys.length) throw errors.validation({ roleKeys: ['Unknown role.'] });

      const user = await tx.user.create({
        data: {
          email: input.email,
          fullName: input.fullName,
          phone: input.phone ?? null,
          passwordHash: await hashPassword(temporaryPassword),
          status: 'ACTIVE',
          mustChangePassword: true,
          passwordChangedAt: new Date(),
          createdById: request.auth?.userId ?? null,
        },
        select: { id: true, email: true, fullName: true },
      });

      await tx.userRole.createMany({
        data: roles.map((r) => ({ userId: user.id, roleId: r.id, assignedById: request.auth?.userId ?? null })),
      });

      if (input.dealerId) {
        const dealer = await tx.dealer.findFirst({ where: { id: input.dealerId, deletedAt: null }, select: { id: true } });
        if (!dealer) throw errors.notFound('dealer');
        await tx.dealerUser.create({ data: { userId: user.id, dealerId: input.dealerId, isPrimary: false } });
      }

      await recordAudit(tx, actorFrom(request), {
        action: 'USER_CREATED',
        entity: 'user',
        entityId: user.id,
        newValue: { email: user.email, roles: input.roleKeys, dealerId: input.dealerId ?? null },
      });

      return { user, temporaryPassword };
    });

    securityLog('USER_CREATED', { createdBy: request.auth?.userId, newUserId: created.user.id, roles: input.roleKeys }, 'info');

    noStore(reply);
    return reply.status(201).send({
      user: created.user,
      // Shown once, to be handed over out of band. It is not stored anywhere in
      // readable form and is not written to the log.
      temporaryPassword: created.temporaryPassword,
      note: 'Give this password to the user through a channel other than email. They must change it at first sign-in.',
    });
  });

  app.patch<{ Params: { id: string } }>(
    '/users/:id',
    { preHandler: app.requirePermission('user:write') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(updateUserSchema, request.body);

      const updated = await request.db(async (tx) => {
        const before = await tx.user.findFirst({
          where: { id, deletedAt: null },
          select: { id: true, email: true, status: true, fullName: true, roles: { select: { role: { select: { key: true } } } } },
        });
        if (!before) throw errors.notFound('user');

        const targetRank = rankOf(before.roles.map((r) => r.role.key));
        if (targetRank < rankOf(request.auth?.roles ?? [])) {
          throw errors.forbidden('cannot modify an account more senior than your own');
        }

        const user = await tx.user.update({
          where: { id },
          data: {
            ...(input.fullName ? { fullName: input.fullName } : {}),
            ...(input.phone !== undefined ? { phone: input.phone ?? null } : {}),
            ...(input.status ? { status: input.status } : {}),
            updatedById: request.auth?.userId ?? null,
          },
          select: { id: true, email: true, fullName: true, status: true },
        });

        if (input.status && input.status !== 'ACTIVE') {
          await tx.session.updateMany({
            where: { userId: id, revokedAt: null },
            data: { revokedAt: new Date(), revokedReason: `account ${input.status.toLowerCase()}` },
          });
        }

        await recordAudit(tx, actorFrom(request), {
          action: 'USER_UPDATED',
          entity: 'user',
          entityId: id,
          previousValue: { status: before.status, fullName: before.fullName },
          newValue: { status: user.status, fullName: user.fullName },
        });

        return user;
      });

      noStore(reply);
      return updated;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/users/:id/roles',
    // MFA-gated: changing who can do what is the highest-leverage action in the
    // system, so it needs a second factor on the current session.
    { preHandler: app.requirePermission('user:role:assign') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(assignRolesSchema, request.body);

      if (id === request.auth?.userId) {
        securityLog('SELF_ROLE_CHANGE_BLOCKED', { userId: id });
        throw errors.forbidden('a user cannot change their own roles', 'You cannot change your own roles. Ask another administrator.');
      }

      const actorRank = rankOf(request.auth?.roles ?? []);
      if (rankOf(input.roleKeys) < actorRank) {
        securityLog('PRIVILEGE_ESCALATION_BLOCKED', { userId: request.auth?.userId, attempted: input.roleKeys });
        throw errors.forbidden('cannot grant a role senior to your own');
      }

      const result = await request.db(async (tx) => {
        const before = await tx.user.findFirst({
          where: { id, deletedAt: null },
          select: { id: true, email: true, roles: { select: { role: { select: { key: true } } } } },
        });
        if (!before) throw errors.notFound('user');

        const previousRoles = before.roles.map((r) => r.role.key);
        if (rankOf(previousRoles) < actorRank) {
          throw errors.forbidden('cannot modify an account more senior than your own');
        }

        const roles = await tx.role.findMany({ where: { key: { in: input.roleKeys as RoleKey[] } } });
        await tx.userRole.deleteMany({ where: { userId: id } });
        await tx.userRole.createMany({
          data: roles.map((r) => ({ userId: id, roleId: r.id, assignedById: request.auth?.userId ?? null })),
        });

        // Permissions are rebuilt from the database on every request, but
        // ending the sessions makes the change unambiguous and immediate.
        await tx.session.updateMany({
          where: { userId: id, revokedAt: null },
          data: { revokedAt: new Date(), revokedReason: 'roles changed' },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'USER_ROLES_CHANGED',
          entity: 'user',
          entityId: id,
          previousValue: { roles: previousRoles },
          newValue: { roles: input.roleKeys },
          reason: input.reason ?? null,
        });

        return { userId: id, roles: input.roleKeys, previousRoles };
      });

      securityLog('USER_ROLES_CHANGED', { by: request.auth?.userId, target: id, roles: input.roleKeys });
      noStore(reply);
      return result;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/users/:id/force-password-reset',
    { preHandler: app.requirePermission('user:reset_password') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(softDeleteSchema, request.body);

      const result = await request.db(async (tx) => {
        const user = await tx.user.findFirst({ where: { id, deletedAt: null }, select: { id: true, email: true, roles: { select: { role: { select: { key: true } } } } } });
        if (!user) throw errors.notFound('user');
        if (rankOf(user.roles.map((r) => r.role.key)) < rankOf(request.auth?.roles ?? [])) {
          throw errors.forbidden('cannot reset the password of a more senior account');
        }

        const temporaryPassword = `${randomToken(9)}Aa1!`;
        await tx.user.update({
          where: { id },
          data: {
            passwordHash: await hashPassword(temporaryPassword),
            mustChangePassword: true,
            passwordChangedAt: new Date(),
            failedLoginCount: 0,
            lockedUntil: null,
          },
        });
        await tx.session.updateMany({
          where: { userId: id, revokedAt: null },
          data: { revokedAt: new Date(), revokedReason: 'administrative password reset' },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'USER_PASSWORD_RESET_BY_ADMIN',
          entity: 'user',
          entityId: id,
          reason: input.reason,
        });

        return { temporaryPassword, email: user.email };
      });

      securityLog('ADMIN_PASSWORD_RESET', { by: request.auth?.userId, target: id });
      noStore(reply);
      return {
        ...result,
        note: 'Hand this over in person or by phone, not by email. The user must change it at first sign-in.',
      };
    },
  );

  app.get('/roles', { preHandler: app.requirePermission('user:read') }, async (request, reply) => {
    const roles = await request.db((tx) =>
      tx.role.findMany({
        orderBy: { rank: 'asc' },
        select: {
          key: true,
          name: true,
          description: true,
          rank: true,
          _count: { select: { users: true } },
          permissions: { select: { permission: { select: { key: true, group: true, description: true } } } },
        },
      }),
    );
    noStore(reply);
    return {
      roles: roles.map((r) => ({
        key: r.key,
        name: r.name,
        description: r.description,
        rank: r.rank,
        userCount: r._count.users,
        permissions: r.permissions.map((p) => p.permission),
      })),
    };
  });
}
