# Installing the system

Three ways to run it, in order of how much you need:

| | |
|---|---|
| [One shop, one computer](#one-shop-one-computer) | the till, the stock and the database all on one machine |
| [Two or more computers in the shop](#two-or-more-computers-in-the-shop) | one machine holds everything, the others open it in a browser |
| [The database somewhere else](#the-database-somewhere-else) | a hosted database, reachable from more than one place |

Whichever you pick, read [Before you open the shop with it](#before-you-open-the-shop-with-it)
at the end. It is short and it is the part that matters.

---

## What the machine needs

| | |
|---|---|
| PHP | 8.3 or newer, with the `pdo_mysql`, `mbstring`, `zip`, `gd` and `intl` extensions |
| MySQL | 8.0 or newer, or MariaDB 10.6 or newer |
| Composer | 2.x |
| Node | 20 or newer, for building the stylesheet and scripts once |

On Windows, **XAMPP** gives you PHP and MySQL together and is what this system
has been run on. Install it to `C:\xampp`. Composer and Node are separate
downloads.

---

## One shop, one computer

```bash
git clone <your repository> SystemManagment
cd SystemManagment

composer install
npm install
npm run build            # compiles the stylesheet and scripts into public/build

cp .env.example .env     # Windows: copy .env.example .env
php artisan key:generate
php artisan storage:link
```

Create the database, once, in phpMyAdmin or at the command line:

```sql
CREATE DATABASE store_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Open `.env` and set at least these:

```ini
APP_NAME="Soran Store"
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Baghdad
APP_LOCALE=ckb                 # en, ckb, ar or fa

DB_DATABASE=store_management
DB_USERNAME=root
DB_PASSWORD=

# Where nightly backups are copied. A USB stick or a network drive — anywhere
# that is not this computer. See docs/BACKUP.md.
BACKUP_REMOTE_PATH=D:/backups
```

> **Windows paths in `.env` take forward slashes and no quotes.**
> `D:/backups`, never `"D:\backups"` — a backslash before a letter is read as an
> escape sequence and the file will not parse.

Then create the tables and the first user:

```bash
php artisan migrate --seed
```

That seeds the permissions, the settings, the Cash Customer, the document
counters and the two categories the system fills in for you. It also creates the
administrator:

```
admin@example.com / password
```

**Change that password the first time you log in.** Settings → Users.

Run it:

```bash
php artisan serve
```

and open `http://localhost:8000`.

`php artisan serve` is fine for one machine, but it stops when you close the
window. To have it always there, point Apache at `public/` instead — in XAMPP,
add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/SystemManagment/public"
    ServerName shop.local
    <Directory "C:/xampp/htdocs/SystemManagment/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 shop.local` to `C:\Windows\System32\drivers\etc\hosts`, restart
Apache, and set `APP_URL=http://shop.local` in `.env`.

### Moving an existing shop to another computer

Install as above, but instead of `migrate --seed`, bring the data across:

```bash
# On the old machine
php artisan backup:run

# Copy the .sql.gz file over, then on the new machine
php artisan backup:restore storage/app/backups/daily/backup-....sql.gz
```

Copy `storage/app/public` too — that is where the shop logo lives. Do **not**
copy `.env` wholesale: copy it and then check `APP_URL`, `DB_*` and
`BACKUP_REMOTE_PATH`, which are about the new machine, not the old one.

---

## Two or more computers in the shop

One machine — the one at the counter, or a small always-on box — runs everything.
The others need no installation at all: they open it in a browser.

On the machine that runs it:

1. Give it a fixed local address, e.g. `192.168.1.10`, in your router.
2. Serve on all interfaces rather than just itself:
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
   With Apache, this already works — just use the machine's address.
3. Set `APP_URL=http://192.168.1.10:8000` in `.env`.
4. Let it through the Windows firewall: **Windows Defender Firewall → Advanced
   settings → Inbound Rules → New Rule → Port → TCP 8000 → Allow**.

The other computers open `http://192.168.1.10:8000`. Every user gets their own
login and their own permissions, so the person on the second machine sees only
what you let them see.

This is the arrangement to prefer. Everything stays on one machine: one
database, one set of backups, one place to look when something is wrong.

---

## The database somewhere else

You can point the system at a MySQL server that is not on this machine — another
computer in the shop, or a hosted one.

```ini
DB_HOST=your-database-host
DB_PORT=3306
DB_DATABASE=store_management
DB_USERNAME=store
DB_PASSWORD=a-long-random-password
```

Before you do, three things worth knowing.

**Every page gets slower by the round trip.** A page draws twenty or so queries;
at 40 ms each that is most of a second added to a screen that was instant. On a
local network you will not notice. Over the internet you will, and the sale
screen is the one you use a hundred times a day.

**A hosted database must never be open to the world.** MySQL on port 3306 with a
public address is found by scanners within hours. Either restrict it to your
shop's IP address in the host's firewall, or reach it through a VPN or an SSH
tunnel. Turn TLS on: without it the password and every invoice crosses the
network in the clear.

**The connection becomes a thing that can fail.** The shop cannot sell while the
database is unreachable — no internet, no till. A local database keeps working
during an outage.

If what you actually want is *to see the shop's figures from home*, hosting the
whole thing — application and database together on a small VPS, reached over
HTTPS — is the better shape. Then the round trip happens once per page instead of
once per query, and the database stays private on the same machine.

### Backups when the database is remote

`backup:run` needs `mysqldump` able to reach the host. It is the same tool with
the same credentials, so it works — but check it once, deliberately:

```bash
php artisan backup:check
php artisan backup:run
```

---

## Before you open the shop with it

Work down this list. It is short, and every line on it is something that is
painful to discover later.

**Turn off the developer settings.** In `.env`:

```ini
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` shows the database password on any error page. Then:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run those three after any change to `.env`, or the change will not be read.

**Change the administrator password**, and give each person their own login
rather than sharing one. The activity log names whoever did a thing, and that is
worth nothing if everyone is `admin`.

**Set `BACKUP_REMOTE_PATH` to somewhere that is not this computer**, and let the
scheduler run so the nightly backup actually happens. On Windows, Task Scheduler,
every minute:

```
C:\xampp\php\php.exe C:\xampp\htdocs\SystemManagment\artisan schedule:run
```

On Linux, `crontab -e`:

```
* * * * * cd /path/to/SystemManagment && php artisan schedule:run >> /dev/null 2>&1
```

**Then prove the backup works by restoring it** into a scratch database. An
untested backup is not a backup. The procedure is in `docs/BACKUP.md`, it takes
five minutes, and it is the difference between having a backup and believing you
have one.

**Enter your shop's real details** in Settings: name, phone, address, currency,
logo, and the low-stock level. They go on every printed invoice.

**Enter your opening stock** — each product with the quantity you hold and what
you paid for it. FIFO has no first layer without it, and every profit figure
afterwards is measured from there.

**Then, when you are ready to start for real:** Settings → *Clear all
transactions*. It removes the practice sales and purchases, keeps your products,
customers and suppliers, takes a backup first, and sets the document numbers back
to 1 so your first real invoice is INV-00001.

---

## When something is wrong

| What you see | What it is |
|---|---|
| `The environment file is invalid` | a backslash in a Windows path in `.env`. Use forward slashes, no quotes. |
| `SQLSTATE[HY000] [2002]` | MySQL is not running, or `DB_HOST`/`DB_PORT` is wrong. |
| A blank white page | `APP_DEBUG=false` hiding an error. Look in `storage/logs/laravel.log`. |
| Styles missing, page unstyled | `npm run build` has not been run since the last update. |
| The logo does not appear | `php artisan storage:link` has not been run. |
| `'mysqldump' is not recognized` | set `MYSQLDUMP_PATH` in `.env`, or run `php artisan backup:check`, which says exactly what it could not find. |
| A change to `.env` does nothing | `php artisan config:cache` again. |

After pulling an update, always:

```bash
composer install
npm install && npm run build
php artisan migrate
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
