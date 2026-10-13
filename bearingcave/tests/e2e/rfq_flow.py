"""End-to-end RFQ workflow over HTTP (buyer RFQ → matching → distribution → quotation → acceptance → order).

Usage: python3 tests/e2e/rfq_flow.py BASE_URL APP_DIR DB_NAME DB_USER DB_PASS
Needs DemoSeeder data (APP_DIR/writable/demo/credentials.txt). Creates one RFQ and one order.
"""
import os, urllib.request, urllib.parse, http.cookiejar, re, sys, time, subprocess, datetime
BASE, APP, DB, DBU, DBP = (sys.argv[1:6] + [None] * 5)[:5]
if not DBP:
    sys.exit(__doc__)
creds = {}
for line in open(APP + '/writable/demo/credentials.txt'):
    m = re.search(r'(\S+@example\.com)\s+(\S+)$', line)
    if m: creds[m.group(1)] = m.group(2)
def sql(q):
    return subprocess.run(['mysql', '-u' + DBU, '-p' + DBP, DB, '-N', '-B', '-e', q], capture_output=True, text=True).stdout.strip()
class S:
    def __init__(s, email):
        time.sleep(7)  # login throttle: 10 POSTs/min/IP
        s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        s.post('/login', {'email': email, 'password': creds[email]}, '/login')
        assert '/login' not in s.last_url, 'login failed ' + email
    def get(s, path):
        try:
            r = s.op.open(BASE + path, timeout=30); s.last_url = r.geturl(); return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()
    def post(s, path, data, form_page=None):
        _, html = s.get(form_page or path)
        tok = re.search(r'name="csrf_test_name" value="([^"]+)"', html).group(1)
        body = urllib.parse.urlencode(dict(data, csrf_test_name=tok), doseq=True).encode()
        r = s.op.open(BASE + path, data=body, timeout=30); s.last_url = r.geturl(); return r.status, r.read().decode()
def check(cond, msg):
    print(('PASS ' if cond else 'FAIL ') + msg); 
    if not cond: sys.exit(1)

buyer = S('demo.buyer.verified@example.com')
deadline = (datetime.datetime.now() + datetime.timedelta(days=5)).strftime('%Y-%m-%dT%H:%M')
st, _ = buyer.post('/buyer/rfqs', {'title': 'Clean-install RFQ 6204-2RS', 'destination_country': 'AE', 'currency': 'INR', 'delivery_terms': 'CIF', 'deadline_at': deadline,
    'items[0][part_number]': '6204-2RS', 'items[0][description]': 'Deep groove ball bearing', 'items[0][quantity]': '500', 'items[0][unit]': 'pcs'}, '/buyer/rfqs/new')
rid = sql("SELECT id FROM rfqs WHERE title='Clean-install RFQ 6204-2RS' ORDER BY id DESC LIMIT 1")
check(rid != '', f'buyer submitted RFQ via form (id {rid}, {sql(f"SELECT rfq_number FROM rfqs WHERE id={rid}")})')
sup_id = sql("SELECT company_id FROM company_members m JOIN users u ON u.id=m.user_id JOIN auth_identities i ON i.user_id=u.id WHERE i.secret='demo.supplier.verified@example.com'")
check(sql(f"SELECT COUNT(*) FROM rfq_matches WHERE rfq_id={rid} AND supplier_company_id={sup_id}") == '1', 'database matching suggested the verified supplier that lists 6204-2RS')
supplier = S('demo.supplier.verified@example.com')
check(supplier.get(f'/supplier/rfqs/{rid}')[0] == 404, 'supplier cannot open the RFQ before distribution (404)')
admin = S('demo.superadmin@example.com')
admin.post(f'/admin/rfqs/{rid}/distribute', {'suppliers[]': sup_id}, f'/admin/rfqs/{rid}')
check(sql(f"SELECT status FROM rfqs WHERE id={rid}") == 'distributed', 'admin distributed the RFQ')
st, html = supplier.get(f'/supplier/rfqs/{rid}')
check(st == 200 and 'Clean-install RFQ 6204-2RS' in html, 'supplier sees the distributed RFQ')
item = sql(f"SELECT id FROM rfq_items WHERE rfq_id={rid}")
pid = sql("SELECT id FROM products WHERE part_number='6204-2RS' AND company_id=" + sup_id + " LIMIT 1")
valid = (datetime.date.today() + datetime.timedelta(days=20)).isoformat()
supplier.post(f'/supplier/rfqs/{rid}/quote', {'currency': 'INR', 'valid_until': valid, 'lead_time_days': '10', 'payment_terms': '100% advance', 'logistics_option': 'platform_managed',
    f'lines[{item}][unit_price]': '17.25', f'lines[{item}][quantity]': '500', f'lines[{item}][product_id]': pid}, f'/supplier/rfqs/{rid}')
qid = sql(f"SELECT id FROM quotations WHERE rfq_id={rid} AND is_current=1")
check(qid != '' and sql(f"SELECT total_amount FROM quotations WHERE id={qid}") == '8625.00', 'supplier submitted quotation (500 × 17.25 = 8,625.00)')
st, html = buyer.get(f'/buyer/rfqs/{rid}')
check(st == 200 and '8,625' in html, 'buyer sees and compares the quotation')
buyer.post(f'/buyer/quotations/{qid}/accept', {'delivery_address': 'Jebel Ali, Dubai', 'accept_terms': '1'}, f'/buyer/rfqs/{rid}')
order = sql(f"SELECT CONCAT(order_number,' ',status,' ',total) FROM orders WHERE quotation_id={qid}")
check(order != '', f'buyer accepted → order created: {order}')
check(sql(f"SELECT status FROM rfqs WHERE id={rid}") == 'awarded', 'RFQ marked awarded')
