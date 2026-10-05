/*
 * End-to-end check of a running QMS in a real browser (Chromium via Playwright).
 * Run it against a TEST installation with demo data (it creates users and reports):
 *
 *   QMS_URL=http://127.0.0.1:8096 QMS_ADMIN_PASSWORD='...' QMS_PASSWORD='...' node full-flow.mjs
 *
 * QMS_PASSWORD becomes the password of the test users op1, pe1, qe1, qa1 (created
 * or reset by the script through the Users page). QMS_INSECURE=1 accepts a
 * self-signed certificate; QMS_SHOTS=/some/folder saves screenshots and the PDF.
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = (process.env.QMS_URL || 'http://127.0.0.1:8096').replace(/\/$/, '');
const ADMIN_PW = process.env.QMS_ADMIN_PASSWORD || '';
const PW = process.env.QMS_PASSWORD || '';
if (!ADMIN_PW || !PW) {
  console.error('Set QMS_ADMIN_PASSWORD (administrator) and QMS_PASSWORD (for the test users).');
  process.exit(2);
}
const SHOTS = process.env.QMS_SHOTS || '';
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const executablePath = process.env.CHROMIUM_PATH || undefined;
const INSECURE = process.env.QMS_INSECURE === '1';
const USERS = {
  op1: { role: 'Operator', employee: 'E1001 · Demo Operator' },
  pe1: { role: 'Production Engineer', employee: 'E3001 · Demo Production Engineer' },
  qe1: { role: 'Quality Engineer', employee: 'E2001 · Demo Quality Engineer' },
  qa1: { role: 'QA Admin', employee: 'E4001 · Demo QA Head' },
};
let failures = 0;
const check = (ok, label) => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`);
  if (!ok) failures += 1;
};
const browser = await chromium.launch({ executablePath, args: ['--no-sandbox'] });

async function session(user, password, viewport = { width: 1280, height: 900 }) {
  const context = await browser.newContext({ viewport, ignoreHTTPSErrors: INSECURE });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/login.php`);
  await page.fill('#username', user);
  await page.fill('#password', password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return { context, page, errors, close: () => context.close() };
}

const flash = async (page) => (await page.locator('.alert').first().innerText()).trim();
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }); };

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

// Presses Save and waits for "All changes saved" ("Unsaved changes" and the
// partial "Saved – some values need correction" do not count).
async function waitSaved(page) {
  await page.click('[data-save-now]');
  const allSaved = () => /^All changes saved/.test(document.querySelector('[data-save-state]')?.textContent || '');
  await page.waitForFunction(allSaved, null, { timeout: 15000 }).catch(() => {});
  return page.evaluate(allSaved);
}

async function signAs(user, reportId, action, remarks = '', round = null) {
  const s = await session(user, PW);
  await s.page.goto(`${BASE}/inspection.php?id=${reportId}`);
  const selector = round === null ? `.qms-sign-card button[data-sign-action="${action}"]` : `button[data-sign-action="${action}"][data-sign-url$="round=${round}"]`;
  await s.page.click(selector);
  await s.page.waitForSelector('#signatureModal.show');
  if (remarks) await s.page.fill('#signRemarks', remarks);
  if (await s.page.locator('#signPassword').isVisible()) await s.page.fill('#signPassword', PW);
  await Promise.all([s.page.waitForNavigation(), s.page.click('#signatureModal button[type=submit]')]);
  const message = await flash(s.page);
  check(s.errors.length === 0, `${user}: no browser errors ${s.errors.join(' | ')}`);
  await s.close();
  return message;
}

// 1. The administrator creates (or resets) the four test users; each sets QMS_PASSWORD at first login.
let admin = await session('admin', ADMIN_PW);
check(admin.page.url().endsWith('/dashboard.php'), 'administrator lands on the dashboard');
for (const [username, info] of Object.entries(USERS)) {
  await admin.page.goto(`${BASE}/admin/users.php?q=${username}`);
  const existing = admin.page.locator('table a', { hasText: 'Edit' }).first();
  if (await existing.count()) {
    await Promise.all([admin.page.waitForNavigation(), existing.click()]);
    await Promise.all([admin.page.waitForNavigation(), admin.page.click('button:has-text("Reset password")')]);
  } else {
    await admin.page.goto(`${BASE}/admin/user_edit.php`);
    await admin.page.fill('#username', username);
    await admin.page.selectOption('#role_id', { label: info.role });
    await admin.page.selectOption('#employee_id', { label: info.employee });
    await Promise.all([admin.page.waitForNavigation(), admin.page.click('form button[type=submit][data-once]')]);
  }
  const otp = (await flash(admin.page)).match(/shown only now\): (\S+)/)?.[1];
  check(Boolean(otp), `${username}: one-time password issued`);
  const u = await session(username, otp);
  check(u.page.url().endsWith('/account.php'), `${username}: must change the one-time password`);
  await u.page.fill('#current_password', otp);
  await u.page.fill('#new_password', PW);
  await u.page.fill('#confirm_password', PW);
  await Promise.all([u.page.waitForNavigation(), u.page.click('form button[type=submit]')]);
  check(!u.page.url().endsWith('/account.php'), `${username}: password changed`);
  await u.close();
}

// 2. Operator: Setup Change Approval on a tablet, autosaved and submitted.
let s = await session('op1', PW, { width: 1024, height: 1366 });
check(s.page.url().endsWith('/home.php'), 'operator lands on the inspection home page');
await s.page.goto(`${BASE}/inspection_new.php?type=1`);
await s.page.selectOption('#part_id', { index: 1 });
await s.page.waitForFunction(() => document.querySelector('#machine_id').options.length > 1 && !/Loading/.test(document.querySelector('#machineHint').textContent));
await s.page.selectOption('#machine_id', { index: 1 });
await Promise.all([s.page.waitForNavigation(), s.page.click('button[type=submit][data-once]')]);
const sca = new URL(s.page.url()).searchParams.get('id');
check(s.page.url().includes('/inspection_edit.php') && Boolean(sca), 'SCA draft opened for entry');
await s.page.click('[data-now-for="h-finish_at"]');
await fillAll(s.page);
check(await waitSaved(s.page), 'autosave stored all values');
await s.page.click('[data-steps] button:last-child');
await shot(s.page, '1-sca-review');
await Promise.all([s.page.waitForURL(/inspection\.php\?id=/), s.page.click('[data-submit]')]);
check(/Report SCA-\d{4}-\d{2}-\d{6} submitted/.test(await flash(s.page)), 'SCA submitted with a report number');
check(s.errors.length === 0, `operator: no browser errors ${s.errors.join(' | ')}`);
await s.close();

// 3. Three approval stages with password re-entry.
check((await signAs('pe1', sca, 'approve')).includes('Quality Verification'), 'production verification');
check((await signAs('qe1', sca, 'approve')).includes('QA Approval'), 'quality verification');
check((await signAs('qa1', sca, 'approve', 'Approved in E2E run')).includes('approved'), 'QA approval');

// 4. In-Process sheet: operator adds and signs an inspection time, QE verifies, operator submits.
s = await session('op1', PW, { width: 1024, height: 1366 });
await s.page.goto(`${BASE}/inspection_new.php?type=3`);
await s.page.selectOption('#part_id', { index: 1 });
await s.page.waitForFunction(() => [...document.querySelector('#machine_id').options].some((o) => o.text.startsWith('CNC-02')));
await s.page.selectOption('#machine_id', { label: [...(await s.page.locator('#machine_id option').allInnerTexts())].find((t) => t.startsWith('CNC-02')) });
await Promise.all([s.page.waitForNavigation(), s.page.click('button[type=submit][data-once]')]);
const ipr = new URL(s.page.url()).searchParams.get('id');
const onSheet = s.page.url().includes('/inspection_edit.php');
check(onSheet, 'In-Process day sheet opened');
let round = null;
if (onSheet) {
  await Promise.all([s.page.waitForNavigation(), s.page.locator('thead form[data-flush-save] button[type=submit]').first().click()]);
  round = (s.page.url().match(/#round-(\d+)/) || [])[1] ?? null;
  check(Boolean(round), 'inspection time added');
  await fillAll(s.page);
  check(await waitSaved(s.page), 'grid values saved');
  await shot(s.page, '2-ipr-grid');
  await Promise.all([s.page.waitForNavigation(), s.page.locator('tbody.round-foot form[data-flush-save] button[type=submit]').first().click()]);
  check((await flash(s.page)).includes('signed by the operator'), 'operator signed the inspection');
}
await s.close();
if (round) {
  check((await signAs('qe1', ipr, 'verify', '', round)).includes('verified'), 'quality engineer verified the inspection');
  s = await session('op1', PW);
  await s.page.goto(`${BASE}/inspection_edit.php?id=${ipr}`);
  await Promise.all([s.page.waitForURL(/inspection\.php\?id=/), s.page.click('[data-submit]')]);
  check(/IPR-\d{4}-\d{2}-\d{6} submitted/.test(await flash(s.page)), 'In-Process sheet submitted');
  check(s.errors.length === 0, `operator (sheet): no browser errors ${s.errors.join(' | ')}`);
  await s.close();
}

// 5. Printout and "Save as PDF" (Chromium's print-to-PDF of the same page).
s = await session('qa1', PW);
const print = await s.page.goto(`${BASE}/inspection_print.php?id=${sca}`);
check(print.status() === 200 && (await s.page.content()).includes('SETUP CHANGE APPROVAL'), 'print view renders');
await s.page.emulateMedia({ media: 'print' });
const pdf = await s.page.pdf({ format: 'A4', printBackground: true });
check(pdf.length > 20000 && pdf.subarray(0, 5).toString() === '%PDF-', `A4 PDF produced (${Math.round(pdf.length / 1024)} KB)`);
if (SHOTS) writeFileSync(`${SHOTS}/report-${sca}.pdf`, pdf);
await s.page.emulateMedia({ media: 'screen' });

// 6. Management pages and CSV.
for (const path of ['/dashboard.php', '/reports.php', '/report.php?name=daily', '/report.php?name=monthly', '/report.php?name=oos', '/report.php?name=gauge-due',
  '/approvals.php', '/inspections.php', `/inspection.php?id=${sca}`, '/templates.php', '/gauges.php', '/masters.php', '/masters.php?type=parts']) {
  const r = await s.page.goto(`${BASE}${path}`);
  check(r.status() === 200, `GET ${path}`);
}
await shot(s.page, '3-masters');
const csv = await s.page.request.get(`${BASE}/report.php?name=daily&export=csv`);
check(csv.status() === 200 && csv.headers()['content-type'].startsWith('text/csv'), 'CSV export');
check(s.errors.length === 0, `QA: no browser errors ${s.errors.join(' | ')}`);
await s.close();

// 7. Administration pages (CSP violations would show up as browser errors).
for (const path of ['/admin/users.php', '/admin/roles.php', '/admin/role_edit.php?id=5', '/admin/settings.php', '/admin/audit.php', '/admin/login_history.php', '/admin/sync.php']) {
  const r = await admin.page.goto(`${BASE}${path}`);
  check(r.status() === 200, `GET ${path}`);
}
await admin.page.goto(`${BASE}/dashboard.php`);
await shot(admin.page, '4-dashboard');
check(admin.errors.length === 0, `administrator: no browser errors ${admin.errors.join(' | ')}`);
await admin.close();

// 8. Access control: the operator cannot open administration or other people's reports.
s = await session('op1', PW);
check((await s.page.goto(`${BASE}/admin/users.php`)).status() === 403, 'operator gets 403 on Users');
check((await s.page.goto(`${BASE}/templates.php`)).status() === 403, 'operator gets 403 on Templates');
await s.close();

await browser.close();
console.log(failures === 0 ? '\nAll end-to-end checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
