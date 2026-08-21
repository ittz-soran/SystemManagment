# Backups and restore

> Section 8b of the project doc: *"Financial records are the shop's only proof of
> who owes what."* And: *"An untested backup is not a backup."*

## What runs

`backup:run` dumps the whole database with `mysqldump --single-transaction`
(consistent without locking the till out mid-sale), gzips it, copies it off the
machine, and prunes what has aged out.

| | |
|---|---|
| When | nightly, `BACKUP_AT` (default 02:15) in the shop's timezone |
| Local copy | `storage/app/backups/daily/backup-YYYY-MM-DD-HHMMSS.sql.gz` |
| Monthly keeper | the first backup of each month, `storage/app/backups/monthly/backup-YYYY-MM.sql.gz` |
| Off-machine copy | `BACKUP_REMOTE_PATH` |
| Retention | 30 daily, 12 monthly |

## Setting it up

**1. Point the off-machine copy somewhere that is not this machine.** A mounted
share, an external drive, a directory an rclone job syncs to cloud storage —
anything whose disk is not the one the database is on. A dead disk should not
take both.

```
BACKUP_REMOTE_PATH=/mnt/backup-drive/store-management
```

Until this is set, every run warns and the settings page shows a warning, because
a backup on the same disk is not a backup.

**2. Give the scheduler its one crontab line.** Laravel's schedule only fires if
something calls it every minute:

```cron
* * * * * cd /var/www/store-management && php artisan schedule:run >> /dev/null 2>&1
```

On Windows there is no cron. Create a Task Scheduler task that runs every minute:

```
Program:   C:\xampp\php\php.exe
Arguments: artisan schedule:run
Start in:  C:\xampp\htdocs\SystemManagment
```

Set it to run whether the user is logged on or not, or backups stop the moment
Soran signs out for the night.

**3. Check `mysqldump` can be found.** It ships with the database server, but
not every install puts it on PATH — XAMPP keeps it in `C:\xampp\mysql\bin` and
adds nothing to PATH, so running the command by hand gives:

```
'mysqldump' is not recognized as an internal or external command
```

`backup:run` looks in the usual places itself (XAMPP, Laragon, WAMP, a default
MySQL or MariaDB install, Homebrew, `/opt/lampp`), so on a normal setup there is
nothing to do. If yours is somewhere else, say where:

```
# Windows / XAMPP
MYSQLDUMP_PATH=C:/xampp/mysql/bin/mysqldump.exe
MYSQL_PATH=C:/xampp/mysql/bin/mysql.exe

# Linux
MYSQLDUMP_PATH=/usr/bin/mysqldump
MYSQL_PATH=/usr/bin/mysql
```

> **Forward slashes, and no quotes.** A Windows path does not survive `.env` in the
> obvious form: dotenv reads the `\x` of `\xampp` inside double quotes as an escape
> sequence and refuses to parse the file, and every command then fails with *"The
> environment file is invalid!"* before Laravel boots. Windows accepts forward
> slashes everywhere, so use those and the question never comes up.

Cron is worth a second thought here even when the command works by hand: its
PATH is much shorter than a login shell's, which is the classic reason a backup
runs fine at the keyboard and silently fails at night. Setting the two absolute
paths removes the question.

**4. Confirm.** Run it once by hand, then look at the Settings page — it shows
the last backup time, how many copies exist, and where they go.

```
php artisan backup:run
```

## Restoring

Restoring **replaces every row** in the database with the backup's contents.
Nothing on the web can trigger it; it is a command on the server.

```
php artisan backup:restore                                   # the newest daily copy
php artisan backup:restore /mnt/backup-drive/store-management/backup-2026-08-21-021500.sql.gz
```

It asks for confirmation. `--force` skips the question, for scripts.

## Testing the restore

Do this **before go-live and every few months after**. It takes ten minutes and
it is the only thing that turns a pile of `.sql.gz` files into a backup.

1. Note today's numbers from the dashboard: total stock value, what customers
   owe, and the document number of the most recent sale.

2. Create a scratch database and point a copy of the app at it — never test a
   restore against the live one:

   ```
   mysql -u root -e "CREATE DATABASE store_restore_test CHARACTER SET utf8mb4"
   ```

   ```
   DB_DATABASE=store_restore_test php artisan backup:restore /path/to/backup.sql.gz
   ```

3. Serve that copy and check the three numbers from step 1 match, then open the
   most recent sale and confirm its lines and payments are there.

4. Run the stock check — it compares the cached `products.quantity` against the
   batch sums, and a restore that lost rows shows up here immediately:

   ```
   DB_DATABASE=store_restore_test php artisan tinker --execute="
     foreach (App\Models\Product::all() as \$p) {
         \$sum = \$p->stockBatches()->sum('quantity_remaining');
         if ((int) \$sum !== (int) \$p->quantity) echo \$p->sku.' MISMATCH'.PHP_EOL;
     }"
   ```

5. Drop the scratch database.

If any step fails, the backups are not working — fix that before anything else,
because everything else in this system is recoverable and this is not.

## When a backup fails

The command exits non-zero and writes to `storage/logs/laravel.log`. The
Settings page keeps showing the *last successful* run, so a stale "last backup"
date there is itself the alarm: anything older than two days is flagged.

Common causes, in the order they actually happen:

- `mysqldump` not found, or not on cron's PATH → set `MYSQLDUMP_PATH` to the
  full path, forward slashes and unquoted: `C:/xampp/mysql/bin/mysqldump.exe`.
- The off-machine path is a share that unmounted → the local copy is still
  written and the run warns rather than failing.
- The disk is full → the dump is discarded rather than left half-written, so a
  truncated file is never mistaken for a backup.
