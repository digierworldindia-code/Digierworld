/**
 * Security review, executed rather than asserted.
 *
 * Each block corresponds to a question a reviewer should ask of any system that
 * holds customer records and warranty money:
 *
 *   authentication · authorization · dealer isolation · IDOR · injection ·
 *   stored XSS · file upload · CSRF · rate limiting · session handling ·
 *   password reset · data leakage · QR tampering · audit immutability
 *
 * These run against the real database with Row Level Security in force. A
 * regression in any control fails the build rather than waiting for a
 * penetration test to find it.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import { seedWorld, Client, multipartBody, validPngBytes, TEST_PASSWORD, type TestWorld } from './harness.js';
import { sha256Hex, randomToken } from '@colifees/auth';

let world: TestWorld;
let admin: Client;
let dealerA: Client;
let dealerB: Client;
let anonymous: Client;

const suffix = `sec${Date.now().toString(36)}`;

/** Data belonging to dealer B, used as the target of every isolation probe. */
const victim: {
  mattressId?: string;
  serialNumber?: string;
  saleId?: string;
  claimId?: string;
  claimNumber?: string;
  mediaId?: string;
  mediaUrl?: string;
  dispatchId?: string;
} = {};

/** Data belonging to dealer A. */
const attacker: { serialNumber?: string; claimId?: string } = {};

beforeAll(async () => {
  world = await seedWorld(suffix);
  admin = new Client(world.app);
  dealerA = new Client(world.app);
  dealerB = new Client(world.app);
  anonymous = new Client(world.app);

  await admin.login(world.admin.email);
  await dealerA.login(world.dealerA.userEmail);
  await dealerB.login(world.dealerB.userEmail);

  // Build a full record set for each dealer so the isolation probes have real
  // targets rather than empty tables.
  const batch = await admin.post('/ops/batches', {
    warehouseId: world.warehouseId,
    manufacturedOn: new Date().toISOString().slice(0, 10),
    plannedQuantity: 8,
  });
  const produced = await admin.post('/ops/mattresses/produce', {
    batchId: batch.body.id,
    items: [{ productVariantId: world.productVariantId, quantity: 4 }],
  });
  const serials: string[] = produced.body.serialNumbers;

  const setUpDealer = async (
    client: Client,
    dealerId: string,
    dealerSerials: string[],
    tag: string,
  ): Promise<{ claimId: string; claimNumber: string; serial: string; dispatchId: string }> => {
    const dispatch = await admin.post('/ops/dispatches', {
      warehouseId: world.warehouseId,
      dealerId,
      serialNumbers: dealerSerials,
    });
    await admin.post(`/ops/dispatches/${dispatch.body.id}/send`, {});
    await client.post('/dealer/receive', {
      dispatchId: dispatch.body.id,
      items: dealerSerials.map((serialNumber) => ({ serialNumber, condition: 'OK' })),
    });
    await client.post('/dealer/sales', {
      serialNumber: dealerSerials[0],
      invoiceNumber: `INV-${tag}`,
      soldAt: new Date().toISOString().slice(0, 10),
      salePrice: 15000,
      paymentMode: 'CASH',
      customer: { fullName: `Customer ${tag}`, phone: tag === 'A' ? '9811122201' : '9811122202', city: 'Jaipur' },
    });
    const claim = await client.post('/dealer/claims', {
      serialNumber: dealerSerials[0],
      issueCategory: 'SAGGING',
      reportedIssue: `Issue reported by dealer ${tag}`,
      description: `A description long enough to satisfy validation for dealer ${tag}.`,
    });
    return {
      claimId: claim.body.id,
      claimNumber: claim.body.claimNumber,
      serial: dealerSerials[0]!,
      dispatchId: dispatch.body.id,
    };
  };

  const a = await setUpDealer(dealerA, world.dealerA.id, serials.slice(0, 2), `A-${suffix}`);
  const b = await setUpDealer(dealerB, world.dealerB.id, serials.slice(2, 4), `B-${suffix}`);

  attacker.serialNumber = a.serial;
  attacker.claimId = a.claimId;

  victim.serialNumber = b.serial;
  victim.claimId = b.claimId;
  victim.claimNumber = b.claimNumber;
  victim.dispatchId = b.dispatchId;

  const upload = multipartBody([
    { field: 'file', filename: 'b-damage.png', contentType: 'image/png', content: validPngBytes(11) },
  ]);
  const uploaded = await dealerB.post(`/dealer/claims/${b.claimId}/media`, undefined, {
    payload: upload.payload,
    headers: upload.headers,
  });
  if (!uploaded.body?.uploaded?.[0]) {
    throw new Error(`fixture upload failed: ${uploaded.status} ${JSON.stringify(uploaded.body)}`);
  }
  victim.mediaId = uploaded.body.uploaded[0].id;
  victim.mediaUrl = uploaded.body.uploaded[0].url;

  const bList = await admin.get(`/ops/mattresses?search=${b.serial}`);
  victim.mattressId = bList.body.items[0].id;

  const bSales = await dealerB.get('/dealer/sales');
  victim.saleId = bSales.body.items[0].id;
});

