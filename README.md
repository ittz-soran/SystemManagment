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
