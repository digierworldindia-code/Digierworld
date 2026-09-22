/**
 * Test harness.
 *
 * Builds the real application and drives it through `app.inject`, so requests
 * travel the whole pipeline: security headers, CORS, CSRF, authentication,
 * permission checks, database scope and Row Level Security. Nothing is stubbed.
 *
 * `Client` is a tiny cookie jar. It behaves like a browser, which matters
 * because the session, the refresh token and the CSRF token are all cookies —
 * testing with bearer tokens instead would skip the CSRF path entirely.
 */
import type { FastifyInstance } from 'fastify';
import { buildApp } from '../src/app.js';
import { getPrisma, type PrismaClient } from '@colifees/database';
import { hashPassword } from '@colifees/auth';

export const TEST_PASSWORD = 'Wt4&gLpzR91Vm#Qy';

export interface TestResponse<T = any> {
  status: number;
  body: T;
  headers: Record<string, unknown>;
  raw: string;
}

export class Client {
  private cookies = new Map<string, string>();

  constructor(private readonly app: FastifyInstance) {}

  /** Mirrors what a browser does with the CSRF cookie the console reads. */
  private csrfToken(): string | undefined {
    return this.cookies.get('clf_csrf');
  }

  private cookieHeader(): string {
    return [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  private absorb(setCookie: unknown): void {
    if (!setCookie) return;
    const list = Array.isArray(setCookie) ? setCookie : [setCookie];
    for (const raw of list) {
      const [pair] = String(raw).split(';');
      const index = pair?.indexOf('=') ?? -1;
      if (index === -1 || !pair) continue;
      const name = pair.slice(0, index);
      const value = pair.slice(index + 1);
      if (value === '' ) this.cookies.delete(name);
      else this.cookies.set(name, value);
    }
  }

  async request<T = any>(
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    url: string,
    options: { body?: unknown; headers?: Record<string, string>; omitCsrf?: boolean; payload?: any } = {},
  ): Promise<TestResponse<T>> {
    const headers: Record<string, string> = {
      ...(this.cookies.size > 0 ? { cookie: this.cookieHeader() } : {}),
      ...options.headers,
    };

    const csrf = this.csrfToken();
    if (csrf && !options.omitCsrf && method !== 'GET') {
      headers['x-csrf-token'] = csrf;
    }

    const response = await this.app.inject({
      method,
      url,
      headers,
      ...(options.payload !== undefined
        ? { payload: options.payload }
        : options.body !== undefined
          ? { payload: options.body as object }
          : {}),
    });

    this.absorb(response.headers['set-cookie']);

    let body: any = null;
    try {
      body = response.body ? JSON.parse(response.body) : null;
    } catch {
      body = response.body;
    }

    return {
      status: response.statusCode,
      body,
      headers: response.headers as Record<string, unknown>,
      raw: response.body,
    };
  }

  get = <T = any>(url: string, options?: Parameters<Client['request']>[2]) => this.request<T>('GET', url, options);
  post = <T = any>(url: string, body?: unknown, options: Parameters<Client['request']>[2] = {}) =>
    this.request<T>('POST', url, { ...options, body });
  patch = <T = any>(url: string, body?: unknown, options: Parameters<Client['request']>[2] = {}) =>
    this.request<T>('PATCH', url, { ...options, body });
  put = <T = any>(url: string, body?: unknown, options: Parameters<Client['request']>[2] = {}) =>
    this.request<T>('PUT', url, { ...options, body });
  del = <T = any>(url: string, body?: unknown, options: Parameters<Client['request']>[2] = {}) =>
    this.request<T>('DELETE', url, { ...options, body });

  async login(email: string, password = TEST_PASSWORD): Promise<TestResponse> {
    const response = await this.post('/auth/login', { email, password });
    return response;
  }

  /** Drops the session cookies without telling the server. */
  forgetCookies(): void {
    this.cookies.clear();
  }

  peekCookie(name: string): string | undefined {
    return this.cookies.get(name);
  }

  setCookie(name: string, value: string): void {
    this.cookies.set(name, value);
  }
}

export interface TestWorld {
  app: FastifyInstance;
  prisma: PrismaClient;
  admin: { email: string; id: string };
  warehouse: { email: string; id: string };
  warrantyManager: { email: string; id: string };
  dealerA: { id: string; code: string; userEmail: string; userId: string };
  dealerB: { id: string; code: string; userEmail: string; userId: string };
  warehouseId: string;
  productVariantId: string;
}

let cachedApp: FastifyInstance | undefined;

export async function startApp(): Promise<FastifyInstance> {
  if (!cachedApp) cachedApp = await buildApp();
  await cachedApp.ready();
  return cachedApp;
}

/**
 * Creates the fixtures each suite needs, under a unique suffix so repeated runs
 * against the same database do not collide.
 */
export async function seedWorld(suffix: string): Promise<TestWorld> {
  const app = await startApp();
  const prisma = getPrisma();
  const hash = await hashPassword(TEST_PASSWORD);

  const staffScope = async <T>(fn: (tx: any) => Promise<T>): Promise<T> =>
    prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      return fn(tx);
    }, { timeout: 60_000 });