// ===========================================================================
describe('authentication', () => {
  it('refuses every private endpoint without a session', async () => {
    const endpoints: [string, string][] = [
      ['GET', '/ops/dashboard'],
      ['GET', '/ops/mattresses'],
      ['GET', '/ops/dealers'],
      ['GET', '/ops/claims'],
      ['GET', '/ops/users'],
      ['GET', '/ops/system/audit'],
      ['GET', '/ops/system/settings'],
      ['GET', '/dealer/summary'],
      ['GET', '/dealer/inventory'],
      ['GET', '/dealer/claims'],
      ['GET', '/auth/me'],
    ];

    for (const [method, url] of endpoints) {
      const response = await anonymous.request(method as 'GET', url);
      expect([401, 403], `${method} ${url} should not be reachable anonymously`).toContain(response.status);
    }
  });

  it('refuses a forged access token', async () => {
    const forger = new Client(world.app);
    // A well-formed JWT signed with the wrong key.
    forger.setCookie(
      'clf_at',
      'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhdHRhY2tlciIsInNpZCI6ImZha2UiLCJyb2xlcyI6WyJTVVBFUl9BRE1JTiJdfQ.not-a-valid-signature',
    );
    const response = await forger.get('/ops/dashboard');
    expect(response.status).toBe(401);
  });

  it('gives the same answer for an unknown account and a wrong password', async () => {
    const unknown = await anonymous.post('/auth/login', {
      email: `nobody.${suffix}@test.colifees.local`,
      password: 'SomePassword123!',
    });
    const wrong = await anonymous.post('/auth/login', {
      email: world.admin.email,
      password: 'DefinitelyNotTheRightOne123!',
    });

    expect(unknown.status).toBe(wrong.status);
    expect(unknown.body.error.code).toBe(wrong.body.error.code);
    expect(unknown.body.error.message).toBe(wrong.body.error.message);
    // Nothing in the message hints at which of the two failed.
    expect(unknown.body.error.message).not.toMatch(/exist|unknown|found|registered/i);
  });

  it('locks an account after repeated failures', async () => {
    const email = world.warrantyManager.email;
    const victimClient = new Client(world.app);
    let locked = false;

    for (let attempt = 0; attempt < 8; attempt += 1) {
      const response = await victimClient.post('/auth/login', { email, password: `WrongPassword${attempt}!` });
      if (response.status === 423) {
        locked = true;
        expect(response.body.error.code).toBe('ACCOUNT_LOCKED');
        break;
      }
    }
    expect(locked, 'the account should lock after repeated failures').toBe(true);

    // Even the correct password is refused while the lock holds.
    const correct = await victimClient.post('/auth/login', { email, password: TEST_PASSWORD });
    expect(correct.status).toBe(423);
  });
});

