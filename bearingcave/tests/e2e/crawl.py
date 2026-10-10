"""Authenticated link crawl: logs in as every demo account (writable/demo/credentials.txt),
follows same-site GET links and fails on HTTP >= 400 or PHP error output.
Usage: python3 tests/e2e/crawl.py [baseUrl]   (login throttle => ~6.5 s pause per account)"""
import urllib.request, urllib.parse, http.cookiejar, re, sys, json
BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8080'
creds = {}
for line in open('writable/demo/credentials.txt'):
    m = re.match(r'(.{26})(\S+@example\.com)\s+(\S+)', line)
    if m: creds[m.group(2)] = m.group(3)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    pass

def session():
    cj = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

def get(op, path):
    try:
        r = op.open(BASE + path, timeout=30)
        return r.status, r.read().decode('utf-8', 'replace'), r.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), path

import time
def login(email):
    time.sleep(6.5)
    op = session()
    st, body, _ = get(op, '/login')
    tok = re.search(r'name="csrf_test_name" value="([^"]+)"', body).group(1)
    data = urllib.parse.urlencode({'csrf_test_name': tok, 'email': email, 'password': creds[email]}).encode()
    r = op.open(BASE + '/login', data=data, timeout=30)
    return op, r.geturl()

BAD = re.compile(r'(Whoops|ErrorException|Fatal error|Warning</b>|Notice</b>|Undefined (variable|array key|index)|Uncaught|TypeError|ArgumentCountError|DatabaseException|Call to undefined|<title>Error)', re.I)

def check(op, paths, label):
    fails = 0
    for p in paths:
        st, body, url = get(op, p)
        bad = BAD.search(body)
        if st >= 400 or bad:
            fails += 1
            snippet = ''
            if bad:
                i = bad.start(); snippet = re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', body[max(0,i-200):i+600]))[:700]
            print(f'  FAIL [{label}] {p} -> {st} {url} :: {snippet}')
    print(f'{label}: {len(paths)-fails}/{len(paths)} OK')
    return fails

total = 0
guest = session()
pub = ['/', '/marketplace', '/marketplace?q=6204', '/marketplace?q=FAG%206204', '/marketplace?category=ball-bearings&age=gt_12m&verified=1&sort=price_asc', '/marketplace?id=20&od=47', '/category/bearings', '/brand/skf',
       '/membership', '/how-it-works', '/for-buyers', '/for-suppliers', '/services/inspection', '/services/logistics', '/trust-and-verification', '/about', '/contact',
       '/register', '/register/buyer', '/register/supplier', '/login', '/suppliers', '/compare', '/page/terms', '/page/privacy', '/sitemap.xml', '/robots.txt', '/search/suggest?q=620',
       '/api/v1/products', '/api/v1/products?q=6204', '/api/v1/categories', '/api/v1/brands', '/api/v1/suggest?q=62', '/login/magic-link']
# product pages
st, body, _ = get(guest, '/marketplace')
slugs = sorted(set(re.findall(r'/product/([a-z0-9\-]+)', body)))
pub += ['/product/' + s for s in slugs[:6]]
st, body, _ = get(guest, '/suppliers')
pub += sorted(set(re.findall(r'(/suppliers/[a-z0-9\-]+)', body.replace(BASE, ''))))[:2]
total += check(guest, pub, 'guest')

buyer_paths = ['/dashboard', '/buyer/dashboard', '/buyer/company', '/buyer/team', '/buyer/verification', '/buyer/documents', '/buyer/billing', '/buyer/disputes', '/buyer/inspections', '/buyer/inspections/new',
    '/buyer/logistics', '/buyer/logistics/new', '/buyer/saved', '/buyer/enquiries', '/buyer/rfqs', '/buyer/rfqs/new', '/buyer/cart', '/buyer/orders', '/notifications', '/account', '/compare', '/marketplace?q=NU206']
