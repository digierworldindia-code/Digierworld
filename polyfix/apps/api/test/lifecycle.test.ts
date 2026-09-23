/**
 * The complete mattress lifecycle, end to end, against a real database.
 *
 *   manufacture → serial + QR → dispatch → dealer receives → inventory →
 *   sale → warranty → claim → photos → risk indicators → review → approval →
 *   replacement → audit trail
 *
 * This is the acceptance test for the system as a whole. If it passes, the
 * business flow works; if it fails, something in the chain is broken regardless
 * of how the screens look.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import { seedWorld, Client, multipartBody, validPngBytes, type TestWorld } from './harness.js';

let world: TestWorld;
let admin: Client;
let warehouse: Client;
let dealer: Client;

const suffix = `lc${Date.now().toString(36)}`;

beforeAll(async () => {
  world = await seedWorld(suffix);
  admin = new Client(world.app);
  warehouse = new Client(world.app);
  dealer = new Client(world.app);

  expect((await admin.login(world.admin.email)).status).toBe(200);
  expect((await warehouse.login(world.warehouse.email)).status).toBe(200);
  expect((await dealer.login(world.dealerA.userEmail)).status).toBe(200);
});

describe('mattress lifecycle', () => {
  const state: {
    batchId?: string;
    serials: string[];
    dispatchId?: string;
    claimId?: string;
    claimNumber?: string;
    soldSerial?: string;
    replacementSerial?: string;
    qrToken?: string;
  } = { serials: [] };

  it('1. creates a manufacturing batch', async () => {
    const response = await warehouse.post('/ops/batches', {
      warehouseId: world.warehouseId,
      manufacturedOn: new Date().toISOString().slice(0, 10),
      plannedQuantity: 10,
      lineSupervisor: 'Test Supervisor',
    });

    expect(response.status).toBe(201);
    expect(response.body.batchCode).toMatch(/^BAT\d{6,}$/);
    state.batchId = response.body.id;
  });

  it('2. produces units with database-issued serial numbers', async () => {
    const response = await warehouse.post('/ops/mattresses/produce', {
      batchId: state.batchId,
      items: [{ productVariantId: world.productVariantId, quantity: 4 }],
    });

    expect(response.status).toBe(201);
    expect(response.body.produced).toBe(4);
    expect(response.body.serialNumbers).toHaveLength(4);
    for (const serial of response.body.serialNumbers) {
      expect(serial).toMatch(/^CLF\d{8}$/);
    }
    // Serials are unique and issued by the database, not the client.
    expect(new Set(response.body.serialNumbers).size).toBe(4);
    state.serials = response.body.serialNumbers;
  });

  it('3. refuses a client-supplied serial number', async () => {
    const response = await warehouse.post('/ops/mattresses/produce', {
      batchId: state.batchId,
      items: [{ productVariantId: world.productVariantId, quantity: 1 }],
      // A caller trying to choose its own identity is simply ignored by the
      // schema, which strips unknown keys.
      serialNumber: 'CLF99999999',
    });
    expect(response.status).toBe(201);
    expect(response.body.serialNumbers[0]).not.toBe('CLF99999999');
  });

  it('4. issues a QR label that carries an opaque token, not the serial', async () => {
    const list = await warehouse.get(`/ops/mattresses?search=${state.serials[0]}`);
    expect(list.status).toBe(200);
    const mattressId = list.body.items[0].id;

    const qr = await warehouse.get(`/ops/mattresses/${mattressId}/qr?format=json`);
    expect(qr.status).toBe(200);
    expect(qr.body.verifyUrl).toContain('/warranty/verify?q=');
    // The printed label must not leak the sequential serial.
    expect(qr.body.verifyUrl).not.toContain(state.serials[0]);

    state.qrToken = new URL(qr.body.verifyUrl).searchParams.get('q') ?? undefined;
    expect(state.qrToken).toBeTruthy();

    const svg = await warehouse.get(`/ops/mattresses/${mattressId}/qr?format=svg`);
    expect(svg.status).toBe(200);
    expect(svg.headers['content-type']).toContain('image/svg+xml');
  });

  it('5. creates and sends a dispatch to the dealer', async () => {
    const created = await warehouse.post('/ops/dispatches', {
      warehouseId: world.warehouseId,
      dealerId: world.dealerA.id,
      serialNumbers: state.serials.slice(0, 3),
      transporter: 'Test Logistics',
      lrNumber: 'LR-TEST-001',
    });

    expect(created.status).toBe(201);
    expect(created.body.itemCount).toBe(3);
    state.dispatchId = created.body.id;

    const sent = await warehouse.post(`/ops/dispatches/${state.dispatchId}/send`, {});
    expect(sent.status).toBe(200);
    expect(sent.body.itemCount).toBe(3);
  });

  it('6. will not dispatch a unit that is already in transit', async () => {
    const response = await warehouse.post('/ops/dispatches', {
      warehouseId: world.warehouseId,
      dealerId: world.dealerA.id,
      serialNumbers: [state.serials[0]!],
    });
    expect(response.status).toBe(422);
    expect(response.body.error.message).toMatch(/not available to dispatch/i);
  });

  it('7. shows the consignment to the receiving dealer', async () => {
    const response = await dealer.get('/dealer/incoming');
    expect(response.status).toBe(200);
    expect(response.body.items.length).toBeGreaterThan(0);
    const consignment = response.body.items.find((d: any) => d.id === state.dispatchId);
    expect(consignment).toBeDefined();
    expect(consignment.itemCount).toBe(3);
  });

  it('8. records receipt, including a damaged unit', async () => {
    const response = await dealer.post('/dealer/receive', {
      dispatchId: state.dispatchId,
      items: [
        { serialNumber: state.serials[0], condition: 'OK' },
        { serialNumber: state.serials[1], condition: 'OK' },
        { serialNumber: state.serials[2], condition: 'DAMAGED', remarks: 'Corner crushed in transit' },
      ],
      remarks: 'Received at test showroom',
    });

    expect(response.status).toBe(200);
    expect(response.body.received).toBe(3);
    expect(response.body.damaged).toBe(1);
    expect(response.body.dispatchStatus).toBe('RECEIVED');
  });

  it('9. scanning a received unit offers the sell action', async () => {
    const response = await dealer.post('/dealer/scan', { serialNumber: state.serials[0] });
    expect(response.status).toBe(200);
    expect(response.body.mattress.status).toBe('DEALER_RECEIVED');
    expect(response.body.actions).toContain('SELL');
    expect(response.body.warranty).toBeNull();
  });

  it('10. records a sale and activates the warranty automatically', async () => {
    const response = await dealer.post('/dealer/sales', {
      serialNumber: state.serials[0],
      invoiceNumber: `INV-${suffix}-001`,
      soldAt: new Date().toISOString().slice(0, 10),
      salePrice: 18900,
      paymentMode: 'UPI',
      customer: {
        fullName: 'Test Customer One',
        phone: '9811100001',
        city: 'Jaipur',
        state: 'Rajasthan',
        pincode: '302001',
        addressLine: '12 Test Colony',
      },
    });

    expect(response.status).toBe(200);
    expect(response.body.warrantyYears).toBeGreaterThan(0);
    expect(new Date(response.body.warrantyEnd).getTime()).toBeGreaterThan(Date.now());
    state.soldSerial = response.body.serialNumber;
  });

  it('11. refuses to sell the same unit twice', async () => {
    const response = await dealer.post('/dealer/sales', {
      serialNumber: state.serials[0],
      invoiceNumber: `INV-${suffix}-002`,
      soldAt: new Date().toISOString().slice(0, 10),
      salePrice: 18900,
      paymentMode: 'CASH',
      customer: { fullName: 'Someone Else', phone: '9811100002' },
    });
    expect(response.status).toBe(422);
    expect(response.body.error.message).toMatch(/already been sold/i);
  });

  it('12. refuses a duplicate invoice number for the same dealer', async () => {
    const response = await dealer.post('/dealer/sales', {
      serialNumber: state.serials[1],
      invoiceNumber: `INV-${suffix}-001`,
      soldAt: new Date().toISOString().slice(0, 10),
      salePrice: 18900,
      paymentMode: 'CASH',
      customer: { fullName: 'Test Customer Two', phone: '9811100003' },
    });
    expect(response.status).toBe(409);
  });

  it('13. public verification shows warranty status and no private data', async () => {
    const anonymous = new Client(world.app);
    const response = await anonymous.post('/public/verify', { serialNumber: state.soldSerial });

    expect(response.status).toBe(200);
    expect(response.body.genuine).toBe(true);
    expect(response.body.warranty.status).toBe('ACTIVE');
    expect(response.body.warranty.daysRemaining).toBeGreaterThan(0);

    // Nothing about the customer, the dealer or the money may appear.
    const serialised = JSON.stringify(response.body).toLowerCase();
    expect(serialised).not.toContain('test customer one');
    expect(serialised).not.toContain('9811100001');
    expect(serialised).not.toContain('18900');
    expect(serialised).not.toContain(world.dealerA.code.toLowerCase());
    expect(serialised).not.toContain('invoice');
    expect(serialised).not.toContain('risk');
  });

  it('14. public verification by QR token works and reveals no more', async () => {
    const anonymous = new Client(world.app);
    const response = await anonymous.post('/public/verify', { qrToken: state.qrToken });
    expect(response.status).toBe(200);
    expect(response.body.genuine).toBe(true);
    expect(JSON.stringify(response.body).toLowerCase()).not.toContain('customer');
  });

  it('15. dealer raises a warranty claim on the sold unit', async () => {
    const response = await dealer.post('/dealer/claims', {
      serialNumber: state.soldSerial,
      issueCategory: 'SAGGING',
      reportedIssue: 'Visible dip on the left sleeping side',
      description: 'Customer reports a dip of roughly 40mm on the left side after ten weeks of normal use.',
    });

    expect(response.status).toBe(200);
    expect(response.body.claimNumber).toMatch(/^CLM\d{8}$/);
    state.claimId = response.body.id;
    state.claimNumber = response.body.claimNumber;

    // The dealer is never shown the risk assessment.
    expect(JSON.stringify(response.body).toLowerCase()).not.toContain('risk');
  });

  it('16. refuses a second open claim on the same unit', async () => {
    const response = await dealer.post('/dealer/claims', {
      serialNumber: state.soldSerial,
      issueCategory: 'FABRIC_TEAR',
      reportedIssue: 'Another issue entirely',
      description: 'A second attempt to open a claim while the first is still open.',
    });
    expect(response.status).toBe(409);
  });

  it('17. accepts genuine photographs and rejects a disguised file', async () => {
    const good = multipartBody([
      { field: 'file', filename: 'damage.png', contentType: 'image/png', content: validPngBytes(0) },
      { field: 'file', filename: 'label.png', contentType: 'image/png', content: validPngBytes(7) },
    ]);
    const upload = await dealer.post(`/dealer/claims/${state.claimId}/media`, undefined, {
      payload: good.payload,
      headers: good.headers,
    });

    expect(upload.status).toBe(200);
    expect(upload.body.uploaded).toHaveLength(2);
    // The response hands back a signed, expiring reference — never a storage key.
    expect(upload.body.uploaded[0].url).toMatch(/^\/media\/[0-9a-f-]+\?exp=\d+&sig=/);
    expect(JSON.stringify(upload.body)).not.toContain('claims/');

    const disguised = multipartBody([
      {
        field: 'file',
        filename: 'payload.png',
        contentType: 'image/png',
        content: Buffer.from('MZ\x90\x00\x03 this is an executable pretending to be a png'),
      },
    ]);
    const rejected = await dealer.post(`/dealer/claims/${state.claimId}/media`, undefined, {
      payload: disguised.payload,
      headers: disguised.headers,
    });

    expect(rejected.status).toBe(200);
    expect(rejected.body.uploaded).toHaveLength(0);
    expect(rejected.body.rejected[0].reason).toMatch(/not a JPEG, PNG, WebP or PDF/i);
  });

  it('18. shows the claim to the warranty team with risk indicators', async () => {
    const response = await admin.get(`/ops/claims/${state.claimId}`);
    expect(response.status).toBe(200);
    expect(response.body.claim.claimNumber).toBe(state.claimNumber);
    expect(response.body.risk).toBeDefined();
    expect(['LOW', 'MEDIUM', 'HIGH']).toContain(response.body.risk.level);
    // An early claim is flagged, since the sale was recorded moments ago.
    const codes = response.body.risk.signals.map((s: any) => s.code);
    expect(codes).toContain('EARLY_CLAIM');

    // But a same-day receive-and-sell is ordinary trading, not an impossible
    // timeline. The sale carries a date (midnight) while the receipt carries a
    // timestamp, so a naive comparison flags every one of them — which would
    // make the indicator fire on normal business and be worth nothing.
    expect(codes).not.toContain('TIMELINE_INCONSISTENT');

    expect(response.body.risk.disclaimer).toMatch(/not a decision/i);
    expect(response.body.media.length).toBe(2);
  });

  it('19. records an internal note the dealer cannot see', async () => {
    const note = await admin.post(`/ops/claims/${state.claimId}/notes`, {
      note: 'Internal: checked batch records, no other reports from this production run.',
      isInternal: true,
    });
    expect(note.status).toBe(200);

    const dealerView = await dealer.get(`/dealer/claims/${state.claimId}`);
    expect(dealerView.status).toBe(200);
    // Hidden by a Row Level Security policy on claim_events, not by a filter here.
    expect(JSON.stringify(dealerView.body)).not.toContain('Internal: checked batch records');
  });

  it('20. requests more information, which the dealer does see', async () => {
    const request = await admin.post(`/ops/claims/${state.claimId}/request-information`, {
      message: 'Please send a photograph of the law label showing the serial number.',
    });
    expect(request.status).toBe(200);

    const dealerView = await dealer.get(`/dealer/claims/${state.claimId}`);
    expect(dealerView.body.claim.status).toBe('INFO_REQUESTED');
    expect(JSON.stringify(dealerView.body)).toContain('law label');

    const notifications = await dealer.get('/dealer/notifications');
    expect(notifications.body.notifications.some((n: any) => n.type === 'CLAIM_INFO_REQUESTED')).toBe(true);
  });

  it('21. requires a stated reason for any decision', async () => {
    const response = await admin.post(`/ops/claims/${state.claimId}/decision`, {
      decision: 'APPROVED',
      decisionReason: 'ok',
    });
    expect(response.status).toBe(422);
    expect(response.body.error.details.decisionReason).toBeDefined();
  });

  it('22. approves the claim with a recorded reason', async () => {
    const response = await admin.post(`/ops/claims/${state.claimId}/decision`, {
      decision: 'APPROVED',
      decisionReason: 'Photographs confirm a manufacturing defect in the comfort layer; within warranty term.',
      resolution: 'Replacement approved',
    });
    expect(response.status).toBe(200);
    expect(response.body.status).toBe('APPROVED');
  });

  it('23. issues a replacement and links it to the original', async () => {
    // The replacement comes from free stock the dealer already holds.
    const replacementSerial = state.serials[1]!;
    const response = await admin.post(`/ops/claims/${state.claimId}/replacement`, {
      replacementSerialNumber: replacementSerial,
      remarks: 'Replacement handed over at the dealer showroom.',
    });

    expect(response.status).toBe(200);
    expect(response.body.originalSerial).toBe(state.soldSerial);
    expect(response.body.replacementSerial).toBe(replacementSerial);
    state.replacementSerial = replacementSerial;
  });

  it('24. keeps the original mattress in the record, marked replaced', async () => {
    const list = await admin.get(`/ops/mattresses?search=${state.soldSerial}`);
    const mattressId = list.body.items[0].id;

    const passport = await admin.get(`/ops/mattresses/${mattressId}`);
    expect(passport.status).toBe(200);
    expect(passport.body.mattress.status).toBe('REPLACED');
    expect(passport.body.replacedBy.serialNumber).toBe(state.replacementSerial);

    // The full history survives: manufacture through to replacement.
    const events = passport.body.timeline.map((e: any) => e.eventType);
    expect(events).toEqual(
      expect.arrayContaining(['MANUFACTURED', 'ADDED_TO_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_RAISED', 'REPLACED']),
    );
  });

  it('25. gives the replacement its own lifecycle and warranty', async () => {
    const list = await admin.get(`/ops/mattresses?search=${state.replacementSerial}`);
    const mattressId = list.body.items[0].id;

    const passport = await admin.get(`/ops/mattresses/${mattressId}`);
    expect(passport.body.mattress.status).toBe('SOLD');
    expect(passport.body.mattress.isReplacement).toBe(true);
    expect(passport.body.replacementFor.serialNumber).toBe(state.soldSerial);
    expect(passport.body.replacementFor.claimNumber).toBe(state.claimNumber);
    expect(passport.body.warranty.status).toBe('ACTIVE');
  });

  it('26. public verification reflects the replacement on both units', async () => {
    const anonymous = new Client(world.app);

    const original = await anonymous.post('/public/verify', { serialNumber: state.soldSerial });
    expect(original.body.genuine).toBe(true);
    expect(original.body.warranty.status).toBe('SUPERSEDED');
    expect(original.body.note).toMatch(/replaced under warranty/i);

    const replacement = await anonymous.post('/public/verify', { serialNumber: state.replacementSerial });
    expect(replacement.body.warranty.status).toBe('ACTIVE');
  });

  it('27. writes a complete, verifiable audit trail', async () => {
    const audit = await admin.get('/ops/system/audit?pageSize=100');
    expect(audit.status).toBe(200);

    const actions = audit.body.items.map((a: any) => a.action);
    for (const expected of [
      'LOGIN',
      'BATCH_CREATED',
      'MATTRESS_PRODUCED',
      'DISPATCH_CREATED',
      'DISPATCH_SENT',
      'DISPATCH_RECEIVED',
      'SALE_RECORDED',
      'CLAIM_SUBMITTED',
      'CLAIM_MEDIA_UPLOADED',
      'CLAIM_APPROVED',
      'REPLACEMENT_ISSUED',
    ]) {
      expect(actions).toContain(expected);
    }

    // The decision entry carries the reason and the indicators shown at the time.
    const decision = audit.body.items.find((a: any) => a.action === 'CLAIM_APPROVED');
    expect(decision.reason).toMatch(/manufacturing defect/i);
    expect(decision.newValue.riskLevelAtDecision).toBeDefined();

    const verification = await admin.get('/ops/system/audit/verify');
    expect(verification.status).toBe(200);
    expect(verification.body.intact).toBe(true);
    expect(verification.body.problems).toHaveLength(0);
  });

  it('28. reports the dealer inventory correctly after the whole flow', async () => {
    const summary = await dealer.get('/dealer/inventory/summary');
    expect(summary.status).toBe(200);
    const byStatus = Object.fromEntries(summary.body.byStatus.map((s: any) => [s.status, s.count]));
    expect(byStatus.SOLD ?? 0).toBeGreaterThanOrEqual(1);
    expect(byStatus.REPLACED ?? 0).toBe(1);
  });
});
