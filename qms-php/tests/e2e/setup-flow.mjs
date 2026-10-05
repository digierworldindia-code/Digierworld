/*
 * Browser check of the one-time setup page on a fresh upload.
 *
 *   QMS_URL=http://127.0.0.1:8096 QMS_DB_NAME=... QMS_DB_USER=... QMS_DB_PASS=... QMS_ADMIN_PASSWORD=... \
 *   QMS_SETUP_KEY=$(grep -o '[a-f0-9]\{20\}' /path/to/qms/storage/setup-key.php) node setup-flow.mjs
 *
 * Needs an EMPTY database. Creates the administrator "admin" and loads the demo data.
 * The setup key file is created on the first visit of the site: open the site once
 * (or run this script once with a wrong key) before reading the key.
 */
import { chromium } from 'playwright';

const BASE = (process.env.QMS_URL || 'http://127.0.0.1:8096').replace(/\/$/, '');
const env = (name, fallback = '') => process.env[name] || fallback;
const ADMIN_PW = env('QMS_ADMIN_PASSWORD');
if (!ADMIN_PW) {
  console.error('Set QMS_ADMIN_PASSWORD.');
  process.exit(2);
}
let failures = 0;
const check = (ok, label) => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`);
  if (!ok) failures += 1;
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox'] });
const context = await browser.newContext({ viewport: { width: 1200, height: 900 }, ignoreHTTPSErrors: process.env.QMS_INSECURE === '1' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

await page.goto(`${BASE}/`);
check(page.url().endsWith('/install.php') && (await page.content()).includes('Server check'), 'a fresh upload opens the setup page');

const fill = async (key) => {
  await page.fill('#setup_key', key);
  await page.fill('#db_host', env('QMS_DB_HOST', 'localhost'));
  await page.fill('#db_port', env('QMS_DB_PORT', '3306'));
  await page.fill('#db_name', env('QMS_DB_NAME'));
  await page.fill('#db_user', env('QMS_DB_USER'));
  await page.fill('#db_pass', env('QMS_DB_PASS'));
  await page.fill('#base_url', `${BASE}/`);
  await page.fill('#company_name', 'Setup Test Industries');
  await page.selectOption('#timezone', 'Asia/Kolkata');
  await page.check('#demo');
  await page.fill('#admin_username', 'admin');
  await page.fill('#admin_password', ADMIN_PW);
  await page.fill('#admin_password_confirm', ADMIN_PW);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
};

await fill('wrong-key-0000000000');
check((await page.content()).includes('does not match'), 'a wrong setup key is refused');

if (!env('QMS_SETUP_KEY')) {
  console.log('Set QMS_SETUP_KEY (from storage/setup-key.php) and run again to finish the installation.');
  await browser.close();
  process.exit(failures === 0 ? 0 : 1);
}
await fill(env('QMS_SETUP_KEY'));
check((await page.content()).includes('Setup complete'), 'installation completes');

await Promise.all([page.waitForNavigation(), page.click('a.btn-primary')]);
check(page.url().endsWith('/login.php'), 'the sign-in link opens the login page');
check((await page.content()).includes('Setup Test Industries'), 'company name from the setup is shown');
await page.fill('#username', 'admin');
await page.fill('#password', ADMIN_PW);
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
check(page.url().endsWith('/dashboard.php'), 'administrator signs in and lands on the dashboard');

const templates = await page.goto(`${BASE}/templates.php`);
check(templates.status() === 200 && (await page.content()).includes('PUBLISHED'), 'demo templates are published');

const again = await (await browser.newContext({ ignoreHTTPSErrors: process.env.QMS_INSECURE === '1' })).newPage();
const setup = await again.goto(`${BASE}/install.php`);
check(setup.status() === 404, 'the setup page is switched off after installation');

check(errors.length === 0, `no browser errors ${errors.join(' | ')}`);
await browser.close();
console.log(failures === 0 ? '\nSetup checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