  return staffScope(async (tx) => {
    const roleByKey = new Map(
      (await tx.role.findMany({ select: { id: true, key: true } })).map((r: any) => [r.key, r.id]),
    );

    const makeUser = async (email: string, fullName: string, roleKey: string) => {
      const user = await tx.user.create({
        data: {
          email,
          fullName,
          passwordHash: hash,
          status: 'ACTIVE',
          mustChangePassword: false,
          passwordChangedAt: new Date(),
        },
        select: { id: true, email: true },
      });
      await tx.userRole.create({ data: { userId: user.id, roleId: roleByKey.get(roleKey)! } });
      return user;
    };

    const admin = await makeUser(`admin.${suffix}@test.colifees.local`, 'Test Admin', 'ADMIN');
    const warehouse = await makeUser(`wh.${suffix}@test.colifees.local`, 'Test Warehouse', 'WAREHOUSE');
    const warrantyManager = await makeUser(`wm.${suffix}@test.colifees.local`, 'Test Warranty Manager', 'WARRANTY_MANAGER');

    const makeDealer = async (index: number) => {
      const [{ code }] = await tx.$queryRaw<{ code: string }[]>`SELECT colifees_next_dealer_code() AS code`;
      const dealer = await tx.dealer.create({
        data: {
          code,
          businessName: `Test Dealer ${index} ${suffix}`,
          ownerName: `Owner ${index}`,
          phone: `98765${String(10000 + index).slice(0, 5)}`,
          addressLine1: `${index} Test Road`,
          city: 'Jaipur',
          state: 'Rajasthan',
          pincode: '302001',
          status: 'ACTIVE',
          publicListed: true,
        },
        select: { id: true, code: true },
      });
      const user = await tx.user.create({
        data: {
          email: `dealer${index}.${suffix}@test.colifees.local`,
          fullName: `Dealer ${index} User`,
          passwordHash: hash,
          status: 'ACTIVE',
          mustChangePassword: false,
          passwordChangedAt: new Date(),
        },
        select: { id: true, email: true },
      });
      await tx.userRole.create({ data: { userId: user.id, roleId: roleByKey.get('DEALER')! } });
      await tx.dealerUser.create({ data: { userId: user.id, dealerId: dealer.id, isPrimary: true } });
      return { id: dealer.id, code: dealer.code, userEmail: user.email, userId: user.id };
    };

    const dealerA = await makeDealer(1);
    const dealerB = await makeDealer(2);

    const warehouseRecord = await tx.warehouse.upsert({
      where: { code: 'WH-TEST' },
      create: {
        code: 'WH-TEST',
        name: 'Test Plant',
        address: '1 Industrial Way',
        city: 'Jaipur',
        state: 'Rajasthan',
        pincode: '302022',
      },
      update: {},
      select: { id: true },
    });

    const variant = await tx.productVariant.findFirstOrThrow({ select: { id: true } });

    return {
      app,
      prisma,
      admin,
      warehouse,
      warrantyManager,
      dealerA,
      dealerB,
      warehouseId: warehouseRecord.id,
      productVariantId: variant.id,
    };
  });
}

/** A minimal, genuinely valid 1x1 PNG, used to test the upload path. */
export function validPngBytes(seed = 0): Buffer {
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAAWUlEQVR42u3BAQ0AAADCoPdPbQ43oAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAvBsjvAABsJ1AIwAAAABJRU5ErkJggg==',
    'base64',
  );
  if (seed === 0) return png;
  // Perturbing a trailing byte changes the SHA-256 without breaking the header,
  // which is how the duplicate-photo signal is exercised.
  const copy = Buffer.from(png);
  copy[copy.length - 1] = (copy[copy.length - 1]! + seed) % 256;
  return copy;
}

/** Builds a multipart body by hand so the test controls every byte sent. */
export function multipartBody(
  files: { field: string; filename: string; contentType: string; content: Buffer }[],
): { payload: Buffer; headers: Record<string, string> } {
  const boundary = `----colifeestest${Date.now()}${Math.random().toString(36).slice(2)}`;
  const parts: Buffer[] = [];

  for (const file of files) {
    parts.push(
      Buffer.from(
        `--${boundary}\r\n` +
          `Content-Disposition: form-data; name="${file.field}"; filename="${file.filename}"\r\n` +
          `Content-Type: ${file.contentType}\r\n\r\n`,
      ),
      file.content,
      Buffer.from('\r\n'),
    );
  }
  parts.push(Buffer.from(`--${boundary}--\r\n`));

  return {
    payload: Buffer.concat(parts),
    headers: { 'content-type': `multipart/form-data; boundary=${boundary}` },
  };
}
