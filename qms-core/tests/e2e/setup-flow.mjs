/*
 * Browser check of the one-time setup page on a fresh upload (shared hosting).
 *
 *   QMS_URL=http://127.0.0.1:8081 QMS_SETUP_KEY=... QMS_DB_NAME=... QMS_DB_USER=... QMS_DB_PASS=... node setup-flow.mjs
 *
 * Needs an EMPTY database. Creates the admin "admin" with QMS_ADMIN_PASSWORD.
 */
import { chromium } from 'playwright';

const BASE = (process.env.QMS_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const env = (name, fallback = '') => process.env[name] || fallback;
const ADMIN_PW = env('QMS_ADMIN_PASSWORD', 'Brass-Valve-Torque-42');
let failures = 0;
const check = (ok, label) => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`);
  if (!ok) failures += 1;
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox'] });
const page = await (await browser.newContext({ viewport: { width: 1200, height: 900 }, ignoreHTTPSErrors: process.env.QMS_INSECURE === '1' })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

await page.goto(`${BASE}/`);
check((await page.content()).includes('Server check'), 'setup page is shown on a fresh upload');

const fill = async (key) => {
  await page.fill('#setup_key', key);
  await page.fill('#db_host', env('QMS_DB_HOST', 'localhost'));
  await page.fill('#db_name', env('QMS_DB_NAME'));
  await page.fill('#db_user', env('QMS_DB_USER'));
  await page.fill('#db_pass', env('QMS_DB_PASS'));
  await page.fill('#app_url', `${BASE}/`);
  await page.fill('#company_name', 'Setup Test Industries');
  await page.selectOption('#timezone', 'Asia/Kolkata');
  await page.check('#demo');
  await page.fill('#admin_username', 'admin');
  await page.fill('#admin_name', 'Plant Administrator');
  await page.fill('#admin_password', ADMIN_PW);
  await page.fill('#admin_password_confirm', ADMIN_PW);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
};

await fill('wrong-key');
check((await page.content()).includes('does not match'), 'a wrong setup key is refused');

await fill(env('QMS_SETUP_KEY'));
check((await page.content()).includes('Setup complete'), 'installation completes');

await Promise.all([page.waitForNavigation(), page.click('a.btn-primary')]);
check(page.url().endsWith('/login'), 'sign-in link opens the login page');
check((await page.content()).includes('Setup Test Industries'), 'company name from setup is shown');
await page.fill('#username', 'admin');
await page.fill('#password', ADMIN_PW);
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
check(page.url().endsWith('/dashboard'), 'administrator signs in and lands on the dashboard');

const templates = await page.goto(`${BASE}/templates`);
check(templates.status() === 200 && (await page.content()).includes('PUBLISHED'), 'demo templates are published');

const again = await (await browser.newContext({ ignoreHTTPSErrors: process.env.QMS_INSECURE === '1' })).newPage();
await again.goto(`${BASE}/`);
check(again.url().endsWith('/login') && !(await again.content()).includes('Server check'), 'setup page is switched off after installation');

check(errors.length === 0, `no browser errors ${errors.join(' | ')}`);
await browser.close();
console.log(failures === 0 ? '\nSetup checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
