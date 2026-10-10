"""Regenerates docs/ER_DIAGRAM.md from the live schema.
Usage: DB_PASS=... [DB_USER=bc DB_NAME=bearingcave] python3 tools/er_diagram.py"""
import os, subprocess, collections
def q(sql):
    out = subprocess.run(['mysql','--default-character-set=utf8mb4','-u'+os.environ.get('DB_USER','bc'),'-p'+os.environ['DB_PASS'],os.environ.get('DB_NAME','bearingcave'),'-N','-B','-e',sql],capture_output=True,text=True).stdout
    return [l.split('\t') for l in out.strip().split('\n') if l]
fks = q("SELECT table_name, column_name, referenced_table_name FROM information_schema.key_column_usage WHERE table_schema='"+os.environ.get('DB_NAME','bearingcave')+"' AND referenced_table_name IS NOT NULL ORDER BY table_name, column_name")
cols = q("SELECT c.table_name, c.column_name, c.column_type, c.column_key FROM information_schema.columns c WHERE c.table_schema='"+os.environ.get('DB_NAME','bearingcave')+"' ORDER BY c.table_name, c.ordinal_position")
fkset = {(t,c) for t,c,_ in fks}
cols=[r+['']*(4-len(r)) for r in cols]
domains = collections.OrderedDict([
 ('Identity & companies', ['users','auth_identities','auth_groups_users','auth_permissions_users','companies','company_members','company_member_permissions','company_directors','buyer_profiles','supplier_profiles','company_country_rules','countries','currencies']),
 ('Verification (12 modules)', ['companies','users','verification_applications','verification_stages','verification_events','kyc_records','compliance_certifications','financial_assessments','risk_assessments','quality_assessments','site_audits','sanctions_screenings','approved_vendors','supplier_performance','documents','supplier_master_fields','supplier_master_attributes','categories','brands','products']),
 ('Membership & billing', ['companies','users','membership_plans','plan_entitlements','subscriptions','invoices','payments','payment_webhook_events','orders']),
 ('Catalog & inventory', ['companies','users','categories','brands','products','part_numbers','cross_references','fitments','product_specifications','product_images','pricing_tiers','product_country_rules','inventory_lots','inventory_movements','import_jobs','import_job_rows','saved_products','product_enquiries','search_logs']),
 ('Procurement & orders', ['companies','users','products','rfqs','rfq_items','rfq_matches','quotations','quotation_items','quotation_messages','carts','cart_items','orders','order_items','order_status_history']),
 ('Services, disputes & platform', ['companies','users','orders','inspection_requests','inspection_reports','logistics_requests','shipments','shipment_events','disputes','dispute_messages','notifications','email_outbox','audit_logs','platform_settings','cms_pages','contact_leads','number_sequences']),
])
out=['# Entity–relationship diagram','','Generated from the live MySQL schema (`information_schema`) after running all migrations — not hand-drawn — so it matches the code. Regenerate after schema changes with `DB_PASS=… python3 tools/er_diagram.py`.','',f'Tables: {len({c[0] for c in cols})} · foreign keys: {len(fks)}. Shield auth tables (`auth_*`) are owned by CodeIgniter Shield.','']
colmap=collections.defaultdict(list)
for row in cols:
    row += ['']*(4-len(row)); t,c,ty,k=row; colmap[t].append((c,ty,k))
for name, tables in domains.items():
    ts=set(tables)
    out += [f'## {name}','','```mermaid','erDiagram']
    for t in tables:
        if t not in colmap: continue
        out.append(f'    {t} {{')
        shown=0
        for c,ty,k in colmap[t]:
            key = 'PK' if k=='PRI' else ('FK' if (t,c) in fkset else ('UK' if k=='UNI' else ''))
            if key or shown<4:
                tyc=ty.split('(')[0].replace(' unsigned','')
                out.append(f'        {tyc} {c}' + (f' {key}' if key else ''))
                if not key: shown+=1
        out.append('    }')
    for t,c,r in fks:
        if t in ts and r in ts and t!=r:
            out.append(f'    {r} ||--o{{ {t} : "{c}"')
    out += ['```','']
out += ['## Critical relationships','',
'- **companies** is the tenant boundary. Every buyer/supplier record (products, RFQs, quotations, orders, documents, verification) carries a `company_id` / `*_company_id`; controllers load private records through `BaseController::owned()` which 404s and writes a security audit event on mismatch.',
'- **company_members** links Shield `users` to companies with a member role (owner/admin/member) and **company_member_permissions** grants employees granular rights (`App\\Libraries\\CompanyPermissions`).',
'- **products → inventory_lots → inventory_movements**: stock is received in dated lots (inventory age = oldest open lot), every change writes a movement row; `products.stock_on_hand/stock_reserved` are denormalised counters protected by CHECK constraint `chk_products_stock` and conditional UPDATEs (no overselling).',
'- **part_numbers / cross_references / fitments** hold normalised part numbers, supplier-declared cross references (`relation` distinguishes declared vs verified interchange) and vehicle applications.',
'- **product_country_rules / company_country_rules** implement allow/deny country visibility; `VisibilityService` is the single enforcement point used by web, search, API, RFQ matching, exports and sitemap.',
'- **rfqs → rfq_items → rfq_matches → quotations → quotation_items**: matches store score + reasons; quotations are per supplier with revision numbers; acceptance creates an **orders** row with **order_items**.',
'- **orders → invoices → payments**: proforma invoices and payments are separate; **payment_webhook_events** enforces idempotency (unique provider + event id).',
'- **membership_plans → plan_entitlements**, **subscriptions** (date-ranged) determine features via `EntitlementService`; verification status lives on `companies`, independent of payment.',
'- **verification_applications → verification_stages → verification_events**: one application per company, one row per configured stage, append-only event history.',
'- **supplier_master_fields / supplier_master_attributes**: the 86 Supplier List headings are field definitions (EAV for headings without a native column); headings that map to native tables (KYC, certifications, risk, etc.) are written there.',
'']
open('docs/ER_DIAGRAM.md','w').write('\n'.join(out))
print(len(out))
