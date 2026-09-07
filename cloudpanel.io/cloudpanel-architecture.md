# CloudPanel Architecture — Complete Reference

> **Source of this document:** reverse-engineered from a **live CloudPanel install** on this
> server (Ubuntu 24.04.4 LTS, ~30 sites), by reading the running configuration *and* the
> CloudPanel application source at `/home/clp/htdocs/app/files/src/`.
> Every path, template and formula below was verified against the actual machine.
>
> **Note on customisation:** this particular server has local modifications layered on top of
> stock CloudPanel (marked **[LOCAL]** throughout). Anything not marked is stock behaviour.
>
> Document generated: 2026-09-06

> **How to read this document.** This is a **review and reference**, not a work order.
> Nothing on this server has been changed to produce it — every observation comes from
> read-only inspection. Where a finding or a "fix" is described, treat it as *analysis of how
> the system behaves and what a different design would do*, recorded so you can make your own
> decisions when building your own tooling. Nothing here needs to be acted on.

> **Series:** a condensed standalone overview of all four documents is in
> `cloudpanel-summary.md` — start there if you want the whole picture in one read.


---

## Table of Contents

1. [Design Philosophy](#1-design-philosophy)
2. [Component Map](#2-component-map)
3. [The Control Plane](#3-the-control-plane)
4. [Identity & Isolation Model](#4-identity--isolation-model)
5. [Per-Vhost Filesystem Layout](#5-per-vhost-filesystem-layout)
6. [Site Creation: Exact Sequence](#6-site-creation-exact-sequence)
7. [Nginx Architecture](#7-nginx-architecture)
8. [PHP-FPM Architecture](#8-php-fpm-architecture)
9. [PHP CLI Architecture](#9-php-cli-architecture)
10. [SSL/TLS Management](#10-ssltls-management)
11. [Cron, Logrotate & Logs](#11-cron-logrotate--logs)
12. [Varnish Cache Integration](#12-varnish-cache-integration)
13. [Site Deletion: Teardown Order](#13-site-deletion-teardown-order)
14. [Databases, FTP & SSH Users](#14-databases-ftp--ssh-users)
15. [Security Model & Attack Surface](#15-security-model--attack-surface)
16. [Blueprint: Building Your Own](#16-blueprint-building-your-own)
17. [Gotchas & Observations](#17-gotchas--observations)

---

## 1. Design Philosophy

CloudPanel's core architectural decision is:

> **One Linux user per site. Everything else follows from that.**

This single choice cascades into the entire design:

| Concern | How it is solved |
|---|---|
| File isolation | Site files live in `/home/<siteUser>/`, mode `770`, owned `siteUser:siteUser` |
| Process isolation | Each site gets its **own PHP-FPM pool** running as `siteUser` |
| Shell isolation | `siteUser` has `/bin/bash` and can SSH in; sees only its own home |
| Log isolation | Logs written into `/home/<siteUser>/logs/` |
| Cron isolation | `/etc/cron.d/<siteUser>`, jobs run as `siteUser` |
| Cleanup | Delete the user → home directory and everything in it goes with it |

Three further principles:

1. **The database is the source of truth, the filesystem is a projection.**
   CloudPanel stores site definitions in a SQLite DB and *generates* nginx vhosts, FPM pools,
   logrotate files and cron files from it. Config files are outputs, not inputs.

2. **Templates + placeholder processors.** Vhost configs are not string-concatenated. A stored
   template contains `{{placeholders}}`; a chain of processor classes each own exactly one
   placeholder and substitute it. Unreplaced placeholders are stripped at the end.

3. **Nginx runs as root; PHP does not.** Nginx is a single root master with root workers
   (`user root;`) so it can read every site's files. Privilege separation happens at the
   **FastCGI boundary**, not in nginx.

---

## 2. Component Map

### 2.1 Processes and services

| Service | Runs as | Purpose |
|---|---|---|
| `nginx.service` | **root** | Public web server (ports 80/443, plus internal 8080) |
| `clp-nginx.service` | root | **Separate** nginx instance serving the CloudPanel UI on **8443** |
| `clp-php-fpm.service` | clp | PHP 8.1 FPM running the CloudPanel Symfony app itself |
| `clp-agent.service` | root | Go daemon (scheduler) — monitoring, LE renewal, backups |
| `php<VER>-fpm.service` | root master → per-pool users | One systemd unit **per PHP version**, many pools inside |
| `mysql.service` | mysql | Percona Server |
| `varnish` | varnish | Optional per-site HTTP cache on `127.0.0.1:6081` |

Key insight: **the panel and the sites are fully separated.** They use different nginx
instances, different configs, different PHP-FPM masters. Breaking a site vhost cannot take
down the panel, and vice versa.

### 2.2 Port allocation (verified on this host)

```
   80, 443       nginx  — public HTTP/HTTPS (+ QUIC on 443/udp)
   8080          nginx  — internal PHP backend server block (see §7.3)
   8443          clp-nginx — CloudPanel UI
   2299          sshd   [LOCAL] non-standard SSH port
   6081          varnish — cache frontend
   11000-11999   php7.1-fpm pools
   12000-12999   php7.2-fpm pools
   13000-13999   php7.3-fpm pools
   14000-14999   php7.4-fpm pools
   15000-15999   php8.0-fpm pools
   16000-16999   php8.1-fpm pools
   17000-17999   php8.2-fpm pools
   18000-18999   php8.3-fpm pools
   19000-19999   php8.4-fpm pools
   20000-20999   php8.5-fpm pools
```

**The PHP-FPM port formula** — this is the single most important number in the system:

```
base_port(version) = 11000 + (version_index * 1000)
                     where version_index: 7.1=0, 7.2=1, 7.3=2, 7.4=3, 8.0=4,
                                          8.1=5, 8.2=6, 8.3=7, 8.4=8, 8.5=9

base_port + 0     -> the "default" pool (runs as www-data, always present)
base_port + N     -> site pools, allocated sequentially
```

Allocation is **not** stored-and-reserved globally; it is computed at creation time by
scanning the pool directory (see §8.2). This has consequences — see §17.

### 2.3 Firewall (ufw)

```
80/tcp, 443/tcp, 443/udp   ALLOW   (web + QUIC)
8443/tcp                   ALLOW   (panel UI)
2299/tcp                   ALLOW   (SSH) [LOCAL — stock is 22]
```

FPM ports are never exposed: every pool binds `127.0.0.1` and additionally enforces
`listen.allowed_clients = 127.0.0.1`.

---

## 3. The Control Plane

### 3.1 What CloudPanel actually is

A **Symfony 6 application** with Doctrine ORM on **SQLite**.

```
/home/clp/htdocs/app/
├── data/
│   └── db.sq3                 # THE database — sites, certs, users, settings
└── files/                     # the Symfony app
    ├── bin/clpctl             # CLI entrypoint
    ├── composer.json          # symfony/*: 6.0.*, doctrine/orm ^2.9, aws-sdk-php
    ├── config/
    ├── migrations/            # Doctrine migrations — schema is versioned
    ├── public/                # web root served by clp-nginx on :8443
    ├── resources/             # ← TEMPLATES LIVE HERE (skel, logrotate, vhosts)
    ├── src/                   # application code
    ├── templates/             # Twig UI templates
    └── vendor/
```

> The PHP source in `src/` is **obfuscated**: control flow is flattened into `goto` chains
> and all string literals are hex/octal-escaped. It is fully readable after decoding the
> escapes — every code detail in this document was recovered that way.

### 3.2 Database schema (the data model)

`/home/clp/htdocs/app/data/db.sq3`, owned `clp:clp`, mode `0770`.

Core tables:

```sql
site (
  id, type, domain_name, root_directory, user, user_password,
  vhost_template,              -- the RENDERED template text for this site
  application,                 -- e.g. 'Laravel 12', 'Generic'
  certificate_id, php_settings_id, nodejs_settings_id, python_settings_id,
  basic_auth_id,
  page_speed_enabled, page_speed_settings,
  allow_traffic_from_cloudflare_only,
  varnish_cache,
  reverse_proxy_url,
  ssh_keys, created_at, updated_at
)
-- UNIQUE on domain_name AND on user  → 1 site : 1 Linux user, enforced in DB

php_settings (
  site_id UNIQUE, php_version, pool_port,
  memory_limit, max_execution_time, max_input_time, max_input_vars,
  post_max_size, upload_max_file_size, additional_configuration
)

certificate (
  id, site_id, uid, type, expires_at, default_certificate,
  csr, private_key, certificate, certificate_chain
)
-- type: 1 = SELF_SIGNED, 2 = LETS_ENCRYPT, 3 = IMPORTED

vhost_template (id, name, template, root_directory, php_version,
                varnish_cache_settings, type)

cron_job   (site_id, minute, hour, day, month, weekday, command)
ssh_user   (site_id, user_name UNIQUE, ssh_keys)
ftp_user   (site_id, user_name UNIQUE, home_directory)
database   (site_id, database_server_id, name UNIQUE)
basic_auth, blocked_bot, blocked_ip, firewall_rule
user       (panel logins: user_name, email, password, role, mfa, mfa_secret)
config     (key/value: app_version, le_private_key, instance_uid,
            custom_domain, release_channel, timezone, masquerade_address)
```

**Critical design point:** certificates are stored **in the database** (`private_key`,
`certificate`, `certificate_chain` as CLOBs) and *written out* to
`/etc/nginx/ssl-certificates/`. The DB is authoritative; the files are a projection.
This is why CloudPanel backups only need the `.sq3` file plus `/home`.

Equally: `site.vhost_template` stores the **rendered** vhost body for that site. Editing a
vhost through the UI updates this column and re-writes the file.

### 3.3 `clpctl` — the CLI

```bash
/usr/bin/clpctl          # thin bash wrapper, shell-quotes args
  └─ sudo /usr/bin/clpctlWrapper
       └─ /usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini \
            /home/clp/htdocs/app/files/bin/clpctl <args>
```

The sudoers grant is in `/etc/sudoers.d/cloudpanel`:

```
Defaults !mail_badpass
clp ALL=(ALL) NOPASSWD: ALL
ALL ALL=(ALL) NOPASSWD: /usr/bin/clpctlWrapper
```

Note `ALL ALL=... NOPASSWD: /usr/bin/clpctlWrapper` — **every** user on the box may invoke
`clpctlWrapper` as root. `clpctlWrapper` is mode `0700 root:root`, so it can only be reached
through that sudo rule. `clpctl` escapes arguments (`addslashes()` over `` \ ` ( ) $ " ``)
before re-invoking through `bash -c`. This is the panel's main privilege boundary and it is
worth understanding before copying the pattern (see §15).

Available commands (from `src/Command/`):

```
site:add:php | site:add:static | site:add:reverse-proxy
site:add:nodejs | site:add:python | site:delete | site:install:certificate
lets-encrypt:install:certificate
lets-encrypt:renew:certificates
lets-encrypt:renew:custom-domain-certificate
db:add | db:delete | db:import | db:export | db:backup | db:show:master-credentials
user:add | user:delete | user:list | user:reset-password | user:disable:mfa
vhost-template:add | :delete | :view | :list | :import
cloudpanel:enable-basic-auth | :disable-basic-auth | :delete:sites
                             | :set-release-channel
system:permissions:reset
varnish-cache:purge
{aws,do,gce,hetzner,vultr}:snapshot / image create
```

### 3.4 `clp-agent`

`/usr/sbin/clp-agent` — a **Go** binary (not stripped), running as root, using
`github.com/prprprus/scheduler`. It embeds a reference to
`/home/clp/htdocs/app/data/db.sq3` and drives the periodic work: instance monitoring
(the `instance_cpu`, `instance_memory`, `instance_disk_usage`, `instance_load_average`
tables), Let's Encrypt renewals, and scheduled backups.

This is why you will find **no cron entries** for certificate renewal — the agent's internal
scheduler owns it.

---

## 4. Identity & Isolation Model

### 4.1 The site user

Created by `Creator::createUser()`, which builds this command
(`src/System/Command/CreateUserCommand.php`):

```bash
/usr/bin/sudo /usr/sbin/useradd \
  -p "$(sudo cat <tmpfile> | openssl passwd -6 -stdin)" \
  -m \
  '<siteUser>' \
  -k '/home/clp/htdocs/app/files/resources/etc/skel/site-user/' \
  -s '/bin/bash' \
  -d '/home/<siteUser>' \
  [-g <group>] [-G <groups>]
```

Details that matter:

- Password is written to a temp file, `chmod 0400`, then hashed with `openssl passwd -6`
  (SHA-512 crypt) — the plaintext never appears in the process argument list.
- `-m` creates the home directory, `-k` seeds it from the **skeleton** (§5.2).
- Shell is `/bin/bash` — **site users can log in over SSH**.
- The username is **supplied by the operator** (`--siteUser=`), it is *not* derived from
  the domain. On this host the convention used is `<project>-<subdomain>`
  (`lv.cijagani.in` → `cijagani-lv`), but that is a human convention, not code.

### 4.2 Groups

```
cijagani-lv:x:1014:www-data      # per-site primary group, with www-data as a MEMBER
www-data:x:33:                   # www-data's own group has no members
```

Read that carefully — it is the reverse of what most people assume. Each site's group
contains `www-data` as a secondary member. This lets the `www-data`-owned "default" FPM pool
and other shared tooling traverse into site directories when needed, while the site user's
own processes remain confined.

### 4.3 Permissions

`Creator::resetPermissions()` applies:

| Path | Owner | Dir mode | File mode |
|---|---|---|---|
| `/home/<siteUser>` (recursive) | `siteUser:siteUser` | `770` | `770` |
| `/home/<siteUser>/.ssh` | `siteUser:siteUser` | `700` | `600` |

And `/home` itself is `drwx-----x root:root` (`0711`) — traversable but **not listable**.
A site user cannot enumerate the other tenants on the box.

`umask 007` is set in every site user's `~/.bashrc`, so newly created files inherit
group-rw and are world-inaccessible.

### 4.4 The isolation boundary — and its limits

What **is** isolated:

- ✅ Filesystem: `770` + `/home` mode `0711` means user A cannot read user B's files.
- ✅ PHP execution: each site's FPM pool runs as its own user. A PHP RCE in site A
  cannot read site B's `.env`.
- ✅ Logs, cron, tmp, backups: all per-user.

What is **not** isolated (important — this is *not* containerisation):

- ❌ **The kernel and process table.** It is one shared OS. `ps aux` shows everything.
- ❌ **Nginx.** It runs as `root` and reads every site's files.
- ❌ **PHP CLI version** — global, see §9.
- ❌ **Resource limits.** No cgroup/quota per site by default; `pm.max_children`
  is the only real throttle, and memory/CPU are shared.
- ❌ **MySQL.** One shared server; separation is by DB grants only.
- ❌ **Loopback network.** Any site user can connect to `127.0.0.1:<any FPM port>`.
  `listen.allowed_clients = 127.0.0.1` does not stop a local user — it only stops remote
  hosts. A site user who knows another site's pool port can speak FastCGI to it directly.
  This is a genuine gap in the model; see §15.

---

## 5. Per-Vhost Filesystem Layout

### 5.1 The complete inventory — what exists after creating one PHP site

Creating a site called `example.com` with user `myuser` and PHP 8.3 produces
**exactly** the following. This is the answer to "what files and folders are created".

```
── PER-SITE, INSIDE THE HOME DIRECTORY ────────────────────────────────────────
/home/myuser/                                   drwxrwx---  myuser:myuser
├── .bashrc                                     (from skel) sources /etc/bashrc/bashrc,
│                                                            ~/.python_version, umask 007
├── .profile                                    (from skel) sources ~/.bashrc
├── .ssh/                                       drwx------ 700
│   ├── authorized_keys                         600, empty
│   └── config                                  600, empty
├── htdocs/                                     ← ALL WEB CONTENT
│   ├── .gitignore
│   └── example.com/                            ← created by createRootDirectory()
│       └── index.php                           ← "<?php\n\necho 'Hello World :-)';"
├── logs/
│   ├── .gitignore
│   ├── nginx/
│   │   ├── access.log                          ← nginx writes here
│   │   └── error.log
│   └── php/
│       └── error.log                           ← PHP writes here (via PHP_VALUE)
├── backups/
│   ├── .gitignore
│   └── databases/
│       └── .gitignore
└── tmp/                                        ← also used for pagespeed_cache
    └── .gitignore

── IF VARNISH CACHE IS ENABLED, ADDITIONALLY ──────────────────────────────────
/home/myuser/.varnish-cache/
├── settings.json                               {enabled, server, cacheTagPrefix,
│                                                cacheLifetime, excludes, excludedParams}
└── controller.php                              copied from resources/varnish-cache/
                                                controller/{generic|wordpress}/
/home/myuser/logs/varnish-cache/
└── purge.log

── SYSTEM-WIDE FILES CREATED OUTSIDE THE HOME ─────────────────────────────────
/etc/nginx/sites-enabled/example.com.conf       ← the vhost      (root:root 644)
/etc/nginx/ssl-certificates/example.com.crt     ← certificate    (root:root 644)
/etc/nginx/ssl-certificates/example.com.key     ← private key    (root:root 644)  ⚠ see §15
/etc/php/8.3/fpm/pool.d/example.com.conf        ← FPM pool       (root:root 644)
/etc/logrotate.d/myuser                         ← log rotation

── CREATED ONLY WHEN THE FEATURE IS USED ──────────────────────────────────────
/etc/cron.d/myuser                              ← if any cron job is defined
/etc/nginx/basic-auth/example.com               ← if HTTP basic auth is enabled
/home/myuser/htdocs/example.com/.well-known/acme-challenge/<token>
                                                ← transient, during LE issuance only

── ROWS INSERTED IN THE DATABASE ──────────────────────────────────────────────
site, php_settings, certificate  (self-signed at creation)
```

**Naming conventions to internalise:**

| Artifact | Named after |
|---|---|
| nginx vhost file | **domain name** — `<domain>.conf` |
| SSL cert/key files | **domain name** — `<domain>.crt` / `.key` |
| FPM pool file *and* pool name | **domain name** — `<domain>.conf`, `[<domain>]` |
| basic-auth file | **domain name** |
| logrotate file | **site user** |
| cron file | **site user** |
| home directory | **site user** |

Mixing these two keys (domain vs. user) is deliberate: system-level resources that must be
unique per *tenant* use the user; per-*hostname* resources use the domain.

### 5.2 The skeleton directory

`/home/clp/htdocs/app/files/resources/etc/skel/site-user/`

```
.bashrc
.profile
.ssh/authorized_keys        (empty)
.ssh/config                 (empty)
htdocs/.gitignore
logs/.gitignore
logs/nginx/access.log       (empty)
logs/nginx/error.log        (empty)
logs/php/error.log          (empty)
backups/.gitignore
backups/databases/.gitignore
tmp/.gitignore
```

The empty log files are pre-created so nginx and PHP-FPM never have to create them as root
in a user-owned directory. The `.gitignore` files exist purely to make otherwise-empty
directories survive being packaged in git.

There is a **second** skeleton, `resources/etc/skel/ssh-user/`, for additional SSH users
(§14.3) — it has no `htdocs`/`logs`/`backups`, because those get **symlinked** in instead.

The site-user `.bashrc`:

```bash
# .bashrc
if [ -f /etc/bashrc/bashrc ]; then
  . /etc/bashrc/bashrc
fi
if [ -f ~/.python_version  ]; then
  . ~/.python_version
fi
# User specific aliases and functions
umask 007
```

`/etc/bashrc/bashrc` is CloudPanel's shared shell profile — it sets `LC_ALL`/`LANG`,
`EDITOR=nano`, history behaviour, and **loads NVM** from `$HOME/.nvm`. That is how per-site
Node.js versions work: NVM per home directory, not a system package.

---

## 6. Site Creation: Exact Sequence

Recovered from `src/Command/SiteAddPhpCommand.php` +
`src/Site/Creator.php` + `src/Site/Creator/PhpSite.php`.

```bash
clpctl site:add:php \
  --domainName=example.com \
  --phpVersion=8.3 \
  --vhostTemplate='Generic' \
  --siteUser=myuser \
  --siteUserPassword='!secret!'
```

### Step-by-step

```
 1. PARSE DOMAIN
    domainName -> lowercase, trimmed
    resolveDomainName() via jeremykendall/php-domain-parser (PSL-aware)
      -> registrableDomain  (e.g. "cijagani.in")
      -> subdomain          (e.g. "lv", or null)
    Subject Alternative Names:
      if subdomain is null or "www"  -> SANs = [domain, www.<registrableDomain>]
      else                           -> SANs = [domain]

 2. LOAD VHOST TEMPLATE
    SELECT * FROM vhost_template WHERE name = '<vhostTemplate>'
    root_directory  <- template's root_directory suffix (e.g. "public")
    site.root_directory = "<domainName>/<suffix>"      e.g. "example.com/public"
    varnish_cache_settings <- template's JSON, if any

 3. GENERATE A SELF-SIGNED CERTIFICATE  (so HTTPS works from second zero)
    RsaKeyGenerator -> private key
    CsrGenerator(privateKey, DistinguishedName(commonName, SANs)) -> CSR
    Openssl::createSelfSignedCertificate(privateKey, csr)
    -> certificate row, type = TYPE_SELF_SIGNED (1)

 4. VALIDATE + PERSIST ENTITIES
    Symfony Validator over site + php_settings entities
    INSERT site, php_settings, certificate

 5. RENDER THE VHOST
    PhpVhostTemplate(site) with processor chain (see §7.2)
    build() -> substitute every {{placeholder}}
    removeEmptyPlaceholders() -> strip any that had no processor
    site.vhost_template = rendered text

 ── FILESYSTEM WORK BEGINS (PhpSiteCreator) ──────────────────────────────────

 6. createUser()
       useradd -m -k <skel/site-user> -s /bin/bash -d /home/myuser myuser
       (password SHA-512 crypt via openssl passwd -6)

 7. createRootDirectory()
       mkdir -p /home/myuser/htdocs/example.com/public

 8. createIndexPhp()
       write /home/myuser/htdocs/example.com/public/index.php
       content: <?php\n\necho 'Hello World :-)';

 9. createPhpFpmPool()                                  ← see §8.2 for port logic
       scan /etc/php/8.3/fpm/pool.d/  (ignoring global.conf)
       port = highest_existing_port + 1
       php_settings.pool_port = port
       write /etc/php/8.3/fpm/pool.d/example.com.conf

10. createPrivateKeyAndCertificate()
       write /etc/nginx/ssl-certificates/example.com.key
       write /etc/nginx/ssl-certificates/example.com.crt

11. createLogrotateFile()
       read resources/etc/logrotate/template
       replace {{user}} / {{group}}
       write /etc/logrotate.d/myuser

12. [if varnish] createVarnishCacheStructure()
       mkdir /home/myuser/.varnish-cache/
       mkdir /home/myuser/logs/varnish-cache/
       write settings.json  (the template's JSON, minus the "controller" key)
       copy resources/varnish-cache/controller/<controller>/controller.php
       touch purge.log

13. resetPermissions()
       chown -R myuser:myuser /home/myuser
       find /home/myuser -type d -exec chmod 770
       find /home/myuser -type f -exec chmod 770
       find /home/myuser/.ssh -type d -exec chmod 700
       find /home/myuser/.ssh -type f -exec chmod 600

14. createNginxVhost()
       write /etc/nginx/sites-enabled/example.com.conf

15. reloadPhpFpmService()   -> systemctl reload php8.3-fpm
16. reloadNginxService()    -> systemctl reload nginx
```

Note the ordering discipline: **permissions are reset before the vhost is written**, and the
vhost is written *last* among file operations, so nginx never reloads into a half-built site.
Both service reloads are skipped entirely when `APP_ENV == "dev"`.

---

## 7. Nginx Architecture

### 7.1 Directory layout

```
/etc/nginx/
├── nginx.conf                  # main config; includes sites-enabled/*.conf
├── global_settings             # shared security headers, included by each vhost
├── sites-enabled/              # ← ALL VHOSTS LIVE HERE, ONE FILE PER DOMAIN
│   ├── default.conf            # catch-all, returns 444
│   ├── example.com.conf
│   └── ...
├── sites-available/            # ← EMPTY. CloudPanel does not use the
│                               #   available/enabled symlink pattern at all.
├── ssl-certificates/           # <domain>.crt + <domain>.key per site
├── ssl/dhparams.pem
├── basic-auth/<domain>         # htpasswd files, only if basic auth enabled
├── cloudflare/ips              # "allow <cidr>;" lines, for CF-only mode
├── blocked_ips                 # global IP blocklist, included in http{}
├── geoip/                      # GeoIP.dat, GeoLiteCity.dat
├── conf.d/                     # [LOCAL] custom maps and rate-limit zones
└── modules-enabled/
```

**Design note:** CloudPanel writes directly into `sites-enabled/` and leaves
`sites-available/` empty. Enable/disable is not a supported concept — a site either exists
or it does not. If you build your own system, consider whether you want that.

### 7.2 The template engine

This is the most reusable idea in CloudPanel. Located in
`src/Site/Nginx/Vhost/`.

A **template** is text containing `{{placeholder}}` tokens. A **processor** is a class that
owns exactly one placeholder and knows how to render it from the site entity.

```
Template::build()
  1. getPlaceholders()  -> regex /[{{]{2}([\sa-zA-Z0-9_]+)[}}]{2}/ over the content
  2. for each placeholder that has a registered processor:
         processor->setSite(site)
         content = processor->process(content)
         remove placeholder from the pending list
  3. removeEmptyPlaceholders() -> str_replace remaining tokens with ''
```

**Base processors** (registered for every site type, in `Template::init()`):

| Placeholder | Class | Renders |
|---|---|---|
| `{{root}}` | RootDirectory | `root /home/<user>/htdocs/<root_directory>;` |
| `{{server_name}}` | ServerName | `server_name <names>;` (see below) |
| `{{redirect_server_name}}` | RedirectServerName | server_name for the redirect block |
| `{{redirect_domain}}` | RedirectDomain | the redirect target |
| `{{nginx_access_log}}` | NginxAccessLog | `access_log /home/<user>/logs/nginx/access.log <fmt>;` |
| `{{nginx_error_log}}` | NginxErrorLog | `error_log /home/<user>/logs/nginx/error.log;` |
| `{{ssl_certificate_key}}` | SslCertificateKey | `ssl_certificate_key /etc/nginx/ssl-certificates/<domain>.key;` |
| `{{ssl_certificate}}` | SslCertificate | `ssl_certificate /etc/nginx/ssl-certificates/<domain>.crt;` |
| `{{settings}}` | Settings | composite block — see below |

**PHP-only processors** (added in `PhpTemplate::init()`):

| Placeholder | Class | Renders |
|---|---|---|
| `{{php_fpm_port}}` | PhpFpmPort | the integer `php_settings.pool_port` |
| `{{php_settings}}` | PhpSettings | the `PHP_VALUE` ini block — see §8.3 |
| `{{php_error_log}}` | PhpErrorLog | `/home/<user>/logs/php/error.log` |
| `{{varnish_proxy_pass}}` | VarnishProxyPass | `proxy_pass http://...;` — see §12 |

Other site types add: `{{nodejs_app_port}}`, `{{python_app_port}}`, `{{reverse_proxy_url}}`.

#### ServerName logic

```
if subdomain is null:               names = [registrableDomain]
else:                               names = ["<subdomain>.<registrable>"]
if subdomain is null OR == "www":   names += ["www1.<registrableDomain>"]
```

(The literal `www1.` is what the code emits — an apparent CloudPanel quirk/typo, present in
the shipped build on this host.)

#### The `{{settings}}` composite

`Processor/Settings.php` assembles, in this order, joined by blank lines:

1. **Basic auth** — if enabled:
   ```nginx
     satisfy any;                    # only when whitelisted IPs exist
     allow <ip>;                     # one per whitelisted IP
     deny all;
     auth_basic "Restricted Area";
     auth_basic_user_file /etc/nginx/basic-auth/<domain>;
   ```
   The htpasswd file is written as `<user>:<crypt(password, base64(password))>` — classic
   DES `crypt()`, salted with the base64 of the password itself. Weak by modern standards.

2. **PageSpeed** — if enabled:
   ```nginx
     pagespeed on;
     pagespeed FileCachePath "/home/<user>/tmp/pagespeed_cache/";
     <each custom setting line>
   ```

3. **Cloudflare-only** — if enabled: `include /etc/nginx/cloudflare/ips;`

4. **Blocked bots** — if any:
   ```nginx
     if ($http_user_agent ~* (bot1|bot2)) { return 444; }
   ```
   (spaces in bot names become `\s`)

5. **Blocked IPs** — if any:
   ```nginx
     if ($remote_addr ~ "^(1.2.3.4|5.6.7.8)$") { return 403; }
   ```
   ...but when Cloudflare-only is on, it tests `$http_cf_connecting_ip` instead.

### 7.3 The two-server-block pattern

This is CloudPanel's signature vhost shape and it surprises people. Here is the stock
**Generic** PHP template, verbatim from the database:

```nginx
server {
  listen 80;
  listen [::]:80;
  listen 443 quic;
  listen 443 ssl;
  listen [::]:443 quic;
  listen [::]:443 ssl;
  http2 on;
  http3 off;
  {{ssl_certificate_key}}
  {{ssl_certificate}}
  {{server_name}}
  {{root}}

  {{nginx_access_log}}
  {{nginx_error_log}}

  if ($scheme != "https") {
    rewrite ^ https://$host$request_uri permanent;
  }

  location ~ /.well-known {
    auth_basic off;
    allow all;
  }

  {{settings}}

  location / {
    {{varnish_proxy_pass}}
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_hide_header X-Varnish;
    proxy_redirect off;
    proxy_max_temp_file_size 0;
    proxy_connect_timeout      720;
    proxy_send_timeout         720;
    proxy_read_timeout         720;
    proxy_buffer_size          128k;
    proxy_buffers              4 256k;
    proxy_busy_buffers_size    256k;
    proxy_temp_file_write_size 256k;
  }

  location ~* ^.+\.(css|js|jpg|jpeg|gif|png|ico|gz|svg|svgz|ttf|otf|woff|woff2|eot|
                    mp4|ogg|ogv|webm|webp|zip|swf|map)$ {
    add_header Access-Control-Allow-Origin "*";
    add_header alt-svc 'h3=":443"; ma=86400';
    expires max;
    access_log off;
  }

  if (-f $request_filename) {
    break;
  }
}

server {
  listen 8080;
  listen [::]:8080;
  {{server_name}}
  {{root}}

  include /etc/nginx/global_settings;

  try_files $uri $uri/ /index.php?$args;
  index index.php index.html;

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_intercept_errors on;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    try_files $uri =404;
    fastcgi_read_timeout 3600;
    fastcgi_send_timeout 3600;
    fastcgi_param HTTPS "on";
    fastcgi_param SERVER_PORT 443;
    fastcgi_pass 127.0.0.1:{{php_fpm_port}};
    fastcgi_param PHP_VALUE "{{php_settings}}";
  }

  if (-f $request_filename) {
    break;
  }
}
```

**Why two blocks?** So that Varnish can be slotted in without rewriting anything:

```
 Varnish DISABLED:                    Varnish ENABLED:

 client                               client
   │ :443                               │ :443
   ▼                                    ▼
 [nginx frontend]                     [nginx frontend]
   │ proxy_pass 127.0.0.1:8080          │ proxy_pass 127.0.0.1:6081
   ▼                                    ▼
 [nginx backend :8080]                [varnish :6081]
   │ fastcgi_pass 127.0.0.1:19003       │ backend -> 127.0.0.1:8080
   ▼                                    ▼
 [php-fpm pool, as siteUser]          [nginx backend :8080]
                                        │ fastcgi_pass 127.0.0.1:19003
                                        ▼
                                      [php-fpm pool, as siteUser]
```

The frontend block terminates TLS, handles static files, applies security rules. The backend
block on `:8080` is plain HTTP, does the `try_files` routing and speaks FastCGI. Both blocks
carry the same `server_name`, so nginx's virtual-host resolution picks the right backend.

Consequences to be aware of:

- `fastcgi_param HTTPS "on"` and `SERVER_PORT 443` are **hardcoded** in the backend block —
  PHP always believes it is on HTTPS, because the frontend always redirects HTTP→HTTPS anyway.
- Port `8080` is bound on `0.0.0.0`, not `127.0.0.1`. It is not in the ufw allowlist, so it
  is not externally reachable, but that is firewall-dependent rather than bind-dependent.
- Static assets are served by the **frontend** block (the `if (-f $request_filename) break;`
  plus the extensions `location`), so they never traverse the proxy hop.

### 7.4 Other site types

- **Static** — one server block, no `:8080` backend, no FPM pool, no PHP.
- **Reverse proxy** — `{{reverse_proxy_url}}` in a `location @reverse_proxy` named location,
  reached via `try_files $uri @reverse_proxy;`. Includes WebSocket upgrade headers
  (`Upgrade`/`Connection`), `proxy_ssl_server_name on`, and 900s timeouts. No PHP-FPM pool
  and no `:8080` block. `reverse_proxy_url` is stored on the `site` row.
- **Node.js** — `{{nodejs_app_port}}`; the app is run by the site user (via NVM) and nginx
  proxies to it. `nodejs_settings` table holds the version and port.
- **Python** — `{{python_app_port}}`, `python_settings` table; `~/.python_version` is sourced
  by the site user's `.bashrc`.

### 7.5 Global nginx config highlights

Stock, from `/etc/nginx/nginx.conf`:

```nginx
user root;                                # ← nginx workers run as root
worker_processes auto;
worker_rlimit_nofile 1048576;
events { worker_connections 65535; use epoll; multi_accept on; }

http {
  server_tokens off;
  port_in_redirect off;
  disable_symlinks if_not_owner from=/home/;   # ← anti-symlink-escape, important
  client_max_body_size 64M;

  geoip_country /etc/nginx/geoip/GeoIP.dat;
  geoip_city    /etc/nginx/geoip/GeoLiteCity.dat;

  real_ip_recursive on;
  real_ip_header    CF-Connecting-IP;
  set_real_ip_from  <cloudflare ranges...>;

  log_format main       '$remote_addr - $remote_user [$time_local] ...';
  log_format cloudflare '$http_cf_connecting_ip - $remote_user ...';

  ssl_protocols TLSv1.2 TLSv1.3;
  ssl_ciphers EECDH+AESGCM:EDH+AESGCM;
  ssl_prefer_server_ciphers on;
  ssl_session_tickets off;
  ssl_stapling on;  ssl_stapling_verify on;
  ssl_dhparam /etc/nginx/ssl/dhparams.pem;

  gzip on;    gzip_types <list>;
  brotli on;  brotli_static on;  brotli_types <list>;
  pagespeed off;

  include /etc/nginx/blocked_ips;
  include /etc/nginx/sites-enabled/*.conf;
}
```

`disable_symlinks if_not_owner from=/home/` is the quiet hero here: it stops a site user from
symlinking `/home/other-user/htdocs/.env` into their own webroot and having root-nginx
happily serve it.

`/etc/nginx/global_settings` (included by the `:8080` backend block):

```nginx
#add_header Strict-Transport-Security 'max-age=31536000; includeSubDomains; preload';
add_header X-Frame-Options SAMEORIGIN;
add_header X-Content-Type-Options nosniff;
add_header X-XSS-Protection "1; mode=block";
#add_header Content-Security-Policy "img-src 'self' data:;";
add_header X-Permitted-Cross-Domain-Policies master-only;
add_header Referrer-Policy same-origin;
add_header alt-svc 'h3=":443"; ma=86400';
```

The catch-all, `sites-enabled/default.conf`:

```nginx
server {
  listen 80 default_server;
  listen [::]:80 default_server;
  listen 443 quic reuseport default_server;
  listen 443 default_server ssl;
  listen [::]:443 quic reuseport default_server;
  listen [::]:443 default_server ssl;
  ssl_reject_handshake on;
  server_name _;
  return 444;
}
```

Unknown `Host` headers get their TLS handshake rejected outright and plain HTTP gets 444
(connection closed, no response). Note `reuseport` lives here — it must appear on exactly one
`listen` per address:port, which is why the default vhost owns it.

**[LOCAL] customisations on this host** — `/etc/nginx/conf.d/` contains four files that are
*not* stock CloudPanel:
- `bad-bots.conf` — `map $http_user_agent $is_bad_bot` (scanners, aggressive SEO crawlers)
  and a `$is_good_bot` override map. Requires `if ($is_bad_bot) { return 403; }` in a server
  block to take effect.
- `rate-limits.conf` — named `limit_req_zone`s: `web_general` 30r/s, `php_requests` 10r/s,
  `auth_strict` 1r/s, `api_zone` 20r/s, `webhooks` 100r/s, `static_assets` 50r/s, plus
  `limit_conn_zone conn_per_ip`. `limit_req_status 429`.
- `security-headers.conf` — an opt-in header bundle (must be `include`d per server block).
- `cloudflare-ips.conf` — a second copy of the CF ranges.

Also **[LOCAL]**: `nginx.conf` here has tuned `open_file_cache`, `keepalive_requests 10000`,
`gzip_comp_level 5` (down from 8), a `429` error page, and `access_log off` at http level.

---

## 8. PHP-FPM Architecture

### 8.1 Directory layout

```
/etc/php/
├── 7.1/ 7.2/ 7.3/ 7.4/ 8.0/ 8.1/ 8.2/ 8.3/ 8.4/ 8.5/
│   ├── cli/
│   │   ├── php.ini             # CLI ini for this version
│   │   └── conf.d/ -> ../../mods-available symlinks
│   ├── fpm/
│   │   ├── php.ini             # FPM ini for this version
│   │   ├── php-fpm.conf        # master config, includes pool.d/*.conf
│   │   ├── conf.d/
│   │   └── pool.d/
│   │       ├── global.conf     # [global] section (IGNORED by the pool scanner)
│   │       ├── default.conf    # base_port + 0, runs as www-data
│   │       ├── www.conf        # stock Debian pool (unused, kept for reference)
│   │       └── <domain>.conf   # ← ONE FILE PER SITE
│   └── mods-available/
```

All ten PHP versions are installed **simultaneously**, each with its own systemd unit
(`php8.3-fpm.service` etc.). A site selects a version; the pool file goes into that version's
`pool.d/`; only that version's service is reloaded.

`global.conf` (per version):

```ini
[global]
emergency_restart_threshold = 10
emergency_restart_interval = 1m
process_control_timeout = 10s
rlimit_files = 1048576
rlimit_core = 0
```

### 8.2 Pool creation and port allocation

From `Creator/PhpSite::createPhpFpmPool()`:

```php
poolDirectory = "/etc/php/{$phpVersion}/fpm/pool.d/"
pools         = PoolReader(poolDirectory)->getPools()   // parses every *.conf,
                                                        // skipping only "global.conf"
usort(pools, fn($a,$b) => $a->getPort() < $b->getPort())
latestPool    = array_shift(pools)
poolPort      = latestPool->getPort() + 1

phpSettings->setPoolPort(poolPort)
poolFile      = "/etc/php/{$phpVersion}/fpm/pool.d/{$domainName}.conf"
write(poolFile, PoolBuilder->create(pool))
```

So: **the next port is `max(existing ports in this version's pool.d) + 1`.** Ports are never
reserved centrally and never reused from gaps. `default.conf` at `base_port + 0` guarantees
there is always at least one pool to seed from.

> ⚠️ The comparator `fn($a,$b) => $a->getPort() < $b->getPort()` returns a **bool**, not the
> `-1/0/1` that `usort` expects. PHP coerces `true`→1 and `false`→0, so it never returns a
> negative number. The sort is therefore not a correct descending sort in general. In
> practice `array_shift` still tends to land on a high port and the `+1` collides rarely —
> but if you reimplement this, **use a real comparator** (`$b->getPort() <=> $a->getPort()`)
> or, better, allocate ports from the database inside a transaction.

### 8.3 The pool template

`src/Site/PhpFpm/PoolBuilder.php` — the shipped template:

```ini
[{{name}}]
listen = 127.0.0.1:{{port}}
user = {{user}}
group = {{group}}
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 250
pm.process_idle_timeout = 10s
pm.max_requests = 100
listen.backlog = 65535
pm.status_path = /status
request_terminate_timeout = 7200s
rlimit_files = 131072
rlimit_core = unlimited
catch_workers_output = yes
```

Substitutions: `{{name}}` = domain name, `{{port}}` = allocated port,
`{{user}}` = `{{group}}` = site user.

An **actual pool file on this host** (`/etc/php/8.2/fpm/pool.d/help.cijagani.in.conf`):

```ini
[help.cijagani.in]
listen = 127.0.0.1:17002
user = cijagani-help
group = cijagani-help
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 15
pm.process_idle_timeout = 30s
pm.max_requests = 500
listen.backlog = 65535
pm.status_path = /status
request_terminate_timeout = 60s
rlimit_files = 1048576
rlimit_core = unlimited
catch_workers_output = yes
```

**[LOCAL]** — compare the two: `max_children` 250→15, `idle_timeout` 10s→30s,
`max_requests` 100→500, `request_terminate_timeout` 7200s→60s, `rlimit_files` 131072→1048576.
These were tuned after creation. **CloudPanel never rewrites an existing pool file's tuning**
— it only writes the file at creation and deletes it at teardown — so hand-edits survive
panel operations. (Changing the PHP *version* through the UI does rewrite the pool, in the
new version's directory.)

Design notes on the stock values:

- `pm = ondemand` — workers spawn per request and die after idle timeout. With dozens of
  mostly-idle tenants this is the right call: memory is only consumed by sites currently
  serving traffic. The trade-off is a fork cost on the first request after idle.
- `pm.max_children = 250` per site with no global cap means N sites can, in principle,
  demand 250×N workers. In practice `ondemand` plus real traffic keeps this far lower, but
  **there is no memory ceiling enforced anywhere.** This is the single most important thing
  to fix if you build your own version (see §16.6).
- `listen.allowed_clients = 127.0.0.1` restricts *remote* peers only — it does not
  distinguish local users. See §15.

### 8.4 Per-site PHP ini: the `PHP_VALUE` mechanism

CloudPanel does **not** write a `php.ini` per site, and does **not** use `php_admin_value`
in the pool. Instead the nginx backend block passes ini directives as a FastCGI parameter:

```nginx
fastcgi_param PHP_VALUE "{{php_settings}}";
```

`Processor/PhpSettings.php` renders that from the `php_settings` row:

```
error_log=/home/<user>/logs/php/error.log;
memory_limit=<memory_limit>;
max_execution_time=<max_execution_time>;
max_input_time=<max_input_time>;
max_input_vars=<max_input_vars>;
post_max_size=<post_max_size>;
upload_max_filesize=<upload_max_file_size>;
<additional_configuration, verbatim, if set>
```

If Varnish is enabled for the site, it *prepends* one more directive:

```
auto_prepend_file=/home/<user>/.varnish-cache/controller.php;
```

A rendered example from this host (`lv.cijagani.in.conf`):

```nginx
fastcgi_param PHP_VALUE "
error_log=/home/cijagani-lv/logs/php/error.log;
memory_limit=512M;
max_execution_time=60;
max_input_time=60;
max_input_vars=10000;
post_max_size=64M;
upload_max_filesize=64M;
date.timezone=UTC;
display_errors=off;";
```

(`date.timezone` and `display_errors` came from `additional_configuration`.)

**Why this matters architecturally:** changing a site's PHP settings requires **rewriting the
nginx vhost and reloading nginx** — not touching PHP at all. It also means these are
`PHP_VALUE` (overridable at runtime by the app, i.e. `ini_set()` works) rather than
`PHP_ADMIN_VALUE` (locked). If you need hard limits a tenant cannot raise, this mechanism is
the wrong one — use `php_admin_value` in the pool file instead.

It also means **CLI runs do not get these settings**, because there is no FastCGI request.
CLI uses the version's `/etc/php/<ver>/cli/php.ini`.

---

## 9. PHP CLI Architecture

This is the part people most often get wrong about CloudPanel, so it is worth being blunt:

> **PHP CLI is global and server-wide. It is NOT per-site.**

```
/usr/bin/php  ->  /etc/alternatives/php  ->  /usr/bin/php8.4
```

Managed by `update-alternatives` in **manual mode**. On this host:

```
php - manual mode
  link best version is /usr/bin/php8.5
  link currently points to /usr/bin/php8.4
```

Every version is also directly callable:

```
/usr/bin/php7.1  /usr/bin/php7.2  /usr/bin/php7.3  /usr/bin/php7.4  /usr/bin/php8.0
/usr/bin/php8.1  /usr/bin/php8.2  /usr/bin/php8.3  /usr/bin/php8.4  /usr/bin/php8.5
```

### The consequence

A site running FPM 8.2 will get **PHP 8.4** when its user types `php artisan ...`, because
`/usr/bin/php` is a machine-wide symlink. FPM version and CLI version are completely
independent.

This is visible in the cron jobs on this host — the ones that were written carefully pin the
binary, the ones that were not are silently running on whatever the global alternative is:

```cron
# pinned — correct
0 */3 * * * cijagani-help /usr/bin/php8.3 /home/cijagani-help/htdocs/.../artisan schedule:run
*   *  * * * cijagani-main /usr/bin/php8.4 /home/cijagani-main/htdocs/.../artisan schedule:run

# unpinned — runs on the global alternative (8.4), NOT the site's FPM version
*   *  * * * cijagani-app  /usr/bin/php /home/cijagani-app/htdocs/.../artisan schedule:run
*   *  * * * cijagani-demo cd /home/.../instamark && php artisan schedule:run
```

For `cijagani-app` (FPM 8.4) that happens to match. For a site on FPM 8.2 or 8.3 it would
not — and the failure mode is subtle: composer's `platform` checks pass, but extension
availability and language features differ between the web and CLI paths.

### How to work around it

There is no CloudPanel-supplied per-site CLI switch. Practical options:

1. **Always pin the binary** in cron and deploy scripts: `/usr/bin/php8.3 artisan ...`
2. **Per-user shim** — add to the site user's `~/.bashrc`:
   ```bash
   mkdir -p ~/bin && ln -sf /usr/bin/php8.3 ~/bin/php
   export PATH="$HOME/bin:$PATH"
   ```
   The stock `~/.profile` already prepends `$HOME/bin` to `PATH` if it exists, so this works
   with no other changes.
3. Pin in `composer.json` via `config.platform.php` so Composer resolves for the right target
   regardless of which binary invoked it.

Note the parallel: CloudPanel *does* solve this for Node.js (NVM per home directory, loaded
from `/etc/bashrc/bashrc`) and for Python (`~/.python_version`, sourced by `~/.bashrc`), but
not for PHP. If you build your own panel, **fix this** — a `~/.php_version` file sourced the
same way, or a per-user `~/bin/php` symlink created at site creation, closes the gap for
about ten lines of code.

---

## 10. SSL/TLS Management

### 10.1 CloudPanel implements ACME itself

There is **no certbot, no lego, no acme.sh** on the system. `src/Site/Ssl/LetsEncryptClient.php`
is a hand-rolled ACME v2 client built on Guzzle.

```
src/Site/Ssl/
├── LetsEncryptClient.php          # ACME v2 protocol: JWS signing, nonces, orders
├── LetsEncrypt/CertificateOrder.php
├── LetsEncrypt/DomainValidationException.php
├── Generator/RsaKeyGenerator.php  # openssl_pkey_new
├── Generator/CsrGenerator.php
├── DistinguishedName.php          # CN + SANs
├── CertificateParser.php / KeyParser.php / ParsedCertificate.php
├── DataSigner.php                 # RS256 over protected.payload
├── Util/Base64SafeEncoder.php     # base64url
└── Util/Openssl.php               # createSelfSignedCertificate
```

Endpoints:

```
production: https://acme-v02.api.letsencrypt.org/acme/{new-acct,new-nonce,
                                                        new-authz,new-order,
                                                        revoke-cert,key-change}
staging:    https://acme-staging-v02.api.letsencrypt.org/acme/...
```

`setDryRun(true)` switches to staging. `User-Agent: CloudPanel`, 15s timeout.

> ⚠️ The Guzzle client is constructed with `"verify" => false` — TLS certificate
> verification against the ACME API is **disabled**. The ACME protocol's own JWS signatures
> still protect integrity, but this is not a pattern to copy.

The ACME **account key** is stored once, globally, in `config` under the key
`le_private_key` — one account for the whole server, shared by every site.

### 10.2 Issuance flow

From `LetsEncryptInstallCertificateCommand`:

```
 1. Resolve domain -> registrableDomain + subdomain
       if subdomain is null or "www":  domains = [registrable, www.registrable]
       else:                            domains = [domainName]
       domains = unique(domains + explicit --subjectAlternativeName list)

 2. privateKey = config['le_private_key']            (the ACME ACCOUNT key)

 ── DRY RUN AGAINST STAGING FIRST ────────────────────────────────────────────
 3. client = LetsEncryptClient(privateKey); client->setDryRun(true)
    client->registerAccount()
    order = client->requestOrder(domains)
    siteUpdater->deleteLetsEncryptChallengeDirectory()
    siteUpdater->createLetsEncryptChallengeFiles(order)
    errors = client->validateDomains(order)
    if errors -> throw DomainValidationException   ← fail fast, no rate-limit burn

 ── REAL ISSUANCE ────────────────────────────────────────────────────────────
 4. client = LetsEncryptClient(privateKey)          (production)
    client->registerAccount()
    order = client->requestOrder(domains)
    siteUpdater->deleteLetsEncryptChallengeDirectory()
    siteUpdater->createLetsEncryptChallengeFiles(order)
    errors = client->validateDomains(order)
    if errors -> throw

 5. NEW keypair + CSR for the CERTIFICATE (distinct from the account key)
    rsaKeyGenerator->generatePrivateKey()
    csrGenerator(privateKey, DistinguishedName(commonName, SANs))->generate()
    certificate = client->finalizeOrder(order, privateKey, csr)

 6. certificateEntity: type = TYPE_LETS_ENCRYPT (2), store key/cert/chain/csr in DB
    siteUpdater->installCertificate(certificateEntity)
       -> write /etc/nginx/ssl-certificates/<domain>.key
       -> write /etc/nginx/ssl-certificates/<domain>.crt
    siteUpdater->deleteLetsEncryptChallengeDirectory()
    reload nginx
```

The staging dry-run before every real issuance is a genuinely good idea worth stealing: it
catches DNS and webroot problems without consuming Let's Encrypt's production rate limits.

### 10.3 HTTP-01 challenge handling

`Updater::createLetsEncryptChallengeFiles()`:

```php
rootDirectory        = /home/<user>/htdocs/<root_directory>
wellKnownDirectory   = <rootDirectory>/.well-known/
acmeChallengeDirectory = <rootDirectory>/.well-known/acme-challenge/

mkdir -p  <acmeChallengeDirectory>
foreach (challenge as {token, verificationContent}):
    write <acmeChallengeDirectory>/<token>  with content <verificationContent>

chown -R <siteUser>:<siteUser>  <wellKnownDirectory>
find <wellKnownDirectory> -type d -exec chmod 750
find <wellKnownDirectory> -type f -exec chmod 770
```

And `deleteLetsEncryptChallengeDirectory()` removes `<root>/.well-known/acme-challenge/`
before and after every attempt.

The webroot works because **every** vhost template carries this block unconditionally:

```nginx
location ~ /.well-known {
  auth_basic off;
  allow all;
}
```

`auth_basic off` and `allow all` mean HTTP basic auth, Cloudflare-only mode, and IP blocking
are all bypassed for the challenge path — otherwise renewal would break the moment a site
enabled basic auth. (For reverse-proxy sites the block becomes
`location ^~ /.well-known { ... try_files $uri @reverse_proxy; }` so a local challenge file
wins over the proxied backend.)

### 10.4 Certificate storage

| Location | Contents | Notes |
|---|---|---|
| `certificate` table | `private_key`, `certificate`, `certificate_chain`, `csr`, `expires_at`, `type` | **authoritative** |
| `/etc/nginx/ssl-certificates/<domain>.key` | private key | root:root **0644** ⚠ |
| `/etc/nginx/ssl-certificates/<domain>.crt` | cert + chain | root:root 0644 |

On this host: 32 self-signed, 32 Let's Encrypt, 3 imported certificates. A site keeps its
self-signed row after LE issuance — that is the `certificates` collection (many per site);
`site.certificate_id` points at the active one.

⚠️ **Private keys are mode 0644 — world-readable.** Any site user on the box can read every
other site's TLS private key. See §15.

### 10.5 Renewal

Handled by `clp-agent`'s internal Go scheduler calling
`lets-encrypt:renew:certificates` — **not** by a cron entry. Do not go looking in
`/etc/cron.d` for it. `LetsEncryptRenewCustomDomainCertificateCommand` separately handles
the panel's own `custom_domain` certificate (the hostname you use to reach :8443).

---

## 11. Cron, Logrotate & Logs

### 11.1 Cron

Per-site cron lives in `/etc/cron.d/<siteUser>` — **not** in user crontabs.
`/var/spool/cron/crontabs/` is empty on this host.

```cron
MAILTO=""
* * * * * cijagani-main /usr/bin/php8.4 /home/cijagani-main/htdocs/.../artisan schedule:run >> /dev/null 2>&1
```

Format note: `/etc/cron.d` files require a **user field** between the schedule and the
command — that is the `cijagani-main` column. This is what makes the job run as the site user.
`MAILTO=""` suppresses mail on output.

Written by `Updater::updateUserCrontab()` from the `cron_job` table
(`minute, hour, day, month, weekday, command` per row, all scoped to `site_id`).
Deleting a site removes `/etc/cron.d/<siteUser>` **and** runs `crontab -r` for the user.

### 11.2 Logrotate

Template at `resources/etc/logrotate/template`, rendered to `/etc/logrotate.d/<siteUser>`:

```
/home/{{user}}/logs/*/*.log {
    su root root
    daily
    missingok
    rotate 7
    dateext
    dateformat -%Y-%m-%d
    create 0640 {{user}} {{group}}
    postrotate
      /etc/init.d/nginx reload &> /dev/null || true
    endscript
}
```

The `/*/*.log` glob catches `logs/nginx/*.log`, `logs/php/*.log` and
`logs/varnish-cache/*.log` in one rule. `su root root` is required because the directory is
owned by an unprivileged user — without it logrotate refuses to act. Retention is **7 days**,
which is short; raise `rotate` if you need history.

### 11.3 Log inventory

| Log | Path | Written by |
|---|---|---|
| Site nginx access | `/home/<user>/logs/nginx/access.log` | nginx (frontend block) |
| Site nginx error | `/home/<user>/logs/nginx/error.log` | nginx |
| Site PHP errors | `/home/<user>/logs/php/error.log` | PHP-FPM via `PHP_VALUE error_log` |
| Varnish purges | `/home/<user>/logs/varnish-cache/purge.log` | controller.php |
| Global nginx | `/var/log/nginx/{access,error}.log` | nginx |
| Panel nginx | `/home/clp/logs/nginx/error.log` | clp-nginx |
| Panel PHP | `/home/clp/logs/php/error.log` | clp-php-fpm |

The access-log **format** is chosen per site: `main` normally, `cloudflare` when
"allow traffic from Cloudflare only" is on — the latter logs `$http_cf_connecting_ip` as the
client so the logs stay meaningful behind the CDN.

**[LOCAL]** On this host, `access_log off;` is set at `http{}` level in `nginx.conf` and the
per-site `access_log` lines in the vhosts are **commented out**, so site access logs are not
being written. Error logs are unaffected.

---

## 12. Varnish Cache Integration

Optional per-site HTTP caching, wired in through three places at once.

**1. The proxy hop** — `Processor/VarnishProxyPass.php`:

```php
default = "http://127.0.0.1:8080"                       // no varnish
if (site.varnishCache && settings.enabled && settings.server)
    value = "http://" . trim(settings.server, "/")      // e.g. 127.0.0.1:6081
render: proxy_pass <value>;
```

**2. The PHP hook** — `Processor/PhpSettings.php` prepends
`auto_prepend_file=/home/<user>/.varnish-cache/controller.php;` to the `PHP_VALUE` block,
so the controller loads before every request without the application knowing.

**3. The per-site control files** — `createVarnishCacheStructure()`:

```
/home/<user>/.varnish-cache/settings.json
/home/<user>/.varnish-cache/controller.php   (copied from resources/varnish-cache/
                                              controller/{generic|wordpress}/)
/home/<user>/logs/varnish-cache/purge.log
```

`settings.json` as it exists on this host:

```json
{
    "enabled": false,
    "server": "127.0.0.1:6081",
    "cacheTagPrefix": "f697",
    "cacheLifetime": "604800",
    "excludes": ["^\\/admin\\/"],
    "excludedParams": ["__SID", "noCache"]
}
```

The `controller` key is present in the *template's* `varnish_cache_settings` but is stripped
before writing `settings.json` — it only selects which controller PHP file to copy.

**Important subtlety observed here:** `site.varnish_cache = 1` in the database for most sites
on this host, yet `settings.json` has `"enabled": false`, so the rendered vhosts proxy to
`:8080` and bypass Varnish entirely — even though `varnishd` is running and listening on
6081. The **runtime** switch is the JSON file, not the DB column. Two flags must agree, and
the JSON one wins. Worth simplifying if you rebuild this.

Purging goes through `clpctl varnish-cache:purge` / `src/Site/VarnishCache/Client.php`, and
the controller logs each purge to `purge.log`.

---

## 13. Site Deletion: Teardown Order

From `src/Site/Deleter.php` and `Deleter/PhpSite.php`. The order is deliberate and worth
copying exactly — it stops traffic first, then kills processes, then removes identity.

```
 0. [PHP sites only] deletePhpFpmPool()
       rm /etc/php/<ver>/fpm/pool.d/<domain>.conf
       systemctl reload php<ver>-fpm          ← workers for this site stop existing

 1. deleteVhost()
       rm /etc/nginx/sites-enabled/<domain>.conf
       systemctl reload nginx                 ← site is now unreachable

 2. deleteCertificates()
       rm /etc/nginx/ssl-certificates/<domain>.crt
       rm /etc/nginx/ssl-certificates/<domain>.key

 3. deleteBasicAuthFile()
       rm /etc/nginx/basic-auth/<domain>

 4. deleteFtpUsers()
       userdel  <ftpUser>          (home directory KEPT — it is inside the site home)

 5. deleteSshUsers()
       userdel -r <sshUser>        (home directory REMOVED)

 6. killSiteUserProcesses()
       pkill -u <siteUser>         (run in background)

 7. deleteCrontab()
       rm /etc/cron.d/<siteUser>
       crontab -r -u <siteUser>

 8. deleteSiteUser()
       userdel -r <siteUser>       ← /home/<siteUser> AND ALL SITE FILES ARE DELETED

 9. deleteLogrotateFile()
       rm /etc/logrotate.d/<siteUser>

10. deleteDatabases()
       for each database: DatabaseManager->deleteDatabase(db, true)
                          (drops the database AND its users)

11. DB rows removed by ON DELETE CASCADE from `site`
```

Points to note:

- **Step 8 is destructive and total.** `userdel -r` removes the home directory, which is where
  all site content lives. There is no confirmation, no soft-delete, no archive step. If you
  build your own system, put a snapshot/export between steps 7 and 8.
- FTP users are deleted **without** `-r` (step 4) because their home is a *subdirectory of the
  site's* home — removing it would delete site content prematurely. SSH users get `-r`
  because they have their own homes. This asymmetry is easy to get wrong.
- Processes are killed (step 6) *after* the pool and vhost are gone, so nothing can respawn.
- Databases are dropped **last**, after the filesystem is gone.

---

## 14. Databases, FTP & SSH Users

### 14.1 Databases

- One shared MySQL/Percona server (`mysql.service`), plus optional remote
  `database_server` entries.
- `database` table: `site_id`, `database_server_id`, `name` (globally UNIQUE).
- `database_user` holds the grants. Isolation is **by MySQL grant only** — there is no
  per-tenant database server.
- `clpctl db:add | db:delete | db:import | db:export | db:backup`
- `clpctl db:show:master-credentials` reveals the root credentials.
- Dumps land in `/home/<siteUser>/backups/databases/` — which is why that directory is in
  the skeleton.

### 14.2 FTP users

- `ftp_user` table: `user_name` (UNIQUE), `home_directory`.
- Created as **real Linux users** whose home is a directory *inside* the site's home.
- Deleted without `-r` (see §13).

### 14.3 Additional SSH users

`Updater::createSshUser()` — for giving a developer their own login against one site:

```
homeDirectory    = /home/<sshUserName>
skeletonDirectory = resources/etc/skel/ssh-user/
useradd -m -k <skel> -s /bin/bash -d /home/<sshUserName> \
        -g <siteUser> <sshUserName>              ← PRIMARY GROUP = the site's group

then symlink into the new home:
    backups -> /home/<siteUser>/backups
    htdocs  -> /home/<siteUser>/htdocs
    logs    -> /home/<siteUser>/logs

chmod 700 /home/<sshUserName>/.ssh   (dirs)
chmod 600 /home/<sshUserName>/.ssh/* (files)
chmod (non-recursive) on /home/<siteUser>
```

The mechanism: the SSH user's **primary group is the site user's group**, and the site home
is mode `770` — so group members have full read/write. The symlinks give them a familiar
layout without duplicating anything.

Consequence: an SSH user has **full write access to the site's files**, including `htdocs`.
This is a collaborator account, not a restricted one. Note also that files they create will
be owned `sshUser:siteUser` — the group is what keeps things working, so if anything ever
resets group ownership, access breaks.

`site.ssh_keys` / `ssh_user.ssh_keys` are written to `~/.ssh/authorized_keys` (mode 600).

---

## 15. Security Model & Attack Surface

An honest assessment — useful both for running CloudPanel and for deciding what to change in
your own build.

### Strengths

| Control | Why it matters |
|---|---|
| Per-site UID + FPM pool | PHP RCE in one site cannot read another's files |
| `/home` mode `0711` | Tenants cannot enumerate each other |
| `disable_symlinks if_not_owner from=/home/` | Blocks symlink escapes out of a webroot |
| `770` + `umask 007` | No world-readable site content |
| `default.conf` → `ssl_reject_handshake` + `444` | Unknown Hosts get nothing |
| FPM binds `127.0.0.1` only, never exposed by ufw | No remote FastCGI access |
| Staging dry-run before real ACME issuance | Avoids rate-limit lockout |
| Panel on a separate nginx + separate FPM | Site config errors cannot break the panel |
| MFA support on panel users (`user.mfa`, `mfa_secret`) | Panel login hardening |

### Weaknesses — things to fix or design around

1. **TLS private keys are world-readable.**
   `/etc/nginx/ssl-certificates/*.key` are `root:root 0644`. Any site user can read every
   other tenant's private key. Mitigation: `chmod 640` + `chgrp` to a group nginx can read,
   or `chmod 600` (nginx workers run as root, so `600` is sufficient for nginx itself).
   Verify with `nginx -t` and reload before trusting it.

2. **Nginx workers run as root.**
   `user root;` means any nginx worker memory-disclosure or module vulnerability is an
   immediate full compromise. This is a deliberate trade (root can read every tenant's
   `770` files without a shared group), but it is a large amount of privilege to hold
   permanently. The alternative — a shared `www-data` in every site group — is what the
   group layout in §4.2 already sets up, so this is closer to changeable than it looks.

3. **Cross-tenant FastCGI access on loopback.**
   `listen.allowed_clients = 127.0.0.1` filters by *peer address*, not by user. Site user A
   can open a socket to site B's pool port and speak FastCGI directly, executing PHP as user
   B within B's webroot. **Fix: use Unix domain sockets instead of TCP**, with
   `listen.owner = <siteUser>`, `listen.group = <siteUser>`, `listen.mode = 0660`. Then the
   filesystem enforces who may connect. This is the single highest-value change you can make
   to this architecture.

4. **No resource limits per tenant.**
   No cgroups, no disk quotas, no per-site memory ceiling. `pm.max_children` is advisory.
   One site can starve the box. See §16.6.

5. **`PHP_VALUE`, not `PHP_ADMIN_VALUE`.**
   Every per-site PHP limit is overridable by the tenant at runtime via `ini_set()`.
   `memory_limit`, `max_execution_time` — all advisory. If you need enforcement, put them in
   the pool file as `php_admin_value`.

6. **Weak basic-auth hashing.**
   `crypt($password, base64_encode($password))` is DES crypt, salted with a value derived
   from the password itself — 8-character effective length, trivially crackable.
   Use bcrypt (`$2y$`) — nginx supports it.

7. **`sudo NOPASSWD` for `clpctlWrapper` granted to `ALL` users.**
   Every account on the box, including every site user, may invoke `clpctlWrapper` as root.
   The wrapper is `0700 root:root` so it is only reachable through that rule, and `clpctl`
   escapes `` \ ` ( ) $ " `` before re-invoking through `bash -c`. The safety of the whole
   arrangement rests on that escaping being complete and on `clpctl` itself authenticating
   the caller. Treat this as the highest-risk surface in the design; if you replicate it,
   restrict the sudoers rule to a specific group rather than `ALL`, and prefer a
   root-owned daemon with a validated request protocol over `sudo` + string escaping.

8. **ACME client disables TLS verification** (`"verify" => false`). Copy the ACME design,
   not this line.

9. **All PHP versions from 7.1 installed simultaneously.**
   7.1–8.0 are all end-of-life and unpatched. Every installed FPM binary is attack surface
   whether or not a site uses it. Remove versions you do not need.

10. **Panel UI on 8443 open to the world** in ufw. Restrict by source IP, or put it behind a
    VPN/WireGuard, and use the basic-auth option (`cloudpanel:enable-basic-auth`) plus MFA.

11. **Backups.** `/home/clp/backups/` and `db.sq3` contain **all** certificate private keys
    and panel credentials. Encrypt them at rest and control access tightly.

---

## 16. Blueprint: Building Your Own

This section distils the design into something you can implement, with the fixes from §15
folded in.

### 16.1 Data model (minimum viable)

```sql
CREATE TABLE site (
  id              INTEGER PRIMARY KEY,
  type            TEXT NOT NULL,          -- php | static | reverse-proxy | nodejs | python
  domain_name     TEXT NOT NULL UNIQUE,   -- keys the vhost/cert/pool filenames
  system_user     TEXT NOT NULL UNIQUE,   -- keys the home/cron/logrotate filenames
  root_directory  TEXT NOT NULL,          -- "<domain>/<subpath>", relative to htdocs
  vhost_template  TEXT NOT NULL,          -- rendered output, for diff/rollback
  template_name   TEXT,
  reverse_proxy_url TEXT,
  created_at, updated_at
);

CREATE TABLE php_settings (
  site_id INTEGER UNIQUE REFERENCES site(id) ON DELETE CASCADE,
  php_version TEXT NOT NULL,
  pool_port   INTEGER,                    -- or pool_socket TEXT, see 16.3
  memory_limit, max_execution_time, max_input_time, max_input_vars,
  post_max_size, upload_max_file_size, additional_configuration
);

CREATE TABLE certificate (
  id INTEGER PRIMARY KEY,
  site_id INTEGER REFERENCES site(id) ON DELETE CASCADE,
  type TEXT,                              -- self-signed | lets-encrypt | imported
  private_key TEXT, certificate TEXT, certificate_chain TEXT, csr TEXT,
  expires_at DATETIME, is_active BOOLEAN
);

CREATE TABLE port_allocation (             -- ← FIX for §8.2
  port INTEGER PRIMARY KEY,
  php_version TEXT NOT NULL,
  site_id INTEGER REFERENCES site(id) ON DELETE CASCADE
);
```

Keep the two-key discipline: **domain names** key per-hostname artifacts,
**system users** key per-tenant artifacts.

### 16.2 Directory contract

```
/home/<user>/
├── htdocs/<domain>/[subpath]     # web root
├── logs/{nginx,php}/             # per-site logs, pre-created empty
├── backups/databases/
├── tmp/
└── .ssh/                         # 700, keys 600

/etc/nginx/sites-enabled/<domain>.conf
/etc/nginx/ssl-certificates/<domain>.{crt,key}      # chmod 640, NOT 644
/etc/php/<ver>/fpm/pool.d/<domain>.conf
/etc/logrotate.d/<user>
/etc/cron.d/<user>
```

Ship a **skeleton directory** and pass it to `useradd -k`. Pre-create the empty log files
there — it saves you a whole class of "root created a file in a user directory" bugs.

### 16.3 Port allocation — do it better

CloudPanel's scan-and-increment is race-prone and its comparator is buggy. Two better options:

**Option A — allocate from the DB, transactionally:**

```sql
BEGIN IMMEDIATE;
  INSERT INTO port_allocation (port, php_version, site_id)
  SELECT COALESCE(MAX(port), :base_port) + 1, :ver, :site_id
    FROM port_allocation WHERE php_version = :ver;
COMMIT;
```

**Option B — skip ports entirely, use Unix sockets** (recommended; also fixes §15.3):

```ini
[<domain>]
listen = /run/php/<domain>-<ver>.sock
listen.owner = <siteUser>
listen.group = <siteUser>
listen.mode = 0660
user  = <siteUser>
group = <siteUser>
```

```nginx
fastcgi_pass unix:/run/php/<domain>-<ver>.sock;
```

Now no allocation is needed at all, and kernel file permissions — not an address filter —
decide who may connect. Note that nginx runs as root and can open any socket; that is the
point. Add a `tmpfiles.d` entry so `/run/php` survives reboot.

### 16.4 Template engine

Implement the placeholder/processor pattern from §7.2 — it is the best idea in the codebase:

```python
PROCESSORS = {
    "{{root}}":              lambda s: f"root /home/{s.user}/htdocs/{s.root_directory};",
    "{{server_name}}":       lambda s: f"server_name {' '.join(s.server_names)};",
    "{{php_fpm_socket}}":    lambda s: f"fastcgi_pass unix:{s.pool_socket};",
    "{{php_settings}}":      render_php_value_block,
    "{{ssl_certificate}}":   lambda s: f"ssl_certificate /etc/nginx/ssl-certificates/{s.domain}.crt;",
    "{{nginx_access_log}}":  lambda s: f"access_log /home/{s.user}/logs/nginx/access.log {s.log_format};",
    "{{settings}}":          render_settings_block,
}

def render(template: str, site) -> str:
    for token, fn in PROCESSORS.items():
        if token in template:
            template = template.replace(token, fn(site))
    return re.sub(r"\{\{[\s\w]+\}\}", "", template)   # strip unresolved tokens
```

Then **always validate before activating**:

```bash
nginx -t && systemctl reload nginx
```

CloudPanel has a `NginxConfigTestCommand` for exactly this. Write the vhost to a temp path,
test, then move it into place — never leave `sites-enabled/` in a state that fails `-t`.

### 16.5 Creation sequence (ordering matters)

```
 1. validate input, resolve domain (use a Public Suffix List library)
 2. BEGIN transaction; insert site + settings; allocate port/socket
 3. useradd -m -k <skel> -s /bin/bash -d /home/<user> <user>
 4. mkdir -p /home/<user>/htdocs/<domain>/<subpath>
 5. write placeholder index file
 6. generate self-signed cert  -> DB, then -> /etc/nginx/ssl-certificates/
 7. write FPM pool -> reload php<ver>-fpm
 8. write logrotate file
 9. chown -R + chmod (770 dirs/files, 700/600 for .ssh)   ← BEFORE the vhost
10. render vhost -> temp -> nginx -t -> move into sites-enabled -> reload nginx
11. COMMIT
```

Permissions before vhost; vhost last. On failure at any step, run the teardown from §13 for
what was already created.

### 16.6 Add what CloudPanel lacks

**Resource limits per tenant** — the biggest gap. Use systemd slices:

```ini
# /etc/systemd/system/site-<user>.slice
[Slice]
MemoryMax=2G
CPUQuota=100%
TasksMax=256
```

Then bind the pool's workers into it. Since all pools of a version share one systemd unit,
the practical route is either one systemd unit per site (`php-fpm@<site>.service` with its
own `php-fpm.conf`), or applying limits via `pam_limits`/cgroup rules on the UID. Also add
filesystem quotas (`quotaon` + `setquota` per UID) — trivially cheap and prevents one tenant
filling the disk.

**Per-site PHP CLI** (§9). At site creation:

```bash
mkdir -p /home/<user>/bin
ln -sf /usr/bin/php<ver> /home/<user>/bin/php
```

The stock `~/.profile` already puts `$HOME/bin` first on `PATH`. Add a `~/.php_version` file
sourced from `.bashrc` for symmetry with the Python mechanism, and **always pin the binary in
generated cron entries** — never emit a bare `php`.

**Hard PHP limits.** Put the values a tenant must not exceed in the pool as
`php_admin_value[memory_limit] = 512M`, and keep only genuinely tunable ones in `PHP_VALUE`.

**Also worth adding:** disk-usage accounting per site; an export/snapshot step before
deletion; config-drift detection (re-render every vhost and diff against disk); per-site
`fail2ban` jails fed by the per-site error logs.

### 16.7 What to copy verbatim

- One Linux user per site; `/home` mode `0711`; `770` + `umask 007`.
- The skeleton directory with pre-created empty logs.
- The placeholder/processor template engine.
- DB-as-truth, files-as-projection — including storing certs in the DB.
- Certificates in the DB means backup = DB + `/home`, nothing else.
- The staging ACME dry-run before production issuance.
- The unconditional, auth-exempt `/.well-known` location in every template.
- The teardown ordering (pool → vhost → processes → user → databases).
- `disable_symlinks if_not_owner from=/home/`.
- A catch-all default vhost with `ssl_reject_handshake` + `return 444`.
- Separate panel nginx/FPM from tenant nginx/FPM.

### 16.8 What to change

| CloudPanel does | Do instead |
|---|---|
| TCP ports for FPM pools | Unix sockets with `listen.owner`/`mode 0660` |
| Scan-and-increment port allocation with a buggy comparator | DB transaction, or no ports at all |
| Cert keys `0644` | `0640` or `0600` |
| `user root;` in nginx | `www-data`, using the existing per-site group membership |
| `PHP_VALUE` for everything | `php_admin_value` for limits, `PHP_VALUE` for preferences |
| DES `crypt()` for basic auth | bcrypt (`$2y$`) |
| `sudo NOPASSWD` for `ALL` users | Restrict to a group; or a root daemon with a typed protocol |
| ACME with `verify => false` | Verify TLS |
| No resource limits | systemd slices + disk quotas |
| Global PHP CLI | Per-user `~/bin/php` symlink, pinned cron |
| `userdel -r` with no snapshot | Export/archive before destroying |
| Ten PHP versions installed | Only what is in use |

---

## 17. Gotchas & Observations

Practical notes, several of them observed on this specific host.

1. **`sites-available/` is empty and unused.** There is no enable/disable. Do not look for
   symlinks; `sites-enabled/` holds real files.

2. **Editing an FPM pool file by hand is safe and persistent.** CloudPanel writes the pool
   at creation and deletes it at teardown, but never rewrites tuning. The pools on this host
   have been hand-tuned (`max_children` 250→15 etc.) and those edits survive. Changing the
   PHP *version* through the panel does write a fresh pool in the new version's directory.

3. **Editing a vhost by hand is *not* safe** unless you also update
   `site.vhost_template` — any panel operation that re-renders will overwrite you. Prefer a
   custom vhost template, or an `include` of a file the panel does not manage.

4. **PHP CLI ≠ PHP FPM version** (§9). The most common real-world bug on CloudPanel boxes.
   Check `/etc/alternatives/php` before debugging a "works in browser, fails in cron" issue.
   On this host several cron entries use bare `php` and are silently on 8.4.

5. **`{{server_name}}` emits `www1.<domain>`** for apex/`www` sites — a quirk in the shipped
   build. Harmless, but surprising in `nginx -T` output.

6. **Two flags gate Varnish** — `site.varnish_cache` in the DB *and* `"enabled"` in
   `/home/<user>/.varnish-cache/settings.json`. The JSON wins at render time. On this host
   the DB says on and the JSON says off, so Varnish is running but bypassed.

7. **Certificate renewal has no cron entry.** It lives inside the `clp-agent` Go scheduler.

8. **`fastcgi_param HTTPS "on"` is hardcoded** in the backend block, so `$_SERVER['HTTPS']`
   is always `on` and `SERVER_PORT` always `443`, regardless of the actual request. Frameworks
   that build absolute URLs from these will always produce `https://` — usually what you want,
   occasionally confusing behind an unusual proxy chain.

9. **Port 8080 binds `0.0.0.0`**, not loopback. It is unreachable only because ufw does not
   allow it. If you ever disable or reconfigure the firewall, every site's PHP backend becomes
   directly reachable with TLS bypassed.

10. **`/home` is `0711`** — traversable, not listable. `ls /home` fails for site users but
    `cd /home/otheruser` succeeds (and then stops at the `770` home). Do not "fix" the `0711`.

11. **Logrotate keeps 7 days** and rotates daily. Short. Raise `rotate` if you need history,
    and remember `su root root` must stay.

12. **FTP users are deleted without `-r`, SSH users with `-r`.** Getting this backwards
    either deletes site content or leaves orphaned homes.

13. **SSH users get full write access** to the site via group membership. There is no
    read-only collaborator role.

14. **The source is obfuscated but readable.** `goto`-flattened control flow, hex/octal
    string escapes. Decode the escapes and it reads normally — that is how this document
    was produced.

15. **Backups on this host** live in `/home/clp/backups/<timestamp>/` plus
    `/home/<user>/backups/`, and `clp-agent` drives scheduled runs.
    `/home/clp/scripts/create_backup.sh` exists for manual invocation.

16. **Ten PHP versions (7.1–8.5) are installed**, most end-of-life. Each has a running FPM
    master with at least a `default` pool. On this host, versions 8.2/8.3/8.4/8.5 have real
    site pools; 7.1–8.1 have only the default pool and are pure attack surface.

---

## Appendix A: Quick Command Reference

```bash
# ── SITES ──────────────────────────────────────────────────────────────────
clpctl site:add:php --domainName=example.com --phpVersion=8.3 \
                    --vhostTemplate='Generic' --siteUser=myuser \
                    --siteUserPassword='!secret!'
clpctl site:add:static        --domainName=... --siteUser=... --siteUserPassword=...
clpctl site:add:reverse-proxy --domainName=... --reverseProxyUrl=http://127.0.0.1:3000 ...
clpctl site:add:nodejs        --domainName=... --nodejsVersion=20 --appPort=3000 ...
clpctl site:add:python        --domainName=... --pythonVersion=3.11 --appPort=8000 ...
clpctl site:delete --domainName=example.com --force

# ── SSL ────────────────────────────────────────────────────────────────────
clpctl lets-encrypt:install:certificate --domainName=example.com \
       --subjectAlternativeName=www.example.com,shop.example.com
clpctl site:install:certificate --domainName=... \
       --privateKey=/path/key.pem --certificate=/path/cert.pem

# ── DATABASES ──────────────────────────────────────────────────────────────
clpctl db:add --domainName=... --databaseName=... --databaseUserName=... \
              --databaseUserPassword=...
clpctl db:export --databaseName=db --file=/home/user/backups/databases/db.sql.gz
clpctl db:import --databaseName=db --file=/path/dump.sql.gz
clpctl db:show:master-credentials

# ── PANEL USERS ────────────────────────────────────────────────────────────
clpctl user:add --userName=john --email=... --firstName=... --lastName=... \
                --password='...' --role=admin
clpctl user:reset-password --userName=john --password='...'
clpctl user:disable-mfa --userName=john

# ── VHOST TEMPLATES ────────────────────────────────────────────────────────
clpctl vhost-templates:list
clpctl vhost-template:view   --name='Generic'
clpctl vhost-template:add    --name='MyApp' --file=/path/template.conf
clpctl vhost-templates:import

# ── PANEL / SYSTEM ─────────────────────────────────────────────────────────
clpctl cloudpanel:enable-basic-auth --userName=... --password='...'
clpctl cloudpanel:disable-basic-auth
clpctl system:permissions:reset
clpctl varnish-cache:purge --domainName=...
```

## Appendix B: Diagnostic Cheatsheet

```bash
# Which FPM pool / port does a site use?
grep -r "listen = " /etc/php/*/fpm/pool.d/<domain>.conf
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT s.domain_name, s.user, p.php_version, p.pool_port
     FROM site s JOIN php_settings p ON p.site_id = s.id
    ORDER BY p.php_version, p.pool_port;"

# Full port map across every version
for v in /etc/php/*/fpm/pool.d; do
  grep -H '^listen = ' $v/*.conf 2>/dev/null
done

# Which sites run which PHP version?
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT php_version, COUNT(*) FROM php_settings GROUP BY php_version;"

# Certificates expiring soon
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT s.domain_name, c.type, c.expires_at
     FROM certificate c JOIN site s ON s.id = c.site_id
    ORDER BY c.expires_at;"
openssl x509 -in /etc/nginx/ssl-certificates/<domain>.crt -noout -dates -subject

# Validate config before reloading — ALWAYS
nginx -t
php-fpm8.3 -t
systemctl reload nginx php8.3-fpm

# Effective vhost for one domain
nginx -T | awk '/server_name <domain>/,/^}/'

# Per-site live processes
ps -u <siteUser> -o pid,ppid,etime,rss,cmd

# Per-site disk usage
du -sh /home/*/htdocs 2>/dev/null | sort -h

# Confirm which PHP the CLI actually uses
readlink -f /usr/bin/php; update-alternatives --display php
sudo -u <siteUser> php -v

# Tail one site's logs
tail -f /home/<siteUser>/logs/{nginx,php}/*.log

# Test logrotate without rotating
logrotate -d /etc/logrotate.d/<siteUser>

# Panel health
systemctl status clp-agent clp-nginx clp-php-fpm
tail -f /home/clp/logs/php/error.log
```

## Appendix C: File Manifest — One PHP Site

Everything created for a single site, in one table.

| Path | Owner | Mode | Created by | Removed by |
|---|---|---|---|---|
| `/home/<user>/` | `<user>:<user>` | 770 | `useradd -m -k <skel>` | `userdel -r` |
| `/home/<user>/htdocs/<domain>/[sub]/` | `<user>:<user>` | 770 | `createRootDirectory()` | `userdel -r` |
| `/home/<user>/htdocs/<domain>/[sub]/index.php` | `<user>:<user>` | 770 | `createIndexPhp()` | `userdel -r` |
| `/home/<user>/logs/nginx/{access,error}.log` | `<user>:<user>` | 770 | skeleton | `userdel -r` |
| `/home/<user>/logs/php/error.log` | `<user>:<user>` | 770 | skeleton | `userdel -r` |
| `/home/<user>/backups/databases/` | `<user>:<user>` | 770 | skeleton | `userdel -r` |
| `/home/<user>/tmp/` | `<user>:<user>` | 770 | skeleton | `userdel -r` |
| `/home/<user>/.ssh/{authorized_keys,config}` | `<user>:<user>` | 700/600 | skeleton | `userdel -r` |
| `/home/<user>/.varnish-cache/settings.json` | `<user>:<user>` | 770 | `createVarnishCacheStructure()` | `userdel -r` |
| `/home/<user>/.varnish-cache/controller.php` | `<user>:<user>` | 770 | copied from resources | `userdel -r` |
| `/etc/nginx/sites-enabled/<domain>.conf` | root:root | 644 | `createNginxVhost()` | `deleteVhost()` |
| `/etc/nginx/ssl-certificates/<domain>.crt` | root:root | 644 | `createPrivateKeyAndCertificate()` | `deleteCertificates()` |
| `/etc/nginx/ssl-certificates/<domain>.key` | root:root | 644 ⚠ | `createPrivateKeyAndCertificate()` | `deleteCertificates()` |
| `/etc/php/<ver>/fpm/pool.d/<domain>.conf` | root:root | 644 | `createPhpFpmPool()` | `deletePhpFpmPool()` |
| `/etc/logrotate.d/<user>` | root:root | 644 | `createLogrotateFile()` | `deleteLogrotateFile()` |
| `/etc/cron.d/<user>` | root:root | 644 | `updateUserCrontab()` *(on demand)* | `deleteCrontab()` |
| `/etc/nginx/basic-auth/<domain>` | root:root | 644 | `createBasicAuthFile()` *(on demand)* | `deleteBasicAuthFile()` |

---

*End of document.*
