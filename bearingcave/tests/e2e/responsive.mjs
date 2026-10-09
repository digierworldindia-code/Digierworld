// TEST 12 — responsive + browser end-to-end check.
//
// Usage (dev server running, demo data seeded):
//   php spark serve &
//   node tests/e2e/responsive.mjs [baseUrl]
//
// Reads demo logins from writable/demo/credentials.txt (written by DemoSeeder),
// visits key screens at 375 / 768 / 1366 px, fails on HTTP >= 400, horizontal
// page overflow or JavaScript errors, writes screenshots to build/e2e/ and
// performs a real multipart CSV import through the supplier UI.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const OUT = path.resolve('build/e2e');
const WIDTHS = [375, 768, 1366];
fs.mkdirSync(OUT, { recursive: true });

const creds = {};
for (const line of fs.readFileSync('writable/demo/credentials.txt', 'utf8').split('\n')) {
    const m = line.match(/(\S+@example\.com)\s+(\S+)$/);
    if (m) creds[m[1]] = m[2];
}

const ROLES = {
    guest: { user: null, pages: ['/', '/marketplace', '__product__', '/suppliers', '/membership', '/how-it-works', '/services/inspection', '/trust-and-verification', '/compare', '/contact', '/login', '/register'] },
    buyer: { user: 'demo.buyer.verified@example.com', pages: ['/buyer/dashboard', '/buyer/rfqs', '/buyer/rfqs/new', '/buyer/cart', '/buyer/orders', '/buyer/verification', '/buyer/billing'] },
    supplier: { user: 'demo.supplier.verified@example.com', pages: ['/supplier/dashboard', '/supplier/products', '/supplier/products/new', '/supplier/imports', '/supplier/rfqs', '/supplier/orders', '/supplier/analytics', '/supplier/visibility'] },
    admin: { user: 'demo.superadmin@example.com', pages: ['/admin', '/admin/companies', '/admin/verifications', '/admin/products', '/admin/orders', '/admin/settings', '/admin/reports', '/admin/audit'] },
};

const results = [];
const fail = (msg) => { results.push({ ok: false, msg }); console.log('FAIL ' + msg); };
const pass = (msg) => { results.push({ ok: true, msg }); console.log('ok   ' + msg); };

async function login(page, email) {
    await page.goto(BASE + '/login');
    await page.fill('#email', email);
    await page.fill('#password', creds[email]);
    await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
    if (page.url().includes('/login')) throw new Error('login failed for ' + email);
}

const browser = await chromium.launch();
let productPath = null;

for (const [role, cfg] of Object.entries(ROLES)) {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await ctx.newPage();
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') jsErrors.push(m.text()); });
    if (cfg.user) await login(page, cfg.user);

    for (let p of cfg.pages) {
        if (p === '__product__') {
            await page.goto(BASE + '/marketplace');
            const href = await page.locator('a[href*="/product/"]').first().getAttribute('href');
            p = productPath = new URL(href, BASE).pathname;
        }
        for (const w of WIDTHS) {
            await page.setViewportSize({ width: w, height: w < 800 ? 812 : 900 });
            jsErrors.length = 0;
            const resp = await page.goto(BASE + p, { waitUntil: 'networkidle' });
            const status = resp ? resp.status() : 0;
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            const label = `${role} ${p} @${w}px`;
            const shot = `${role}${p.replace(/[^a-z0-9]+/gi, '_')}_${w}.png`.replace(/_+/g, '_');
            await page.screenshot({ path: path.join(OUT, shot), fullPage: false });
            if (status >= 400) fail(`${label}: HTTP ${status}`);
            else if (overflow > 1) fail(`${label}: horizontal overflow ${overflow}px`);
            else if (jsErrors.length) fail(`${label}: JS error ${jsErrors[0]}`);
            else pass(label);
        }
    }

    // Mobile navigation: the collapsed menu must open at 375px.
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(BASE + (cfg.pages[0] === '__product__' ? '/' : cfg.pages[0]));
    const toggler = page.locator('.navbar-toggler:visible, [data-sidebar-toggle]:visible').first();
    if (await toggler.count()) {
        await toggler.click();
        await page.waitForTimeout(400);
        const visibleLinks = await page.locator('.navbar-collapse.show a:visible, .app-sidebar.show a:visible, .offcanvas.show a:visible').count();
        visibleLinks > 0 ? pass(`${role} mobile menu opens (${visibleLinks} links)`) : fail(`${role} mobile menu did not reveal links`);
        await page.screenshot({ path: path.join(OUT, `${role}_mobile_menu_375.png`) });
    } else {
        fail(`${role} no mobile menu toggler at 375px`);
    }

    if (role === 'supplier') {
        // Real multipart upload through the UI: upload → auto-map → validate → confirm.
        await page.setViewportSize({ width: 1366, height: 900 });
        const stamp = Date.now().toString(36).toUpperCase();
        const csv = 'sku,name,part_number,category,item_condition,quantity,received_on,lot_quantity,lot_price,currency\n'
            + `E2E-${stamp}-1,[SAMPLE] E2E tapered bearing,30205,Tapered Roller Bearings,new,120,2025-03-01,120,9600,INR\n`
            + `E2E-${stamp}-2,[SAMPLE] E2E broken row,,No Such Category,new,abc,2099-01-01,,,INR\n`;
        const file = path.join(OUT, 'e2e-import.csv');
        fs.writeFileSync(file, csv);
        await page.goto(BASE + '/supplier/imports');
        await page.setInputFiles('#imp', file);
        await Promise.all([page.waitForNavigation(), page.click('button:has-text("Upload & map columns")')]);
        await Promise.all([page.waitForNavigation(), page.click('button:has-text("Validate & preview")')]);
        await page.screenshot({ path: path.join(OUT, 'supplier_import_preview_1366.png'), fullPage: true });
        page.on('dialog', (d) => d.accept());
        const confirmBtn = page.locator('button[value=import]');
        if (await confirmBtn.isDisabled()) {
            fail('supplier CSV import: no valid rows after validation');
        } else {
            await Promise.all([page.waitForNavigation(), confirmBtn.click()]);
            await page.goto(BASE + '/supplier/products?q=' + encodeURIComponent(`E2E-${stamp}-1`));
            const listed = await page.locator(`text=E2E tapered bearing`).count();
            const broken = await page.locator(`text=E2E broken row`).count();
            listed > 0 && broken === 0
                ? pass('supplier CSV import through UI: valid row imported, invalid row rejected')
                : fail(`supplier CSV import through UI: listed=${listed} broken=${broken}`);
            await page.screenshot({ path: path.join(OUT, 'supplier_import_result_1366.png') });
        }
    }

    await ctx.close();
}

await browser.close();
const failed = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ base: BASE, product: productPath, total: results.length, failed: failed.length, results }, null, 2));
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
