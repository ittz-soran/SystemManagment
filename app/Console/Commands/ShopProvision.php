<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A new shop's folder, made from nothing.
 *
 * One codebase, many shops (PANEL_DOC Section 3): two installs differ in
 * exactly four things — the .env, the storage folder, the compiled caches, and
 * the small public folder a domain points at. Everything else is this one
 * shared copy. So setting a customer up is not an upload; it is making those
 * four things and pointing a domain at the last of them.
 *
 * This is the piece the panel's New Customer screen calls, and it is a command
 * rather than a method so that it can be run and read on its own, before there
 * is a panel to call it.
 *
 * What it makes:
 *
 *     shops/<name>/.env                 fresh APP_KEY, this shop's database
 *     shops/<name>/artisan              names the shop, then defers
 *     shops/<name>/bootstrap/app.php    one line, deferring to the shared one
 *     shops/<name>/bootstrap/cache/     this shop's compiled config — never shared
 *     shops/<name>/storage/…            logs, backups, uploads
 *     <public>/index.php                names the shop, then defers
 *     <public>/.htaccess                the grant, copied from the shared public/
 *     <public>/build/                   the compiled assets, copied
 *     <public>/favicon.ico, robots.txt
 *
 * Two of those are easy to leave out and expensive to leave out.
 *
 * `bootstrap/app.php` must exist in the shop even though it holds one line,
 * because `config:cache` and `route:cache` re-require bootstrapPath('app.php')
 * to build a fresh application. Without the file those commands fail loudly,
 * which is the good outcome; the bad one is a shop quietly sharing another
 * shop's compiled config, and compiled config holds the database password.
 * ShopIsolationTest is where that story is written down.
 *
 * `artisan` is not in PANEL_DOC Section 11's list, and is here because Section
 * 8 needs it: the panel asks a shop for its own opinion of itself by running
 * `licence:show`, `migrate:status` and the data check "through the shared
 * codebase with SHOP_HOME set". The shared artisan defines no SHOP_HOME and
 * cannot — it is one file serving every shop. So each shop gets its own entry
 * point, exactly as it gets its own public/index.php, and for the same reason:
 * the entry point is the only thing that knows which shop this is.
 *
 * It refuses to touch a folder that already has anything in it. Rerunning this
 * against a live shop would replace its .env — a new APP_KEY, which is the key
 * that decrypts every staff member's authenticator secret, and a blank licence.
 * On failure it removes only what it made, and nothing else.
 */
class ShopProvision extends Command
{
    protected $signature = 'shop:provision
                            {name : The shop’s short name, used for its folder — e.g. bazaar}
                            {--home= : Its private folder. Defaults to ../shops/<name> beside this codebase.}
                            {--public= : Where the domain points. Defaults to <home>/public.}
                            {--url= : APP_URL — the address the browser actually uses.}
                            {--shop-name= : APP_NAME, shown in the title bar. Defaults to the short name.}
                            {--db-database= : Its database. Defaults to <name>_shop.}
                            {--db-username= : Its database user.}
                            {--db-password= : That user’s password.}
                            {--db-host=127.0.0.1}
                            {--db-port=3306}
                            {--storage-limit= : STORAGE_LIMIT_MB. Empty means no limit.}
                            {--licence= : LICENCE_KEY, the signed string from `licence:issue`.}
                            {--trial : Set the shop up on trial — see below. Without this and without --licence it is read-only.}';

    protected $description = 'Create a new shop’s folder: .env, storage, caches and the public folder';