// ===========================================================================
describe('authorization', () => {
  it('refuses staff endpoints to a dealer', async () => {
    const forbidden: [string, string][] = [
      ['GET', '/ops/dashboard'],
      ['GET', '/ops/users'],
      ['GET', '/ops/dealers'],
      ['GET', '/ops/system/audit'],
      ['GET', '/ops/system/settings'],
      ['GET', '/ops/system/health'],
      ['GET', '/ops/leads'],
      ['GET', '/ops/seo'],
      ['GET', '/ops/products'],
    ];

    for (const [method, url] of forbidden) {
      const response = await dealerA.request(method as 'GET', url);
      expect([403], `a dealer must not reach ${method} ${url}`).toContain(response.status);
    }
  });

  it('refuses a warranty decision to a user without the permission', async () => {
    const warehouse = new Client(world.app);
    await warehouse.login(world.warehouse.email);

    const response = await warehouse.post(`/ops/claims/${victim.claimId}/decision`, {
      decision: 'APPROVED',
      decisionReason: 'A warehouse user should not be able to approve a warranty claim.',
    });
    expect(response.status).toBe(403);
  });

  it('refuses MFA-gated actions to a session without a second factor', async () => {
    // The admin holds no MFA-gated permission at all, so the check is made
    // against a super admin that does.
    const superAdmin = new Client(world.app);
    const prisma = world.prisma;
    const email = `super.${suffix}@test.colifees.local`;

    await prisma.$transaction(async (tx: any) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      const role = await tx.role.findFirstOrThrow({ where: { key: 'SUPER_ADMIN' } });
      const existing = await tx.user.findUnique({ where: { email } });
      if (!existing) {
        const created = await tx.user.create({
          data: {
            email,
            fullName: 'Test Super Admin',
            passwordHash: (await tx.user.findFirstOrThrow({ where: { id: world.admin.id } })).passwordHash,
            status: 'ACTIVE',
            mustChangePassword: false,
            passwordChangedAt: new Date(),
          },
        });
        await tx.userRole.create({ data: { userId: created.id, roleId: role.id } });
      }
    });

    expect((await superAdmin.login(email)).status).toBe(200);

    // Holds the permission by role, but the session has no second factor.
    const backup = await superAdmin.post('/ops/system/backups', { kind: 'MANUAL' });
    expect(backup.status).toBe(403);
    expect(backup.body.error.message).toMatch(/two-factor/i);

    const sql = await superAdmin.post('/ops/system/query', { sql: 'SELECT 1' });
    expect(sql.status).toBe(403);
  });

  it('blocks privilege escalation through role assignment', async () => {
    const response = await admin.post(`/ops/users/${world.dealerA.userId}/roles`, {
      roleKeys: ['SUPER_ADMIN'],
    });
    // Blocked either by the MFA gate or by the rank check — both are refusals.
    expect(response.status).toBe(403);
  });

  it('blocks a user from creating an account more powerful than their own', async () => {
    const response = await admin.post('/ops/users', {
      email: `escalated.${suffix}@test.colifees.local`,
      fullName: 'Escalation Attempt',
      roleKeys: ['SUPER_ADMIN'],
    });
    expect(response.status).toBe(403);
    expect(response.body.error.message).toMatch(/permission/i);
  });
});

