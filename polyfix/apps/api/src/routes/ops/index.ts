/**
 * Staff console endpoints.
 *
 * Every route here declares the permission it needs. There is no "admin
 * area" blanket check: a warehouse user reaching a warranty decision endpoint
 * is refused by the same mechanism that refuses an anonymous caller.
 *
 * Staff users carry no dealer scope, so Row Level Security lets them see all
 * rows. That makes the permission declaration on each route the operative
 * control, which is why they are listed explicitly rather than inherited.
 */
import type { FastifyInstance } from 'fastify';
import { registerDashboardRoutes } from './dashboard.js';
import { registerManufacturingRoutes } from './manufacturing.js';
import { registerLogisticsRoutes } from './logistics.js';
import { registerNetworkRoutes } from './network.js';
import { registerWarrantyRoutes } from './warranty.js';
import { registerContentRoutes } from './content.js';
import { registerSystemRoutes } from './system.js';

export async function registerOpsRoutes(app: FastifyInstance): Promise<void> {
  // One gate in front of the whole tree. Registered on this encapsulated
  // context, so it runs for every route below regardless of what that route
  // declares for itself, and a new route cannot forget it.
  app.addHook('preHandler', app.requireStaff);

  await app.register(registerDashboardRoutes);
  await app.register(registerManufacturingRoutes);
  await app.register(registerLogisticsRoutes);
  await app.register(registerNetworkRoutes);
  await app.register(registerWarrantyRoutes);
  await app.register(registerContentRoutes);
  await app.register(registerSystemRoutes, { prefix: '/system' });
}