    /** Everything this run created, newest last, so a failure can be undone. */
    private array $made = [];

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name)) {
            $this->components->error(
                "[{$name}] will not do as a shop name. Lower-case letters, digits and hyphens, ".
                'starting with a letter or digit, up to forty — it becomes a folder name and part of a URL.'
            );

            return self::FAILURE;
        }

        $home = $this->absolute($this->option('home') ?: dirname(base_path()).'/shops/'.$name);
        $public = $this->absolute($this->option('public') ?: $home.'/public');

        /*
         * The public folder gets an allowance the home folder does not.
         *
         * On cPanel the document root is created by the act of creating the
         * subdomain, and that has to happen first — the domain must point
         * somewhere before a shop is built for it. cPanel leaves `cgi-bin`
         * behind in it, and Let's Encrypt puts its challenges in `.well-known`.
         * Neither is a site. Counting them as content refused every shop that
         * had a domain pointed at it, which is every shop.
         *
         * Nothing but this command has any business making the home folder, so
         * that one stays strict.
         */
        foreach (['home' => [$home, []], 'public' => [$public, ['cgi-bin', '.well-known']]] as $label => [$path, $ignoring]) {
            if ($this->occupied($path, $ignoring)) {
                $this->components->error("The {$label} folder [{$path}] already has something in it.");
                $this->line(
                    '  Refusing to write into it. If this is a live shop, provisioning over it would'
                    .PHP_EOL.'  replace its .env — a new APP_KEY, which is what decrypts every staff'
                    .PHP_EOL.'  member’s authenticator secret, and a blank licence.'
                );

                return self::FAILURE;
            }
        }

        $this->components->info("Provisioning [{$name}].");

        try {
            $this->makeShopFolder($home, $name);
            $this->makePublicFolder($public, $home);
        } catch (Throwable $e) {
            $this->components->error('Provisioning failed: '.$e->getMessage());
            $this->rollBack();

            return self::FAILURE;
        }

        $this->report($name, $home, $public);

        return self::SUCCESS;
    }

    private function makeShopFolder(string $home, string $name): void
    {
        // Laravel makes none of these itself and fails at the moment it first
        // needs one, which for `sessions` is the first person to sign in.
        foreach ([
            'bootstrap/cache',
            'storage/app/private',
            'storage/app/public',
            'storage/app/backups',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $directory) {
            $this->directory($home.'/'.$directory);
        }

        $this->file($home.'/.env', $this->env($name), 0600);
        $this->file($home.'/bootstrap/app.php', $this->shopBootstrap($home));
        $this->file($home.'/artisan', $this->shopArtisan($home), 0755);
    }

    private function makePublicFolder(string $public, string $home): void
    {
        $this->directory($public);

        $this->file($public.'/index.php', $this->frontController($home, $public));

        // Copied rather than written out, so the shop's copy is the same file
        // the suite checks (WebRootProtectionTest) and cannot drift from it.
        foreach (['.htaccess', 'favicon.ico', 'robots.txt'] as $file) {
            $this->copy(base_path('public/'.$file), $public.'/'.$file);
        }

        $build = base_path('public/build');

        if (! is_dir($build)) {
            throw new RuntimeException(
                "There are no compiled assets at [{$build}]. Run `npm install && npm run build` here first — "
                .'a shop without them loads no stylesheet at all.'
            );
        }

        $this->copyTree($build, $public.'/build');
    }

    /**
     * The shop's own .env.
     *
     * APP_KEY is generated here and never anywhere else: it is what encrypts
     * the staff's authenticator secrets, so two shops sharing one would let
     * either read the other's, and a shop whose key changes locks its own
     * people out of their way back in.
     */
    private function env(string $name): string
    {
        $shopName = (string) ($this->option('shop-name') ?: Str::title(str_replace('-', ' ', $name)));
        $database = (string) ($this->option('db-database') ?: $name.'_shop');
        $url = (string) ($this->option('url') ?: 'https://'.$name.'.soranstore.com');

        return <<<ENV
        # {$shopName} — provisioned by `shop:provision` on {$this->today()}.
        #
        # This file, the storage folder beside it and the compiled caches are the
        # only things that are this shop's own. Everything else is the shared
        # codebase, one folder that every shop reads.

        APP_NAME="{$shopName}"
        APP_ENV=production
        APP_KEY={$this->key()}
        APP_DEBUG=false
        APP_URL={$url}
        APP_TIMEZONE=Asia/Baghdad

        APP_LOCALE=en
        APP_FALLBACK_LOCALE=en

        # Empty on purpose: the administrator is created by importing the .sql
        # template from `install:sql`, which prints its password once.
        ADMIN_PASSWORD=

        # How much space this shop is allowed. Empty means no limit — set it here
        # on the server and nowhere else, because a limit the buyer can edit is
        # not a limit.
        STORAGE_LIMIT_MB={$this->option('storage-limit')}

        {$this->licenceBlock()}
        LOG_CHANNEL=stack
        LOG_STACK=daily
        LOG_LEVEL=warning

        DB_CONNECTION=mysql
        DB_HOST={$this->option('db-host')}
        DB_PORT={$this->option('db-port')}
        DB_DATABASE={$database}
        DB_USERNAME={$this->option('db-username')}
        DB_PASSWORD={$this->option('db-password')}

        SESSION_DRIVER=file
        SESSION_LIFETIME=120
        SESSION_ENCRYPT=false
        SESSION_PATH=/

        # This shop is on the internet behind Cloudflare, so the cookie must never
        # travel over plain http. Without this, whoever shares the wifi has the till.
        SESSION_SECURE_COOKIE=true

        BROADCAST_CONNECTION=log
        FILESYSTEM_DISK=local
        QUEUE_CONNECTION=sync
        CACHE_STORE=file

        MAIL_MAILER=log
        MAIL_FROM_ADDRESS="hello@example.com"
        MAIL_FROM_NAME="\${APP_NAME}"

        # Section 8b: BACKUP_REMOTE_PATH must be somewhere that is NOT this
        # machine, or a dead disk takes the database and its backups together.
        BACKUP_REMOTE_PATH=
        BACKUP_AT=02:15
        BACKUP_KEEP_DAILY=30
        BACKUP_KEEP_MONTHLY=12

        ENV;
    }

    /**
     * The licence lines, and the trap they exist to avoid.
     *
     * PANEL_DOC Section 6 says a trial "runs `unlicensed` — no key at all —
     * which PROJECT_DOC Section 8f defines as full function with no banner".
     * That is true of a copy with no public key. It is NOT true of this one:
     * config/licence.php carries the seller's public key as its committed
     * default, so licensing is switched on in every install of this codebase,
     * and a shop with an empty LICENCE_KEY is `missing`, not `unlicensed` —
     * which is read-only from its first minute. A trial customer could not
     * record a single sale.
     *
     * Verified rather than reasoned about: a provisioned shop with LICENCE_KEY
     * empty reports state `missing` and allowsWriting() false; the same shop
     * with LICENCE_PUBLIC_KEY blanked in its own .env reports `unlicensed` and
     * allowsWriting() true.
     *
     * So --trial blanks the public key in the shop's .env, which is the only
     * thing that makes Section 6's sentence true. The line it writes says what
     * has to happen when the trial ends, because putting a LICENCE_KEY in
     * without taking this back out would leave the licence unchecked — and an
     * unchecked licence is the same as no licence at all.
     */
    private function licenceBlock(): string
    {
        $licence = (string) $this->option('licence');

        if ($this->option('trial')) {
            return implode("\n", [
                '# On trial — PANEL_DOC Section 6: a trial is a status, not a signed',
                '# licence, and a shop on trial runs `unlicensed`: full function, no',
                '# banner. Blanking the public key here is what makes that true. This',
                "# codebase ships the seller's public key as a committed default, so",
                '# without this line the shop would be `missing` — read-only — from',
                '# its first minute.',
                '#',
                '# WHEN THE TRIAL ENDS: delete this line as well as filling in',
                '# LICENCE_KEY. A key that arrives while the public key is blank is',
                '# never checked, which is the same as having no licence at all.',
                'LICENCE_PUBLIC_KEY=',
                'LICENCE_KEY=',
                'LICENCE_GRACE_DAYS=7',
                'LICENCE_WARN_DAYS=14',
            ]);
        }

        return implode("\n", [
            '# The signed string from `licence:issue`. This codebase carries the',
            "# seller's public key, so until this is filled in the shop is `missing`",
            '# — read-only. Reading, printing, deleting and signing in still work.',
            'LICENCE_KEY='.$licence,
            'LICENCE_GRACE_DAYS=7',
            'LICENCE_WARN_DAYS=14',
        ]);
    }

    /**
     * The shop's bootstrap/app.php — one line, and it has to be here.
     *
     * `config:cache` and `route:cache` re-require bootstrapPath('app.php') to
     * build a fresh application, and this shop's bootstrap path is this folder.
     * Plain require, not require_once: those commands need a NEW application
     * each time, and require_once would hand back the last one.
     */
    private function shopBootstrap(string $home): string
    {
        $shared = base_path('bootstrap/app.php');

        return <<<PHP
        <?php

        /*
         * This shop's bootstrap path, so its compiled config and routes are its
         * own and never another shop's — see PANEL_DOC Section 3, and the failure
         * ShopIsolationTest exists to prevent.
         *
         * SHOP_HOME is already defined by whatever got here: the front controller
         * beside the domain, or the artisan file next to this one.
         */

        return require '{$shared}';

        PHP;
    }

    /**
     * The shop's own artisan.
     *
     * PANEL_DOC Section 8: the panel asks a shop what it thinks of itself by
     * running its commands "through the shared codebase with SHOP_HOME set".
     * This is where SHOP_HOME gets set for the command line — the shared artisan
     * serves every shop and so can name none of them.
     *
     *     php /home/soransto/shops/bazaar/artisan licence:show
     */
    private function shopArtisan(string $home): string
    {
        $base = base_path();
        $public = $this->absolute($this->option('public') ?: $home.'/public');

        return <<<PHP
        #!/usr/bin/env php
        <?php

        /*
         * This shop, run from the command line against the shared codebase.
         *
         * SHOP_PUBLIC as well as SHOP_HOME, because a command that builds a URL
         * or writes into the public folder has to reach the right one — this
         * hosting cannot put a document root outside public_html, so a shop's
         * public folder is usually nowhere near its private one.
         */

        define('SHOP_HOME', '{$home}');
        define('SHOP_PUBLIC', '{$public}');
        define('LARAVEL_START', microtime(true));

        require '{$base}/vendor/autoload.php';

        \$app = require '{$base}/bootstrap/app.php';

        exit(\$app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));

        PHP;
    }

    /** The six files the domain points at start here — PANEL_DOC Section 3. */
    private function frontController(string $home, string $public): string
    {
        $base = base_path();

        return <<<PHP
        <?php

        /*
         * {$this->argument('name')} — the only file on the web that is this shop's own.
         *
         * It names the shop and then hands over to the shared codebase, which is
         * one folder every shop reads. SHOP_PUBLIC is this folder, because on this
         * hosting a document root cannot leave public_html, so the shop's public
         * folder sits apart from its private one.
         */

        define('SHOP_HOME', '{$home}');
        define('SHOP_PUBLIC', __DIR__);
        define('LARAVEL_START', microtime(true));

        if (file_exists(\$maintenance = SHOP_HOME.'/storage/framework/maintenance.php')) {
            require \$maintenance;
        }

        require '{$base}/vendor/autoload.php';

        \$app = require '{$base}/bootstrap/app.php';

        \$app->handleRequest(Illuminate\Http\Request::capture());

        PHP;
    }

    private function report(string $name, string $home, string $public): void
    {
        $this->newLine();
        $this->components->info("[{$name}] is provisioned.");

        $this->components->twoColumnDetail('Its own files', $home);
        $this->components->twoColumnDetail('What the domain points at', $public);
        $this->components->twoColumnDetail('Shared codebase', base_path());

        $this->newLine();

        if ($this->option('trial')) {
            $this->components->info('On trial: it runs unlicensed — full function, no banner.');
            $this->line('  When the trial ends, delete the empty LICENCE_PUBLIC_KEY line as well as');
            $this->line('  filling in LICENCE_KEY, or the licence is never checked.');
        } elseif ($this->option('licence')) {
            $this->components->info('A licence was written. Check it took: '.PHP_EOL."    php {$home}/artisan licence:show");
        } else {
            $this->components->warn('No licence and no --trial, so this shop is READ-ONLY until one arrives.');
            $this->line('  Reading, printing, deleting and signing in still work; nothing new can be saved.');
            $this->line('  That is the `missing` state, not `unlicensed` — this codebase ships a public key.');
        }

        $this->newLine();
        $this->line('  Still to do, and none of it is done here:');
        $this->line('    1. Create the database and its user (the panel does this through cPanel UAPI).');
        $this->line('    2. Fill in DB_USERNAME and DB_PASSWORD in the .env if they were not passed.');
        $this->line('    3. Import the .sql template from `install:sql` — that makes the administrator.');
        $this->line('    4. Point the subdomain at the public folder above.');
        $this->newLine();
        $this->line('  Then ask the shop what it thinks of itself:');
        $this->line("    php {$home}/artisan licence:show");
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    /** The same shape `key:generate` writes. */
    private function key(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * Whether a path holds anything at all.
     *
     * A folder that exists but is empty is fine — cPanel makes one the moment a
     * subdomain is added, and refusing that would mean refusing every shop set
     * up the ordinary way round.
     */
    /** @param  list<string>  $ignoring  entries that are not content */
    private function occupied(string $path, array $ignoring = []): bool
    {
        if (! is_dir($path)) {
            return file_exists($path);
        }

        return (bool) array_diff((array) scandir($path), ['.', '..', ...$ignoring]);
    }

    private function absolute(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)
            ? $path
            : rtrim(getcwd(), '/').'/'.$path;
    }

    /**
     * Make a folder, and remember every level of it this run had to create.
     *
     * One level at a time rather than mkdir(recursive), because recursive
     * makes the parents silently and there is then no record that this run was
     * the one that made them. Rollback left `shops/<name>` standing for exactly
     * that reason — a folder holding nothing, looking like a provisioned shop.
     */
    private function directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        $missing = [];

        for ($level = $path; ! is_dir($level) && $level !== dirname($level); $level = dirname($level)) {
            $missing[] = $level;
        }

        foreach (array_reverse($missing) as $level) {
            if (! mkdir($level, 0755) && ! is_dir($level)) {
                throw new RuntimeException("Could not make [{$level}].");
            }

            $this->made[] = $level;
        }
    }

    private function file(string $path, string $contents, int $mode = 0644): void
    {
        $this->directory(dirname($path));

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write [{$path}].");
        }

        chmod($path, $mode);
        $this->made[] = $path;
    }

    private function copy(string $from, string $to): void
    {
        if (! is_file($from)) {
            throw new RuntimeException("There is no [{$from}] to copy.");
        }

        $this->directory(dirname($to));

        if (! copy($from, $to)) {
            throw new RuntimeException("Could not copy [{$from}] to [{$to}].");
        }

        $this->made[] = $to;
    }

    private function copyTree(string $from, string $to): void
    {
        $this->directory($to);

        foreach (scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($from.'/'.$entry)
                ? $this->copyTree($from.'/'.$entry, $to.'/'.$entry)
                : $this->copy($from.'/'.$entry, $to.'/'.$entry);
        }
    }

    /**
     * Undo this run, and only this run.
     *
     * Newest first, so folders are empty by the time they are reached, and each
     * one is removed only if it is empty — a folder that already existed and
     * held something else is never touched. A half-made shop is worse than none:
     * it looks provisioned.
     */
    private function rollBack(): void
    {
        foreach (array_reverse($this->made) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path) && ! $this->occupied($path)) {
                @rmdir($path);
            }
        }

        $this->components->warn('Everything this run had made was removed again.');
    }
}