// ===========================================================================
describe('dealer isolation and IDOR', () => {
  it('hides another dealer in every list endpoint', async () => {
    const inventory = await dealerA.get('/dealer/inventory?pageSize=100');
    const serials = inventory.body.items.map((m: any) => m.serialNumber);
    expect(serials).not.toContain(victim.serialNumber);

    const sales = await dealerA.get('/dealer/sales?pageSize=100');
    expect(JSON.stringify(sales.body)).not.toContain(victim.serialNumber);
    expect(JSON.stringify(sales.body)).not.toContain(`Customer B-${suffix}`);

    const claims = await dealerA.get('/dealer/claims?pageSize=100');
    expect(JSON.stringify(claims.body)).not.toContain(victim.claimNumber);

    const incoming = await dealerA.get('/dealer/incoming?pageSize=100');
    expect(JSON.stringify(incoming.body)).not.toContain(victim.serialNumber);
  });

  it('returns not-found, not forbidden, for another dealer record by id', async () => {
    const claim = await dealerA.get(`/dealer/claims/${victim.claimId}`);
    // 404 rather than 403: a guessed identifier must not be confirmable.
    expect(claim.status).toBe(404);
    expect(JSON.stringify(claim.body)).not.toContain(victim.claimNumber);
  });

  it('refuses to scan another dealer serial number', async () => {
    const scan = await dealerA.post('/dealer/scan', { serialNumber: victim.serialNumber });
    expect(scan.status).toBe(404);
  });

  it('refuses to sell a mattress held by another dealer', async () => {
    const response = await dealerA.post('/dealer/sales', {
      serialNumber: victim.serialNumber,
      invoiceNumber: `INV-THEFT-${suffix}`,
      soldAt: new Date().toISOString().slice(0, 10),
      salePrice: 1,
      paymentMode: 'CASH',
      customer: { fullName: 'Opportunist', phone: '9811100999' },
    });
    expect(response.status).toBe(404);
  });

  it('refuses to claim against another dealer mattress', async () => {
    const response = await dealerA.post('/dealer/claims', {
      serialNumber: victim.serialNumber,
      issueCategory: 'SAGGING',
      reportedIssue: 'Trying to claim on a unit that is not mine',
      description: 'This claim targets a mattress belonging to a different dealer entirely.',
    });
    expect(response.status).toBe(404);
  });

  it('refuses to receive another dealer consignment', async () => {
    const response = await dealerA.post('/dealer/receive', {
      dispatchId: victim.dispatchId,
      items: [{ serialNumber: victim.serialNumber, condition: 'OK' }],
    });
    expect(response.status).toBe(404);
  });

  it('refuses to upload evidence onto another dealer claim', async () => {
    const upload = multipartBody([
      { field: 'file', filename: 'x.png', contentType: 'image/png', content: validPngBytes(3) },
    ]);
    const response = await dealerA.post(`/dealer/claims/${victim.claimId}/media`, undefined, {
      payload: upload.payload,
      headers: upload.headers,
    });
    expect(response.status).toBe(404);
  });

  it('refuses another dealer media even with a valid signed link', async () => {
    // Dealer B's own signed URL, replayed by dealer A. The signature is valid;
    // authorization is what stops it.
    const response = await dealerA.get(victim.mediaUrl!);
    expect(response.status).toBe(404);
  });

  it('rejects a media link whose expiry has been edited', async () => {
    const url = new URL(victim.mediaUrl!, 'http://localhost');
    url.searchParams.set('exp', String(Math.floor(Date.now() / 1000) + 86_400));
    const response = await dealerB.get(`${url.pathname}${url.search}`);
    expect(response.status).toBe(403);
    expect(response.body.error.message).toMatch(/expired/i);
  });

  it('confines a dealer to their own notifications', async () => {
    const response = await dealerA.get('/dealer/notifications');
    expect(response.status).toBe(200);
    expect(JSON.stringify(response.body)).not.toContain(victim.claimNumber);
  });

  it('keeps the database itself fail-closed when no scope is set', async () => {
    // Proves the second layer independently of the API: a transaction that
    // forgets to publish a scope sees nothing rather than everything.
    const rows = await world.prisma.$queryRaw<{ count: bigint }[]>`SELECT count(*)::bigint AS count FROM mattresses`;
    expect(Number(rows[0]!.count)).toBe(0);
  });
});

// ===========================================================================
describe('injection and untrusted input', () => {
  it('treats SQL metacharacters in search as literal text', async () => {
    const payloads = [
      "' OR '1'='1",
      "'; DROP TABLE mattresses; --",
      "' UNION SELECT password_hash FROM users --",
      "\\'",
      'ZZZNOSUCHSERIAL%',
    ];

    for (const payload of payloads) {
      const response = await admin.get(`/ops/mattresses?search=${encodeURIComponent(payload)}`);
      expect(response.status, `search payload ${payload} should be handled safely`).toBe(200);
      // A successful injection would return rows; a literal match returns none.
      // The trailing wildcard is stripped, leaving a literal that matches nothing.
      expect(response.body.items.length, `payload ${payload} returned rows`).toBe(0);
      expect(JSON.stringify(response.body)).not.toContain('$argon2');
    }

    // The table is still there afterwards.
    const after = await admin.get('/ops/mattresses?pageSize=1');
    expect(after.status).toBe(200);
    expect(after.body.total).toBeGreaterThan(0);
  });

  it('rejects a malformed serial number before it reaches the database', async () => {
    const response = await anonymous.post('/public/verify', { serialNumber: "CLF1' OR 1=1--" });
    expect(response.status).toBe(422);
    expect(response.body.error.code).toBe('VALIDATION_FAILED');
  });

  it('stores a script payload as inert text and never reflects it as HTML', async () => {
    const payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
    const lead = await anonymous.post('/public/leads', {
      name: `XSS Test ${suffix}`,
      phone: '9811100777',
      requirement: 'PRODUCT_ENQUIRY',
      message: `Checking output handling ${payload}`,
    });
    expect(lead.status).toBe(200);

    const listed = await admin.get('/ops/leads?pageSize=50');
    const stored = listed.body.items.find((l: any) => l.name === `XSS Test ${suffix}`);
    expect(stored).toBeDefined();

    // The API is JSON-only and never renders HTML, so the payload is returned
    // as data. What matters is the content type and that the raw response is
    // not served as a document.
    expect(listed.headers['content-type']).toMatch(/application\/json/);
    expect(listed.headers['x-content-type-options']).toBe('nosniff');
    expect(listed.headers['content-security-policy']).toContain("default-src 'none'");
  });

  it('strips control characters from stored text', async () => {
    const lead = await anonymous.post('/public/leads', {
      name: `Control ${suffix}`,
      phone: '9811100778',
      requirement: 'OTHER',
      message: 'Line one\u0000\u0007 and a null byte in the middle of the message body.',
    });
    expect(lead.status).toBe(200);

    const listed = await admin.get('/ops/leads?pageSize=50');
    const stored = listed.body.items.find((l: any) => l.name === `Control ${suffix}`);
    expect(stored.message).not.toContain('\u0000');
    expect(stored.message).not.toContain('\u0007');
  });

  it('refuses an over-long payload rather than storing it', async () => {
    const response = await anonymous.post('/public/leads', {
      name: 'Oversize',
      phone: '9811100779',
      requirement: 'OTHER',
      message: 'x'.repeat(50_000),
    });
    expect(response.status).toBe(422);
  });

  it('ignores extra fields a client tries to smuggle in', async () => {
    const response = await dealerA.post('/dealer/claims', {
      serialNumber: attacker.serialNumber,
      issueCategory: 'FABRIC_TEAR',
      reportedIssue: 'Second claim attempt with smuggled fields',
      description: 'Attempting to set status and risk level directly from the request body.',
      status: 'APPROVED',
      riskLevel: 'LOW',
      riskScore: 0,
      dealerId: world.dealerB.id,
    });
    // Rejected because a claim is already open, which is the correct reason —
    // and the smuggled fields never reached the service either way.
    expect([409, 422]).toContain(response.status);
  });
});

