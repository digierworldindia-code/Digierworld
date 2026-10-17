# Google Sheets sync — setup guide

DGR Office keeps all CRM data in MySQL. Google Sheets is a **one-way copy**
(database → sheet) for people who like to look at leads in a spreadsheet.
Edits made in the sheet are **overwritten** on the next sync; make changes in
DGR Office.

What gets written (default tabs, all renameable in *CRM → Google Sheets Sync*):

| Tab | One row per | Key in column A |
|---|---|---|
| Leads | lead | `L<id>` |
| Call Logs | call | `C<id>` |
| Follow Ups | follow-up | `F<id>` |
| Visits | visit | `V<id>` |
| Conversions | converted lead | `K<id>` |
| Daily Summary | telecaller per day | `YYYY-MM-DD|<user id>` |

Column A is the stable database key. When a record changes, the same row is
updated (found through the stored row number, or by searching column A if
someone sorted or inserted rows). Rows are never duplicated by retries.

Privacy defaults: numbers of leads marked **Do-Not-Call** or with withdrawn
consent are masked (`98XXXXXX12 (DND)`); e-mail addresses and money amounts are
not written. You can switch columns on/off per tab — think twice before adding
amounts to a sheet that is shared widely.

---

## 1. Create a Google Cloud project and enable the Sheets API

1. Open <https://console.cloud.google.com/> with the company Google account.
2. Create a project, e.g. `dgr-office-sync`.
3. *APIs & Services → Library* → search **Google Sheets API** → **Enable**.

## 2. Create a service account and key

1. *APIs & Services → Credentials → Create credentials → Service account*.
2. Name it `dgr-office-sheets`. No project roles are needed.
3. Open the service account → *Keys → Add key → Create new key → JSON*.
   A `.json` file downloads. **Treat it like a password.**
4. Note the service account e-mail, e.g.
   `dgr-office-sheets@dgr-office-sync.iam.gserviceaccount.com`.

## 3. Put the key on the server (never in the browser, never in Git)

Upload the JSON by SFTP/SSH to a folder **outside the public web root**:

```bash
mkdir -p /path/to/erp/writable/credentials
mv ~/dgr-office-sheets.json /path/to/erp/writable/credentials/sheets.service-account.json
chmod 600 /path/to/erp/writable/credentials/sheets.service-account.json
chown www-data:www-data /path/to/erp/writable/credentials/sheets.service-account.json   # the PHP user
```

`writable/credentials/` and `*.service-account.json` are git-ignored. The app
refuses a key path inside `public/`.

Either set it in `.env`:

```ini
google.credentialsPath = 'writable/credentials/sheets.service-account.json'
```

or enter the same relative path in *CRM → Google Sheets Sync → Connection*.

## 4. Create the spreadsheet and share it

1. Create a new Google Sheet (e.g. *DGR CRM — live copy*).
2. **Share** it with the service-account e-mail as **Editor**. Share it with
   your team members as **Viewer** (they should edit in DGR Office).
3. Copy the spreadsheet ID — the long code in the URL between `/d/` and
   `/edit` (you can also paste the whole URL; the ID is extracted).

## 5. Connect in DGR Office

*CRM → Google Sheets Sync* (needs the permission *Configure Google Sheets sync*):

1. Paste the spreadsheet ID, confirm the key path, choose tab names, columns and
   the sync interval (minimum 5 minutes). Tick **Sync enabled** and save.
2. Press **Test connection & create tabs**. It creates any missing tabs and
   writes the header rows. If it fails, the message says why (most often: the
   sheet is not shared with the service account, or the Sheets API is not
   enabled).
3. Press **Queue all records** once to send existing data, then **Sync now**
   (or wait for cron).

## 6. Cron

Add one crontab line for the web user (every minute is fine — each task decides
when it is due, and a lock prevents overlaps):

```cron
* * * * * cd /path/to/erp && php spark dgr:cron >> writable/logs/cron.log 2>&1
```

`dgr:cron` runs reminders, the Sheets queue (at the interval you chose) and
recurring retainer drafts. Admin → Diagnostics shows the last run of each task.

## How failures are handled

* Every CRM save is committed to MySQL first; the sync job is written in the
  **same transaction**. If Google is down, nothing is lost.
* A failed job is retried after 1, 2, 4, 8 … minutes (max 6 hours between
  tries). After 8 failed attempts it is marked *dead* and stays visible on the
  dashboard with its last error until you press **Retry failed**.
* The dashboard shows Connected / Disconnected, last successful sync, rows
  waiting and failed rows.

## If credentials are not available

Leave sync disabled. The dashboard shows **Not configured** — DGR Office never
pretends to sync. Users with the *Export CRM data* permission can download the
same tabs as an Excel workbook (*CRM → Leads → Export*); every export is logged.

## Two-way sync

Not implemented and switched off. Reading edits back from a sheet needs a
conflict policy (which side wins, who edited, audit) and is future scope.