for email in ['demo.buyer.verified@example.com', 'demo.buyer@example.com']:
    op, landed = login(email)
    paths = list(buyer_paths)
    st, body, _ = get(op, '/buyer/rfqs')
    paths += sorted(set(re.findall(r'(/buyer/rfqs/\d+)', body.replace(BASE, ''))))
    st, body, _ = get(op, '/marketplace')
    paths += ['/product/' + s for s in sorted(set(re.findall(r'/product/([a-z0-9\-]+)', body)))[:3]]
    paths += ['/buyer/rfqs/new?product_id=1']
    total += check(op, paths, email)

sup_paths = ['/dashboard', '/supplier/dashboard', '/supplier/company', '/supplier/team', '/supplier/verification', '/supplier/documents', '/supplier/billing', '/supplier/disputes', '/supplier/inspections', '/supplier/inspections/new',
    '/supplier/logistics', '/supplier/logistics/new', '/supplier/membership', '/supplier/products', '/supplier/products?age=gt_12m', '/supplier/products/new', '/supplier/products/export', '/supplier/imports', '/supplier/imports/template',
    '/supplier/enquiries', '/supplier/rfqs', '/supplier/quotations', '/supplier/orders', '/supplier/analytics', '/supplier/visibility', '/supplier/account-manager', '/notifications', '/account']
for email in ['demo.supplier.verified@example.com', 'demo.supplier.free@example.com', 'demo.supplier.employee@example.com']:
    op, landed = login(email)
    paths = list(sup_paths)
    st, body, _ = get(op, '/supplier/products')
    paths += sorted(set(re.findall(r'(/supplier/products/\d+/edit)', body.replace(BASE, ''))))[:3]
    st, body, _ = get(op, '/supplier/rfqs')
    paths += sorted(set(re.findall(r'(/supplier/rfqs/\d+)', body.replace(BASE, ''))))
    total += check(op, paths, email)

admin_paths = ['/admin', '/admin/users', '/admin/users/1', '/admin/staff', '/admin/companies?type=supplier', '/admin/companies?type=buyer', '/admin/companies/1', '/admin/companies/3', '/admin/verifications', '/admin/verifications?status=all',
    '/admin/verifications/1', '/admin/verifications/2', '/admin/avl', '/admin/categories', '/admin/brands', '/admin/products', '/admin/products?status=published', '/admin/products/1', '/admin/inventory', '/admin/imports', '/admin/imports/template',
    '/admin/supplier-master', '/admin/supplier-master/template', '/admin/rfqs', '/admin/rfqs/1', '/admin/quotations', '/admin/orders', '/admin/inspections', '/admin/logistics', '/admin/payments', '/admin/invoices', '/admin/invoices/1',
    '/admin/webhooks', '/admin/memberships', '/admin/memberships/2', '/admin/subscriptions', '/admin/performance', '/admin/disputes', '/admin/reports', '/admin/cms', '/admin/cms/1', '/admin/cms/new', '/admin/notifications', '/admin/settings', '/admin/seo',
    '/admin/audit', '/admin/leads', '/notifications', '/account']
import importlib
for key in ['supplier_registrations', 'verification_funnel', 'inventory_by_category', 'aged_stock', 'stock_liquidation', 'buyer_activity', 'rfq_matching', 'rfq_conversion', 'supplier_response', 'order_volume', 'membership_revenue', 'inspection_requests', 'logistics_requests', 'supplier_performance', 'buyer_engagement']:
    admin_paths += ['/admin/reports/' + key, '/admin/reports/' + key + '/export', '/admin/reports/' + key + '/export?format=xlsx']
op, landed = login('demo.superadmin@example.com')
total += check(op, admin_paths, 'superadmin')
for email in ['demo.verification-officer@example.com', 'demo.finance-manager@example.com', 'demo.support-executive@example.com', 'demo.procurement-manager@example.com', 'demo.inspection-manager@example.com', 'demo.logistics-manager@example.com', 'demo.account-manager@example.com']:
    op, landed = login(email)
    st, body, url = get(op, '/admin')
    links = sorted(set(p for p in re.findall(r'href="' + re.escape(BASE) + r'(/admin[^"#]*)"', body)))
    total += check(op, ['/admin'] + links[:40], email)
print('TOTAL FAILURES:', total)