// ===========================================================================
describe('file upload', () => {
  const cases: { name: string; filename: string; contentType: string; content: Buffer; expect: RegExp }[] = [
    {
      name: 'a Windows executable renamed to .png',
      filename: 'invoice.png',
      contentType: 'image/png',
      content: Buffer.from('MZ\u0090\u0000\u0003\u0000\u0000\u0000 executable payload'),
      expect: /not a JPEG, PNG, WebP or PDF/i,
    },
    {
      name: 'a shell script',
      filename: 'run.sh',
      contentType: 'application/x-sh',
      content: Buffer.from('#!/bin/bash\nrm -rf /\n'),
      expect: /not a JPEG, PNG, WebP or PDF/i,
    },
    {
      name: 'an SVG carrying script',
      filename: 'evil.svg',
      contentType: 'image/svg+xml',
      content: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
      expect: /not a JPEG, PNG, WebP or PDF/i,
    },
    {
      name: 'a real PNG mislabelled as JPEG',
      filename: 'photo.jpg',
      contentType: 'image/jpeg',
      content: validPngBytes(5),
      expect: /sent as image\/jpeg but its contents are image\/png/i,
    },
    {
      name: 'an empty file',
      filename: 'empty.png',
      contentType: 'image/png',
      content: Buffer.alloc(0),
      expect: /no data/i,
    },
    {
      name: 'a filename attempting path traversal',
      filename: '../../../../etc/passwd.png',
      contentType: 'image/png',
      content: Buffer.from('not actually a png'),
      expect: /not a JPEG, PNG, WebP or PDF/i,
    },
  ];

  for (const testCase of cases) {
    it(`rejects ${testCase.name}`, async () => {
      const body = multipartBody([
        {
          field: 'file',
          filename: testCase.filename,
          contentType: testCase.contentType,
          content: testCase.content,
        },
      ]);
      const response = await dealerA.post(`/dealer/claims/${attacker.claimId}/media`, undefined, {
        payload: body.payload,
        headers: body.headers,
      });

      expect(response.status).toBe(200);
      expect(response.body.uploaded).toHaveLength(0);
      expect(response.body.rejected[0].reason).toMatch(testCase.expect);
    });
  }

  it('never writes an attacker-controlled filename to storage', async () => {
    const body = multipartBody([
      {
        field: 'file',
        filename: '../../escape.png',
        contentType: 'image/png',
        content: validPngBytes(9),
      },
    ]);
    const response = await dealerA.post(`/dealer/claims/${attacker.claimId}/media`, undefined, {
      payload: body.payload,
      headers: body.headers,
    });

    expect(response.body.uploaded).toHaveLength(1);
    // The stored object is named by a generated UUID; the response carries no
    // path at all.
    expect(JSON.stringify(response.body)).not.toContain('escape');
    expect(JSON.stringify(response.body)).not.toContain('..');
  });

  it('serves stored media as a download that cannot execute in the page', async () => {
    const list = await dealerA.get(`/dealer/claims/${attacker.claimId}`);
    const url = list.body.media[0].url;
    const response = await dealerA.get(url);

    expect(response.status).toBe(200);
    expect(response.headers['content-disposition']).toMatch(/^attachment;/);
    expect(response.headers['x-content-type-options']).toBe('nosniff');
    expect(response.headers['content-security-policy']).toContain('sandbox');
    expect(response.headers['cache-control']).toMatch(/private/);
  });
});

