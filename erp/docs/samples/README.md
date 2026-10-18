# Sample import files

These files are **fictional** and exist only to try the importers. Every
business name starts with `SAMPLE`, phone numbers are in the `90000001xx`
/ `90000002xx` test pattern and e-mail addresses use the reserved
`example.com/.org/.net` domains. The one GSTIN (`27AAAAA0000A1Z2`) is a
format placeholder with a valid check character, **not a real
registration** — never use it on a real invoice.

| File | Where to import | Notes |
| --- | --- | --- |
| `contacts-import-sample.csv` | Clients & vendors → Import (`/contacts/import`, permission `contacts.import`) | Columns are exactly the ones in the downloadable template (`/contacts/import/template`). `type` = `customer`, `vendor`, `prospect` or `customer+vendor`. Blank `state_code` is derived from the GSTIN. Preview shows errors/duplicates before anything is saved. |
| `crm-leads-import-sample.csv` | CRM → Leads → Import (`/crm/leads/import`, permission `crm.lead.import`) | Headers are matched by name (aliases such as `phone`, `mobile_no`, `business_name` also work). Duplicates are detected by normalised mobile. `source` must match a lead source name (Settings → Masters); `assigned` may hold a user's e-mail or full name, otherwise the assignee chosen on the review screen is used. |
| `bank-statement-sample.csv` | Finance → Bank reconciliation → Import statement (`/finance/reconciliation/import`) | Mimics a typical Indian bank export with a preamble before the table. The header row and column mapping are detected automatically and can be corrected before import; footer lines are skipped. Import it against a bank account, then match the lines to receipts/expenses. |

`tests/feature/SampleImportFilesTest.php` parses all three files with the
same code paths as the importers and fails if any row would be rejected.
