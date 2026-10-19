#!/bin/bash
#
# Gets a fresh Claude Code cloud container ready to work on Smart Soran Store:
# PHP and JS dependencies, a .env with a key, and a migrated SQLite database.
#
# Cloud sessions only. On anybody's own machine this does nothing, because a
# developer's .env and database are theirs and must never be touched.
#
# ⚠️ IT NEVER RUNS `npm run build`. public/build is committed on purpose —
# Soran's cPanel hosting has no Node — so building here would rewrite tracked
# files on every session start. Whoever changes resources/ builds by hand and
# commits the result; AssetBuildTest fails if they forget. See PROJECT_DOC §1.
#
# ⚠️ IT NEVER INSTALLS MARIADB. BackupTest has two cases that prove the
# backup copes with `mysqldump` being absent, and a MariaDB install puts it on
# PATH. CI runs the suite on MariaDB; here it is SQLite.
#
# Idempotent: safe on startup, resume, clear and compact alike.

set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

# --- PHP ---------------------------------------------------------------------
composer install --no-interaction --prefer-dist --no-progress

# --- .env ----------------------------------------------------------------------
# Only ever created, never overwritten.
if [ ! -f .env ]; then
  cp .env.example .env

  # .env.example points at MySQL for a real shop. A container has none, so the
  # dev app runs on a SQLite file. The tests ignore this — phpunit.xml gives
  # them their own in-memory database.
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
  sed -i "s#^DB_DATABASE=.*#DB_DATABASE=$CLAUDE_PROJECT_DIR/database/database.sqlite#" .env
fi

# phpunit.xml sets no APP_KEY, so without this every test that encrypts fails.
if ! grep -qE '^APP_KEY=.+' .env; then
  php artisan key:generate --force --no-interaction
fi

# --- database ------------------------------------------------------------------
# Migrated, not seeded: the tests seed themselves, and seeding here would mean
# picking an admin password. See CLAUDE.md for seeding the dev app.
touch database/database.sqlite
php artisan migrate --force --no-interaction

# --- JS --------------------------------------------------------------------------
# Last on purpose: only `npm run build` needs it, so a problem here must never
# stop the PHP, .env and database steps above that every test depends on.
# --ignore-scripts, as composer's own `setup` script does.
#
# ⚠️ package.json carries a "name" for this. Without one npm names the package
# after the FOLDER, and folders differ by machine — so every fresh container
# rewrote package-lock.json and left a stray diff waiting to be committed.
npm install --ignore-scripts --no-audit --no-fund
