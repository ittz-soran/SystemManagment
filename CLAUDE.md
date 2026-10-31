# Smart Soran Store — working on this repo

A PHP/Laravel store system for a real electronics shop in Iraq, sold monthly to
other shops and hosted by Soran on shared cPanel.

**`PROJECT_DOC.md` is the specification. Read §1 first, every session.** It holds
the three rules everything obeys (FIFO, batch cost, live locks), the deploy facts
and the outages behind them. This file does not repeat it — two copies drift.

## Soran's standing rules

- **Do not push until Soran says "push".** Commit locally, show him the result,
  wait. The stop hook will nag about unpushed commits every turn; his
  instruction wins.
- **Doc first.** Where `PROJECT_DOC.md` does not describe something, write the
  decision into it *before* the code — quoting his own words and the date.
- **One branch per chat**; push only to the branch named at session start.
- **After merging anything, run the whole suite before pushing.**
- **Never create a pull request** unless he asks.
- Never paste `.env` contents. The licence private key never touches the server.
- Reply in English. He writes quickly; when a message is ambiguous, ask one
  short question rather than guess — a screenshot settles most things.

## Commands

The SessionStart hook (`.claude/hooks/session-start.sh`) installs everything in
a cloud session. Locally: `composer install`, `npm install`, `.env` + key.

```bash
php vendor/phpunit/phpunit/phpunit                  # whole suite, ~3 min
php vendor/phpunit/phpunit/phpunit --filter Name    # one test or class
vendor/bin/pint --test path/to/File.php             # lint — ALWAYS --test first
php artisan translations:check                      # must report 100% for ckb/ar/fa
php artisan translations:check --sync               # adds empty keys to fill in
```

- **Use `phpunit` directly, not `php artisan test`**, and check the exit code.
  The artisan runner's summary is built from results, not the exit code.
- **Never run Pint without `--test` on a folder.** About 40 files carry style
  differences from before Pint was adopted; fixing them is not anybody's task,
  and a stray run rewrites all of them. Lint the files you touched.
- **Changed anything under `resources/`?** Run `npm run build` and commit
  `public/build` *in the same commit* (§1 explains why). New `bi-*` icon? Run
  `python3 tools/subset-icons.py` first; `IconSubsetTest` catches it.
- **Dev app:** `ADMIN_PASSWORD=<choose one> php artisan migrate:fresh --seed --force`,
  then `php artisan serve --host=127.0.0.1 --port=8000`, log in as
  `admin@example.com`. Every Save is a two-second press-and-hold (`HOLD_MS`). Playwright is
  preinstalled; use Chromium at `/opt/pw-browsers/chromium`.

## How to work here

- **Reproduce before fixing**, ideally on his real data. Pattern-matching a new
  report onto the last fix has written a wrong cause down as fact here before.
- **Sabotage every guard.** Break the code, confirm the test fails, confirm it
  fails *for the right reason*. Several tests here passed for the wrong one.
- **Drive it in a browser** when behaviour is the point. Four bugs in the word
  completion passed every PHP test and were found only by typing into the box.
- **SQLite here, MariaDB in CI.** SQLite hides reserved words, unsigned
  arithmetic and row numbering. Read CI when it is red; do not assume.

## Traps that have each cost real time

- `payable_type` holds the **morph alias**. Use `getMorphClass()`, never `::class`.
- `assertSee('8,000')` also matches `88,000`. Read the element, not the page.
- `resources/js/app.js` is a **deferred module**; a page's inline script runs
  first. The page assigns a global, `app.js` reads it — never the other way.
- Blade's `@json` **splits its argument on commas** and **escapes quotes for
  HTML**. Pass it one variable; inside `<script type="application/json">` use
  `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP)` instead.
- PHP turns an **all-digit array key into an integer** (`'5500'` → `5500`).
- `QueryException` **is a `RuntimeException`**. Show users `problem($e)`, never
  `$e->getMessage()` — it put raw SQL on his screen once.
- **Soft deletes hide rows from guards, never from foreign keys.** Count what the
  database sees (`Product::purgeBlockers`). A document's delete keeps its header
  and drops its lines (`PROJECT_DOC.md` §8).
- **Two clocks**: `TradeProfit` is by period, `DocumentProfit` by cohort. Anything
  printing either says which.
- **Never hard-code ids in tests** — SQLite and MariaDB number rows differently.
- **An enum column takes any word on SQLite here** — earlier migrations rebuilt
  the tables without the check. MariaDB refuses it (*data truncated*), and
  `problem()` shows that as "something else depends on this record". Write only
  the column's listed values, and assert the value in the test.
- **After a script changes a Blade view, `php artisan view:clear` before the next
  run.** On 2026-10-31 a compiled copy of a sabotaged view outlived its restore and
  failed the whole suite. It would not reproduce and the cause was not found, so
  clear rather than trust it — in both directions: a stale copy can fail a good
  view or pass a broken one.
- **Git does not keep file times.** Never compare two files' `filemtime` — a
  fresh clone writes them in any order. Check the built bundle by what it
  contains (`AssetBuildTest`).