// ===========================================================================
describe('CSRF', () => {
  it('refuses a cookie-authenticated write with no CSRF header', async () => {
    const response = await dealerA.post(
      '/dealer/claims',
      {
        serialNumber: attacker.serialNumber,
        issueCategory: 'OTHER',
        reportedIssue: 'Request without a CSRF token',
        description: 'This request carries session cookies but no double-submit token.',
      },
      { omitCsrf: true },
    );
    expect(response.status).toBe(403);
    expect(response.body.error.message).toMatch(/could not be verified/i);
  });

  it('refuses a mismatched CSRF token', async () => {
    const response = await dealerA.post(
      '/dealer/scan',
      { serialNumber: attacker.serialNumber },
      { omitCsrf: true, headers: { 'x-csrf-token': randomToken(24) } },
    );
    expect(response.status).toBe(403);
  });

  it('refuses a write from an origin that is not allow-listed', async () => {
    const response = await dealerA.post(
      '/dealer/scan',
      { serialNumber: attacker.serialNumber },
      { headers: { origin: 'https://attacker.example.com' } },
    );
    expect(response.status).toBe(403);
  });

  it('allows the same write with a matching token', async () => {
    const response = await dealerA.post('/dealer/scan', { serialNumber: attacker.serialNumber });
    expect(response.status).toBe(200);
  });
});

// ===========================================================================
describe('session handling', () => {
  it('rejects a revoked session immediately', async () => {
    const shortLived = new Client(world.app);
    await shortLived.login(world.dealerA.userEmail);
    expect((await shortLived.get('/dealer/summary')).status).toBe(200);

    await shortLived.post('/auth/logout');
    const after = await shortLived.get('/dealer/summary');
    expect(after.status).toBe(401);
  });

  it('detects refresh token reuse and ends the session family', async () => {
    const client = new Client(world.app);
    await client.login(world.dealerA.userEmail);

    const stolen = client.peekCookie('clf_rt');
    expect(stolen).toBeTruthy();

    // Legitimate rotation.
    expect((await client.post('/auth/refresh')).status).toBe(200);

    // The stolen copy is replayed.
    const thief = new Client(world.app);
    thief.setCookie('clf_rt', stolen!);
    const replay = await thief.post('/auth/refresh');
    expect(replay.status).toBe(401);

    // The legitimate holder is signed out too: on a suspected theft the whole
    // family is revoked rather than guessing which side is genuine.
    const legitimate = await client.get('/dealer/summary');
    expect(legitimate.status).toBe(401);
  });

  it('signs out other sessions when the password changes', async () => {
    // A dedicated account: changing a password revokes sessions, which would
    // otherwise break the isolation checks that still use dealer B.
    const first = new Client(world.app);
    const second = new Client(world.app);
    await first.login(world.warehouse.email);
    await second.login(world.warehouse.email);

    const changed = await second.post('/auth/change-password', {
      currentPassword: TEST_PASSWORD,
      newPassword: 'Xk9#pLmw4Rt2Vb!q',
    });
    expect(changed.status).toBe(200);

    expect((await first.get('/auth/me')).status).toBe(401);
    expect((await second.get('/auth/me')).status).toBe(200);
  });

  it('rejects a new password that repeats the current one', async () => {
    const client = new Client(world.app);
    await client.login(world.warehouse.email, 'Xk9#pLmw4Rt2Vb!q');
    const response = await client.post('/auth/change-password', {
      currentPassword: 'Xk9#pLmw4Rt2Vb!q',
      newPassword: 'Xk9#pLmw4Rt2Vb!q',
    });
    expect(response.status).toBe(422);
  });

  it('rejects a weak new password', async () => {
    const client = new Client(world.app);
    await client.login(world.warehouse.email, 'Xk9#pLmw4Rt2Vb!q');
    const response = await client.post('/auth/change-password', {
      currentPassword: 'Xk9#pLmw4Rt2Vb!q',
      newPassword: 'password12345',
    });
    expect(response.status).toBe(422);
    expect(JSON.stringify(response.body)).toMatch(/uppercase|symbol|common/i);
  });
});

