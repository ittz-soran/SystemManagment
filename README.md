# Store Management System

A PHP/Laravel inventory and store management system for an electronics shop in Iraq.
Built to `PROJECT_DOC.md`, which is the single source of truth for every design decision.

## The three rules that govern everything

1. **FIFO costing.** Every stock-in creates a batch; every stock-out consumes the oldest
   batch first and records which batch it came from.
2. **Batch cost = the unit price typed by the user.** Discounts never change it.
3. **Locks are computed live, never stored.**

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 |
| Database | MySQL / MariaDB |
| Frontend | Blade + Bootstrap 5 (RTL build for Sorani/Arabic/Persian) |
| Auth | Laravel Breeze |
| Invoices | Browser print page — no PDF library |

Currency is Iraqi Dinar, stored as integer `BIGINT` throughout. There are no decimal
columns anywhere in the schema.

## Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

Point `.env` at a MySQL/MariaDB database, then:

```bash
php artisan migrate --seed
```

`APP_TIMEZONE` is `Asia/Baghdad`. Without it a 10 PM sale is logged as the next day in
UTC, which breaks daily reports, `occurred_at` ordering, and the 24-hour edit window.

## Tests

```bash
php artisan test
```

The suite runs on in-memory SQLite, so it needs no database server. The headline test is
the Section 10b acceptance suite (`tests/Feature/AcceptanceTest.php`) — every value in it
is an assertion drawn from the project doc, and it is the gate before go-live.

> **One caveat:** `lockForUpdate()` is a silent no-op on SQLite, so the concurrency test
> proves nothing when run there. Run that one against real MySQL before go-live.

## Import & export

**Import & export** (in the sidebar, under System) moves the shop's *descriptive* rows —
products, categories, suppliers and customers — in and out as CSV files that open in
Excel. Export a price list, edit it in a spreadsheet, import it back.

It deliberately cannot write stock or a balance. `products.quantity` is a cache of the
batch sums and a balance is a cache of the ledger, so those move only through a purchase,
a stock adjustment, a sale or a payment. A file that tries anyway is not ignored quietly:
the row is reported with the reason.

Every import shows a preview first — added, changed, unchanged, skipped, row by row —
and writes nothing until you confirm.

> This is not a backup. To move *everything*, including stock and the ledger, use one.

## Appearance

The shop's colours, font and logo are on the Settings page and take effect
immediately — no rebuild.

Two things about how that works are worth knowing before changing it:

- The brand `<style>` block is emitted **after** the compiled stylesheet
  (`resources/views/partials/brand.blade.php`). Bootstrap declares
  `--bs-primary` and `--bs-body-font-family` in its own `:root`, so moving the
  block earlier silently reverts every appearance setting.
- Bootstrap 5.3 compiles component colours at build time — `.btn-primary`
  carries a literal `--bs-btn-bg`, not `var(--bs-primary)`. That is why the
  partial sets the component variables one by one rather than just the two
  top-level ones.

The four offered fonts are self-hosted (`@fontsource/*`, 400 and 600 weights,
Latin and Arabic subsets). Nothing is fetched from Google at runtime, because
the shop cannot depend on that being reachable.

The logo is served by `GET /branding/logo` rather than a `/storage/…` URL, so it
needs no `php artisan storage:link` — a step that requires administrator rights
on Windows and is missing on most XAMPP installs.

## Barcode labels

Section 4 gives an auto-generated barcode an internal EAN-13 prefix, so nothing is
printed on the goods — the shop prints its own. **Print barcode** on a product page
opens a modal: how many, which label size, and what goes on it (name, SKU, price,
the number under the bars, shop name). Defaults come from Settings; changes in the
modal apply to that print run only.

**A web page cannot choose a printer.** `window.print()` hands the job to the
operating system and the person picks there — no web API exists to target a
specific printer. So there are two routes:

- **Through the browser** — a page sized exactly to the label stock, one page per
  copy, `@page { margin: 0 }`. Always works, no setup, works from a phone.
- **Straight to the printer** — the server sends TSPL to a shared printer, no
  dialog at all. Only possible because the app runs on the same machine. Name the
  share in Settings (`\\localhost\XP-365B`); leave it empty to always use the dialog.

The bars never run edge to edge: EAN-13 needs 11 modules of clear background
before it and 7 after, so they get 95/113 of the usable width and the rest is
quiet zone. A label too narrow to carry a readable EAN-13 warns rather than
refusing — a 30 mm label is a legitimate choice for a small item.

## Starting fresh, and archiving

**Start fresh** (Settings → Danger zone) clears everything entered while testing —
sales, purchases, returns, payments, expenses, stock batches and movements, and the
ledger — and puts invoice numbers back to 1. Products, categories, suppliers,
customers, users and settings are kept, with stock zeroed. It takes a backup first,
makes you type the shop's name, and refuses once any period has been frozen with
`books_closed_before`, since nobody freezes test data.

**Archive a period** (Import & export) writes a period to a ZIP of spreadsheets and
then stops the lists showing it. **It deletes nothing, deliberately.** A purchase from
months ago may still own stock on the shelf; every sale's movements are the only
record of what its units cost; a balance is a running total of the ledger. Removing
old documents breaks all three, silently. So the rows stay, `archived_before` decides
what the list screens show by default, and every list carries a "Show them" link.

That hiding is a *local* scope (`Model::visible()`), used only by the index screens.
Making it global would hide the archived period from reports and from the FIFO engine
too — the exact corruption archiving exists to avoid.

## Backups

Financial records are the shop's only proof of who owes what, so this is not optional
setup. Two things need doing on the server, and both are five minutes:

**1. Point the off-machine copy at a disk that is not this one** — in `.env`:

```
BACKUP_REMOTE_PATH=/mnt/backup-drive/store-management     # or D:\backups\store on Windows
```

**2. Give Laravel's scheduler a way to fire**, or nothing ever runs:

```cron
* * * * * cd /var/www/store-management && php artisan schedule:run >> /dev/null 2>&1
```

On Windows that is a Task Scheduler task running `php artisan schedule:run` every
minute — see [docs/BACKUP.md](docs/BACKUP.md) for the exact fields.

Then check the whole chain in one command:

```
php artisan backup:check
```

It reports the database, the tools, whether mysqldump can actually connect, both
folders and whether backups have ever run — and prints what to do about anything
that fails. Fix what it names, then run `php artisan backup:run`.
If it says `'mysqldump' is not recognized`, the tool is not on PATH — XAMPP keeps
it in `C:\xampp\mysql\bin`. The command looks there itself; set `MYSQLDUMP_PATH`
if yours lives somewhere else — with forward slashes and no quotes, or `.env` will
not parse at all.

**[docs/BACKUP.md](docs/BACKUP.md)** has the rest: retention, restoring with
`php artisan backup:restore`, and the restore drill to run before go-live. An untested
backup is not a backup.
