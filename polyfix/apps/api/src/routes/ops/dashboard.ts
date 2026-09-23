import type { FastifyInstance } from 'fastify';
import { noStore } from '../../lib/http.js';

export async function registerDashboardRoutes(app: FastifyInstance): Promise<void> {
  /**
   * Operational overview. Counts only — no customer names, no contact details,
   * nothing that would make a dashboard screenshot a data leak.
   */
  app.get('/dashboard', { preHandler: app.requirePermission('dashboard:view') }, async (request, reply) => {
    const startOfMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);

    const data = await request.db(async (tx) => {
      const [
        totalUnits,
        inWarehouse,
        inTransit,
        atDealers,
        sold,
        activeDealers,
        openClaims,
        highRiskClaims,
        salesThisMonth,
        newLeads,
        pendingApplications,
        claimsLast30,
      ] = await Promise.all([
        tx.mattress.count({ where: { deletedAt: null } }),
        tx.mattress.count({ where: { deletedAt: null, currentStatus: 'MANUFACTURED' } }),
        tx.mattress.count({ where: { deletedAt: null, currentStatus: { in: ['IN_DISPATCH', 'DISPATCHED'] } } }),
        tx.mattress.count({ where: { deletedAt: null, currentStatus: 'DEALER_RECEIVED' } }),
        tx.mattress.count({ where: { deletedAt: null, currentStatus: { in: ['SOLD', 'CLAIM_OPEN', 'REPLACED'] } } }),
        tx.dealer.count({ where: { status: 'ACTIVE', deletedAt: null } }),
        tx.warrantyClaim.count({
          where: { deletedAt: null, status: { in: ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'] } },
        }),
        tx.warrantyClaim.count({
          where: { deletedAt: null, riskLevel: 'HIGH', status: { in: ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'] } },
        }),
        tx.sale.count({ where: { deletedAt: null, soldAt: { gte: startOfMonth } } }),
        tx.contactSubmission.count({ where: { status: 'NEW' } }),
        tx.dealerApplication.count({ where: { status: 'NEW' } }),
        tx.warrantyClaim.count({ where: { deletedAt: null, submittedAt: { gte: thirtyDaysAgo } } }),
      ]);

      const recentClaims = await tx.warrantyClaim.findMany({
        where: { deletedAt: null, status: { in: ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'] } },
        orderBy: [{ riskScore: 'desc' }, { submittedAt: 'desc' }],
        take: 8,
        select: {
          id: true,
          claimNumber: true,
          status: true,
          riskLevel: true,
          riskScore: true,
          submittedAt: true,
          reportedIssue: true,
          dealer: { select: { businessName: true } },
          mattress: { select: { serialNumber: true } },
        },
      });

      const topDealers = await tx.sale.groupBy({
        by: ['dealerId'],
        where: { deletedAt: null, soldAt: { gte: thirtyDaysAgo } },
        _count: { _all: true },
        orderBy: { _count: { dealerId: 'desc' } },
        take: 5,
      });

      const dealerNames = await tx.dealer.findMany({
        where: { id: { in: topDealers.map((d) => d.dealerId) } },
        select: { id: true, businessName: true, city: true },
      });
      const nameById = new Map(dealerNames.map((d) => [d.id, d]));

      return {
        totalUnits,
        inWarehouse,
        inTransit,
        atDealers,
        sold,
        activeDealers,
        openClaims,
        highRiskClaims,
        salesThisMonth,
        newLeads,
        pendingApplications,
        claimsLast30,
        recentClaims,
        topDealers: topDealers.map((d) => ({
          dealer: nameById.get(d.dealerId)?.businessName ?? 'Unknown',
          city: nameById.get(d.dealerId)?.city ?? null,
          sales: d._count._all,
        })),
      };
    });

    noStore(reply);
    return {
      inventory: {
        totalUnits: data.totalUnits,
        inWarehouse: data.inWarehouse,
        inTransit: data.inTransit,
        atDealers: data.atDealers,
        sold: data.sold,
      },
      network: { activeDealers: data.activeDealers, pendingApplications: data.pendingApplications },
      warranty: { openClaims: data.openClaims, highRiskClaims: data.highRiskClaims, claimsLast30Days: data.claimsLast30 },
      commercial: { salesThisMonth: data.salesThisMonth, newLeads: data.newLeads },
      reviewQueue: data.recentClaims.map((c) => ({
        id: c.id,
        claimNumber: c.claimNumber,
        status: c.status,
        riskLevel: c.riskLevel,
        riskScore: c.riskScore,
        submittedAt: c.submittedAt,
        reportedIssue: c.reportedIssue,
        dealer: c.dealer.businessName,
        serialNumber: c.mattress.serialNumber,
      })),
      topDealers: data.topDealers,
    };
  });
}