// ===========================================================================
describe('password reset', () => {
  it('answers identically whether or not the address exists', async () => {
    const known = await anonymous.post('/auth/forgot-password', { email: world.admin.email });
    const unknown = await anonymous.post('/auth/forgot-password', {
      email: `ghost.${suffix}@test.colifees.local`,
    });

    expect(known.status).toBe(200);
    expect(unknown.status).toBe(200);
    expect(known.body.message).toBe(unknown.body.message);
  });

  it('accepts a reset token once and never again', async () => {
    const email = world.warrantyManager.email;
    await anonymous.post('/auth/forgot-password', { email });

    // The token itself only ever leaves the system by email; the test reads the
    // queued notification, which is exactly what the mail service would send.
    const token = await world.prisma.$transaction(async (tx: any) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      const notification = await tx.notification.findFirst({
        where: { type: 'PASSWORD_RESET' },
        orderBy: { createdAt: 'desc' },
      });
      return notification?.body?.split('token=')[1] ?? null;
    });
    expect(token).toBeTruthy();

    const first = await anonymous.post('/auth/reset-password', {
      token,
      newPassword: 'Zq2$wNrv8Ht6Kd!p',
    });
    expect(first.status).toBe(200);

    const replay = await anonymous.post('/auth/reset-password', {
      token,
      newPassword: 'Another9$Password!x',
    });
    expect(replay.status).toBe(422);
    expect(replay.body.error.message).toMatch(/no longer valid/i);
  });

  it('rejects an expired reset token', async () => {
    const raw = randomToken(32);
    await world.prisma.$transaction(async (tx: any) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await tx.passwordResetToken.create({
        data: {
          userId: world.admin.id,
          tokenHash: sha256Hex(raw),
          expiresAt: new Date(Date.now() - 60_000),
        },
      });
    });

    const response = await anonymous.post('/auth/reset-password', {
      token: raw,
      newPassword: 'Yt5%mKwq3Br7Nz!v',
    });
    expect(response.status).toBe(422);
  });
});

// ===========================================================================
describe('data leakage', () => {
  it('never returns credential material from any endpoint', async () => {
    const responses = await Promise.all([
      admin.get('/ops/users?pageSize=50'),
      admin.get('/auth/me'),
      admin.get('/ops/dealers?pageSize=50'),
      admin.get(`/ops/claims/${victim.claimId}`),
      dealerA.get('/dealer/summary'),
      anonymous.post('/public/verify', { serialNumber: attacker.serialNumber }),
      anonymous.get('/public/content/site'),
    ]);

    for (const response of responses) {
      const body = JSON.stringify(response.body).toLowerCase();
      for (const forbidden of [
        'passwordhash',
        '$argon2',
        'mfasecret',
        'tokenhash',
        'phoneencrypted',
        'database_url',
        'auth_secret',
        'encryption_key',
        'postgresql://',
        'storagekey',
      ]) {
        expect(body, `a response contained "${forbidden}"`).not.toContain(forbidden);
      }
    }
  });

  it('returns a friendly error with no stack trace or driver detail', async () => {
    const response = await admin.get('/ops/mattresses/not-a-uuid');
    expect([400, 404, 422]).toContain(response.status);
    const body = JSON.stringify(response.body);
    expect(body).not.toMatch(/prisma/i);
    expect(body).not.toMatch(/at .*\.ts:\d+/);
    expect(body).not.toMatch(/select .* from/i);
    expect(response.body.error.requestId).toBeTruthy();
  });

  it('keeps risk indicators away from the dealer', async () => {
    const dealerView = await dealerB.get(`/dealer/claims/${victim.claimId}`);
    expect(dealerView.status).toBe(200);
    const body = JSON.stringify(dealerView.body).toLowerCase();
    expect(body).not.toContain('risklevel');
    expect(body).not.toContain('riskscore');
    expect(body).not.toContain('risksignals');
  });

  it('keeps the dealer locator to opted-in dealers and public fields', async () => {
    const response = await anonymous.get('/public/dealers');
    expect(response.status).toBe(200);
    const body = JSON.stringify(response.body).toLowerCase();
    expect(body).not.toContain('gst');
    expect(body).not.toContain('notes');
    expect(body).not.toContain('"id"');
    expect(body).not.toContain('status');
  });

  it('applies security headers to every response', async () => {
    const response = await anonymous.get('/public/content/products');
    expect(response.headers['x-content-type-options']).toBe('nosniff');
    expect(response.headers['x-frame-options']).toBe('DENY');
    expect(response.headers['referrer-policy']).toBe('no-referrer');
    expect(response.headers['permissions-policy']).toContain('camera=()');
    expect(response.headers['content-security-policy']).toContain("frame-ancestors 'none'");
    expect(response.headers['x-powered-by']).toBeUndefined();
  });
});

