/*
 * End-to-end check of a running QMS in a real browser (Chromium via Playwright).
 *
 *   QMS_URL=https://qms.example.com QMS_PASSWORD='...' node full-flow.mjs
 *   (add QMS_INSECURE=1 for a test server with a self-signed certificate)
 *
 * Needs four test users (operator, production engineer, quality engineer, QA admin)
 * with the same password, demo master data and published demo templates:
 *   QMS_OPERATOR=op1 QMS_PE=pe1 QMS_QE=qe1 QMS_QA=qa1 (defaults).
 * Run it against a test or staging system only: it creates and approves reports.
 */
import { chromium } from 'playwright';

const BASE = (process.env.QMS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const PW = process.env.QMS_PASSWORD || '';
if (!PW) {
  console.error('Set QMS_PASSWORD to the password of the test users.');
  process.exit(2);
}
const USERS = { op: process.env.QMS_OPERATOR || 'op1', pe: process.env.QMS_PE || 'pe1', qe: process.env.QMS_QE || 'qe1', qa: process.env.QMS_QA || 'qa1' };
const executablePath = process.env.CHROMIUM_PATH || undefined;
const INSECURE = process.env.QMS_INSECURE === '1'; // self-signed test certificates only
let failures = 0;

const check = (ok, label) => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`);
  if (!ok) failures += 1;
};

async function session(user) {
  const browser = await chromium.launch({ executablePath, args: ['--no-sandbox'] });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: INSECURE })).newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('404')) errors.push(m.text()); });
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/login`);
  await page.fill('#username', user);
  await page.fill('#password', PW);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return { browser, page, errors };
}

async function fillAll(page) {
  await page.evaluate(() => {
    const date = document.getElementById('inspectionForm').dataset.inspectionDate;
    const fire = (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
    for (const c of document.querySelectorAll('[data-obs]')) {
      for (let n = 1; n <= Number(c.dataset.count); n += 1) {
        const inputs = [...c.querySelectorAll(`[data-reading="${n}"]`)];
        if (!inputs.length || inputs[0].disabled) continue;
        const first = inputs[0];
        if (first.type === 'radio') {
          const ok = inputs.find((i) => i.value === 'OK' || i.value === 'GO');
          if (!inputs.some((i) => i.checked)) { ok.checked = true; fire(ok); }
        } else if (first.tagName === 'SELECT') {
          if (!first.value) { first.value = [...first.options].find((o) => ['OK', 'GO'].includes(o.value)).value; fire(first); }
        } else if (!first.value) {
          const { type, lsl, usl, decimals } = c.dataset;
          let v = 'OK';
          if (type === 'NUMERIC' || type === 'PERCENTAGE') {
            const lo = lsl ? Number(lsl) : null; const hi = usl ? Number(usl) : null;
            v = (lo !== null && hi !== null ? (lo + hi) / 2 : (lo ?? (hi ?? 5))).toFixed(Number(decimals));
          } else if (type === 'DATE') { const d = new Date(`${date}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + 30); v = d.toISOString().slice(0, 10); }
          else if (type === 'TIME') v = '10:30';
          first.value = v; fire(first);
        }
      }
      const g = c.querySelector('[data-gauge]');
      if (g && !g.disabled && !g.value) {
        const opt = [...g.options].find((o) => o.value && o.dataset.usable !== '0');
        if (opt) { g.value = opt.value; fire(g); }
      }
    }
  });
}

async function sign(user, reportId, action, remarks = '') {
  const s = await session(user);
  await s.page.goto(`${BASE}/inspections/${reportId}`);
  await s.page.click(`.qms-sign-card button[data-sign-action="${action}"]`);
  await s.page.waitForSelector('#signatureModal.show');
  if (remarks) await s.page.fill('#signRemarks', remarks);
  if (await s.page.locator('#signPassword').isVisible()) await s.page.fill('#signPassword', PW);
  await Promise.all([s.page.waitForNavigation(), s.page.click('#signatureModal button[type=submit]')]);
  const message = (await s.page.locator('.alert').first().innerText()).trim();
  check(s.errors.length === 0, `${user}: no browser errors`);
  await s.browser.close();
  return message;
}

// 1. Operator: Setup Change Approval, filled on the tablet layout, submitted.
let s = await session(USERS.op);
check(s.page.url().includes('/inspections/home'), 'operator lands on the inspection home page');
await s.page.goto(`${BASE}/inspections/new?type=1`);
await s.page.selectOption('#part_id', { index: 1 });
await s.page.waitForTimeout(800);
await s.page.selectOption('#machine_id', { index: 1 });
await Promise.all([s.page.waitForNavigation(), s.page.click('button[type=submit][data-once]')]);
const sca = s.page.url().match(/inspections\/(\d+)/)[1];
await s.page.click('[data-now-for="h-finish_at"]');
await fillAll(s.page);
await s.page.waitForTimeout(2500);
check((await s.page.locator('[data-save-state]').innerText()).includes('saved'), 'autosave stored all values');
await s.page.click('[data-goto="5"]');
await Promise.all([s.page.waitForURL(new RegExp(`/inspections/${sca}$`)), s.page.click('[data-submit]')]);
check(/Report SCA-\d{4}-\d{2}-\d+ submitted/.test(await s.page.locator('.alert').first().innerText()), 'SCA submitted with a report number');
check(s.errors.length === 0, 'operator: no browser errors');
await s.browser.close();

// 2. Three approval stages with password re-entry.
check((await sign(USERS.pe, sca, 'approve')).includes('Quality Verification'), 'production verification');
check((await sign(USERS.qe, sca, 'approve')).includes('QA Approval'), 'quality verification');
check((await sign(USERS.qa, sca, 'approve', 'Approved in E2E run')).includes('approved'), 'QA approval');

// 3. Printing and PDF.
s = await session(USERS.qa);
const pdf = await s.page.request.get(`${BASE}/inspections/${sca}/pdf`);
check(pdf.status() === 200 && pdf.headers()['content-type'] === 'application/pdf', 'A4 PDF generated');
const print = await s.page.goto(`${BASE}/inspections/${sca}/print`);
check(print.status() === 200 && (await s.page.content()).includes('SETUP CHANGE APPROVAL'), 'print view renders');

// 4. Management pages.
for (const path of ['/dashboard', '/reports', '/reports/daily', '/reports/oos', '/reports/gauge-due', '/approvals', '/inspections']) {
  const r = await s.page.goto(`${BASE}${path}`);
  check(r.status() === 200, `GET ${path}`);
}
const csv = await s.page.request.get(`${BASE}/reports/daily/export`);
check(csv.status() === 200 && csv.headers()['content-type'].startsWith('text/csv'), 'CSV export');
check(s.errors.length === 0, 'QA: no browser errors');
await s.browser.close();

console.log(failures === 0 ? '\nAll end-to-end checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