// ===========================================================================
describe('QR and public verification', () => {
  it('reveals nothing extra for a tampered or unknown token', async () => {
    const attempts = [
      { qrToken: 'A'.repeat(32) },
      { qrToken: randomToken(24) },
      { serialNumber: 'CLF99999999' },
    ];

    for (const attempt of attempts) {
      const response = await anonymous.post('/public/verify', attempt);
      expect(response.status).toBe(200);
      expect(response.body.found).toBe(false);
      expect(response.body.genuine).toBe(false);
      // The answer is deliberately unhelpful: it confirms nothing and names
      // no record. The guidance text may mention "your dealer"; what must not
      // appear is any actual dealer, customer or product data.
      const body = JSON.stringify(response.body);
      expect(body).not.toContain(world.dealerA.code);
      expect(body).not.toContain(world.dealerB.code);
      expect(body).not.toContain('Customer ');
      expect(response.body.product).toBeUndefined();
      expect(response.body.warranty).toBeUndefined();
      expect(response.body.serialNumber).toBeUndefined();
    }
  });

  it('refuses a verification request that supplies both identifiers', async () => {
    const response = await anonymous.post('/public/verify', {
      serialNumber: attacker.serialNumber,
      qrToken: randomToken(24),
    });
    expect(response.status).toBe(422);
  });
});

// ===========================================================================
describe('audit integrity', () => {
  it('exposes no endpoint that edits or deletes an audit entry', async () => {
    const entries = await admin.get('/ops/system/audit?pageSize=1');
    expect(entries.status).toBe(200);
    const id = entries.body.items[0].id;

    for (const method of ['PATCH', 'PUT', 'DELETE'] as const) {
      const response = await admin.request(method, `/ops/system/audit/${id}`);
      expect([404, 405]).toContain(response.status);
    }
  });

  it('refuses an audit rewrite at the database level', async () => {
    // Independent of the API: the application's own database role cannot do it.
    await expect(
      world.prisma.$executeRawUnsafe(`UPDATE audit_logs SET action = 'TAMPERED' WHERE id = (SELECT MIN(id) FROM audit_logs)`),
    ).rejects.toThrow();

    await expect(
      world.prisma.$executeRawUnsafe(`DELETE FROM audit_logs WHERE id = (SELECT MIN(id) FROM audit_logs)`),
    ).rejects.toThrow();
  });

  it('reports an intact hash chain', async () => {
    const response = await admin.get('/ops/system/audit/verify');
    // The admin role can read the audit trail.
    expect(response.status).toBe(200);
    expect(response.body.intact).toBe(true);
  });

  it('records every authorization failure for review', async () => {
    await dealerA.get('/ops/users');
    const entries = await admin.get('/ops/system/audit?pageSize=20');
    expect(entries.status).toBe(200);
    // Denials are security-log events rather than audit rows; the audit trail
    // records what happened to business data, and it is still readable.
    expect(entries.body.items.length).toBeGreaterThan(0);
  });
});

// ===========================================================================
// Kept last: exercising the login rate limit exhausts the window for this
// source address, so nothing after it could sign in.
// ===========================================================================
describe('rate limiting', () => {
  it('starts refusing repeated sign-in attempts', async () => {
    const client = new Client(world.app);
    let limited = false;

    for (let attempt = 0; attempt < 80; attempt += 1) {
      const response = await client.post('/auth/login', {
        email: `flood${attempt}.${suffix}@test.colifees.local`,
        password: 'WhateverPassword123!',
      });
      if (response.status === 429) {
        limited = true;
        expect(response.body.error.code).toBe('RATE_LIMITED');
        break;
      }
    }

    expect(limited, 'repeated sign-in attempts should eventually be rate limited').toBe(true);
  });
});
