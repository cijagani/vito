# CloudPanel Architecture — Complete Summary

**The entry point to a four-document series.** This document is a standalone synthesis: read
it alone to understand the whole system, or use it as an index into the detailed documents
when you need depth.

> **Source:** reverse-engineered from a **live CloudPanel 2.5.4 install** (Ubuntu 24.04.4 LTS,
> Percona 8.4, ~30 sites), by reading the running configuration *and* decoding the obfuscated
> application source at `/home/clp/htdocs/app/files/src/`. Every path, formula and template
> below was verified against the actual machine.
>
> **How to read this.** It is a **review and reference**, not a work order. Nothing on the
> server was changed to produce it — all inspection was read-only. Where a "fix" is described,
> treat it as analysis of how the system behaves and what a different design would do.
>
> **[LOCAL]** marks customisations specific to this host, as distinct from stock behaviour.
>
> Generated: 2026-09-06

---

## The Series

| # | Document | Covers |
|---|---|---|
| **0** | **`cloudpanel-summary.md`** ← you are here | **Everything, condensed. Entry point.** |
| 1 | `cloudpanel-architecture.md` | Sites, per-vhost filesystem, nginx engine, PHP-FPM & ports, PHP CLI, SSL/ACME, isolation, create/delete |
| 2 | `cloudpanel-database-backup-cron.md` | Database model & grants, dump/import, the three backup systems, rclone, cron generation, restores |
| 3 | `cloudpanel-security-access-monitoring.md` | Panel auth/MFA/API, firewall, per-site security, audit log, monitoring, privileged execution, reviewer's assessment |

Section references below are written as `[1 §7.2]` = document 1, section 7.2.

---

## Table of Contents

1. [The Core Idea](#1-the-core-idea)
2. [Component & Port Map](#2-component--port-map)
3. [What Gets Created for One Site](#3-what-gets-created-for-one-site)
4. [Site Lifecycle](#4-site-lifecycle)
5. [Nginx](#5-nginx)
6. [PHP-FPM and PHP CLI](#6-php-fpm-and-php-cli)
7. [SSL/TLS](#7-ssltls)
8. [Databases](#8-databases)
9. [Backups](#9-backups)
10. [Cron](#10-cron)
11. [Access Control & Security](#11-access-control--security)
12. [Monitoring & Audit](#12-monitoring--audit)
13. [The Privileged Execution Layer](#13-the-privileged-execution-layer)
14. [Cheat Sheet: Constants & Formulas](#14-cheat-sheet-constants--formulas)
15. [Live State of This Server](#15-live-state-of-this-server)
16. [Reviewer's Assessment](#16-reviewers-assessment)
17. [Blueprint Checklist](#17-blueprint-checklist)

---

## 1. The Core Idea

> **One Linux user per site. Everything else follows from that.**

That single decision cascades into the entire design:

| Concern | Solved by |
|---|---|
| File isolation | Files in `/home/<siteUser>/`, mode `770`, owned `siteUser:siteUser`, `/home` itself `0711` |
| Process isolation | Each site gets its **own PHP-FPM pool** running as `siteUser` |
| Shell access | `siteUser` has `/bin/bash`; can SSH in; sees only its own home |
| Logs | `/home/<siteUser>/logs/{nginx,php}/` |
| Cron | `/etc/cron.d/<siteUser>`, jobs run as `siteUser` |
| Cleanup | Delete the user → the home directory and everything in it goes |

Three further principles run through everything:

1. **The database is truth; the filesystem is a projection.** Site definitions live in a
   SQLite DB. Nginx vhosts, FPM pools, logrotate files, cron files and TLS certificates are
   *generated* from it. Config files are outputs, not inputs. You can re-render the server
   from `db.sq3`.

2. **Templates + placeholder processors.** Vhosts are never string-concatenated. A stored
   template contains `{{placeholders}}`; a chain of processor classes each owns exactly one
   placeholder. Unresolved placeholders are stripped at the end. `[1 §7.2]`

3. **Nginx runs as root; PHP does not.** A single root nginx reads every site's files.
   Privilege separation happens at the **FastCGI boundary**, not in nginx.

**What is *not* isolated** — this is not containerisation: the kernel, process table, MySQL
server, loopback network, and all resources (CPU, RAM, disk) are shared. There are no cgroups
and no quotas. `[1 §4.4]`

---

## 2. Component & Port Map

### Services

| Service | Runs as | Purpose |
|---|---|---|
| `nginx.service` | **root** | Public web server (80/443 + internal 8080) |
| `clp-nginx.service` | `clp` | **Separate** nginx serving the panel UI on 8443 |
| `clp-php-fpm.service` | `clp` | PHP 8.1 FPM running the panel's Symfony app |
| `clp-agent.service` | **root** | Go daemon: monitoring, LE renewal, announcements |
| `php<VER>-fpm.service` | root master → per-pool users | One unit **per PHP version**, many pools inside |
| `mysql.service` | mysql | Percona Server 8.4 |
| `varnish` | varnish | Optional per-site cache on `127.0.0.1:6081` |

**The panel and the sites are fully separated** — different nginx instances, different
configs, different FPM masters. A broken site vhost cannot take down the panel.

### Ports

```
   80, 443       nginx      public HTTP/HTTPS (+ QUIC on 443/udp)
   8080          nginx      internal PHP backend server block
   8443          clp-nginx  panel UI
   6081          varnish    cache frontend
   2299          sshd       [LOCAL] non-standard SSH port
   11000-11999   php7.1-fpm pools
   12000-12999   php7.2      13000-13999  php7.3      14000-14999  php7.4
   15000-15999   php8.0      16000-16999  php8.1      17000-17999  php8.2
   18000-18999   php8.3      19000-19999  php8.4      20000-20999  php8.5
```

**The FPM port formula** — the single most important number in the system:

```
base_port(version) = 11000 + (version_index × 1000)
    version_index:  7.1=0  7.2=1  7.3=2  7.4=3  8.0=4
                    8.1=5  8.2=6  8.3=7  8.4=8  8.5=9

base_port + 0   → the "default" pool (runs as www-data, always present)
base_port + N   → site pools, allocated sequentially
```

Allocation is computed at creation time by scanning the pool directory — not reserved
centrally. `[1 §8.2]`

---

## 3. What Gets Created for One Site

Creating `example.com` with user `myuser` on PHP 8.3 produces **exactly** this. `[1 §5.1]`

```
── IN THE HOME DIRECTORY ──────────────────────────────────────────────────────
/home/myuser/                              drwxrwx---  myuser:myuser
├── .bashrc  .profile                      from skeleton; sources /etc/bashrc/bashrc,
│                                          ~/.python_version, sets umask 007
├── .ssh/                                  700
│   ├── authorized_keys                    600, empty
│   └── config                             600, empty
├── htdocs/                                ← ALL WEB CONTENT
│   ├── .gitignore
│   └── example.com/                       ← createRootDirectory()
│       └── index.php                      ← "<?php\n\necho 'Hello World :-)';"
├── logs/
│   ├── nginx/{access.log, error.log}      pre-created empty
│   └── php/error.log
├── backups/databases/                     ← db:backup writes dumps here
└── tmp/                                   ← also pagespeed_cache, remote-backup tar

── IF VARNISH IS ENABLED ──────────────────────────────────────────────────────
/home/myuser/.varnish-cache/{settings.json, controller.php}
/home/myuser/logs/varnish-cache/purge.log

── SYSTEM-WIDE ────────────────────────────────────────────────────────────────
/etc/nginx/sites-enabled/example.com.conf     the vhost           root:root 644
/etc/nginx/ssl-certificates/example.com.crt   certificate         root:root 644
/etc/nginx/ssl-certificates/example.com.key   private key         root:root 644  ⚠
/etc/php/8.3/fpm/pool.d/example.com.conf      FPM pool            root:root 644
/etc/logrotate.d/myuser                       log rotation

── ONLY WHEN THE FEATURE IS USED ──────────────────────────────────────────────
/etc/cron.d/myuser                            if any cron job defined
/etc/nginx/basic-auth/example.com             if basic auth enabled
<root>/.well-known/acme-challenge/<token>     transient, during LE issuance

── DATABASE ROWS ──────────────────────────────────────────────────────────────
site, php_settings, certificate (self-signed at creation)
```

**Two naming keys, used deliberately:**

| Named after the **domain** | Named after the **site user** |
|---|---|
| nginx vhost file | home directory |
| SSL cert + key files | logrotate file |
| FPM pool file *and* pool name | cron file |
| basic-auth file | |

Per-*hostname* resources use the domain; per-*tenant* system resources use the user.

**The skeleton** at `resources/etc/skel/site-user/` seeds the home via `useradd -k`. The empty
log files exist so nginx and PHP never create them as root inside a user-owned directory.
`[1 §5.2]`

---

## 4. Site Lifecycle

### Creation — ordering is deliberate `[1 §6]`

```
 1. parse domain (Public Suffix List) → registrableDomain + subdomain
 2. load vhost template; root_directory = "<domain>/<template suffix>"
 3. generate SELF-SIGNED cert (HTTPS works from second zero)
 4. validate + INSERT site, php_settings, certificate
 5. render the vhost through the processor chain
 ── filesystem work ───────────────────────────────────────────────────────────
 6. useradd -m -k <skel> -s /bin/bash -d /home/<user>   (password: openssl passwd -6)
 7. mkdir -p /home/<user>/htdocs/<domain>/<subpath>
 8. write index.php
 9. allocate FPM port; write /etc/php/<ver>/fpm/pool.d/<domain>.conf
10. write cert + key to /etc/nginx/ssl-certificates/
11. write /etc/logrotate.d/<user>
12. [if varnish] create .varnish-cache structure
13. chown -R + chmod  (770 dirs/files, 700/600 for .ssh)   ← BEFORE the vhost
14. write /etc/nginx/sites-enabled/<domain>.conf           ← vhost LAST
15. reload php<ver>-fpm ; 16. reload nginx
```

Permissions are reset **before** the vhost is written, and the vhost is written **last**, so
nginx never reloads into a half-built site.

### Deletion — reverse discipline `[1 §13]`

```
 0. rm the FPM pool  → reload php-fpm    (workers stop existing)
 1. rm the vhost     → reload nginx      (site unreachable)
 2. rm cert + key
 3. rm basic-auth file
 4. userdel  <ftpUser>     ← NO -r  (home is inside the site home)
 5. userdel -r <sshUser>   ← WITH -r
 6. pkill -u <siteUser>    (background)
 7. rm /etc/cron.d/<user> ; crontab -r -u <user>
 8. userdel -r <siteUser>  ← HOME AND ALL SITE FILES DESTROYED
 9. rm /etc/logrotate.d/<user>
10. drop databases + their users
11. DB rows removed by ON DELETE CASCADE
```

Traffic stops first, identity goes last, databases last of all. **Step 8 is total** — no
confirmation, no soft-delete, no archive.

---

## 5. Nginx

### Layout

```
/etc/nginx/
├── nginx.conf              user root; includes sites-enabled/*.conf
├── global_settings         shared security headers, included by each vhost
├── sites-enabled/          ← ALL VHOSTS, ONE FILE PER DOMAIN (+ default.conf → 444)
├── sites-available/        ← EMPTY. The available/enabled pattern is NOT used.
├── ssl-certificates/       <domain>.crt + .key per site
├── basic-auth/<domain>     htpasswd files
├── cloudflare/ips          "allow <cidr>;" lines
├── blocked_ips             global blocklist, included in http{}
└── conf.d/                 [LOCAL] maps + rate-limit zones
```

### The template engine `[1 §7.2]`

```
Template::build()
  1. regex out every {{placeholder}}
  2. for each with a registered processor: content = processor->process(content)
  3. removeEmptyPlaceholders() — strip the rest
```

| Placeholder | Renders |
|---|---|
| `{{root}}` | `root /home/<user>/htdocs/<root_directory>;` |
| `{{server_name}}` | `server_name <names>;` |
| `{{ssl_certificate}}` / `{{ssl_certificate_key}}` | paths under `/etc/nginx/ssl-certificates/` |
| `{{nginx_access_log}}` / `{{nginx_error_log}}` | paths under `/home/<user>/logs/nginx/` |
| `{{php_fpm_port}}` | the integer `php_settings.pool_port` |
| `{{php_settings}}` | the `PHP_VALUE` ini block |
| `{{php_error_log}}` | `/home/<user>/logs/php/error.log` |
| `{{varnish_proxy_pass}}` | `proxy_pass http://127.0.0.1:8080` or `:6081` |
| `{{settings}}` | composite: basic auth + pagespeed + CF-only + blocked bots/IPs |

### The two-server-block pattern — CloudPanel's signature `[1 §7.3]`

```
 Varnish DISABLED:                    Varnish ENABLED:

 client :443                          client :443
   ▼                                    ▼
 [nginx frontend]                     [nginx frontend]
   │ proxy_pass 127.0.0.1:8080          │ proxy_pass 127.0.0.1:6081
   ▼                                    ▼
 [nginx backend :8080]                [varnish :6081] ──► [nginx backend :8080]
   │ fastcgi_pass 127.0.0.1:19003                           │ fastcgi_pass …:19003
   ▼                                                        ▼
 [php-fpm pool, as siteUser]                          [php-fpm pool, as siteUser]
```

The frontend terminates TLS, serves static files, applies security rules. The backend on
`:8080` is plain HTTP, does `try_files` routing and speaks FastCGI. Both carry the same
`server_name`. This is what lets Varnish be slotted in by changing one `proxy_pass`.

Consequences: `fastcgi_param HTTPS "on"` and `SERVER_PORT 443` are **hardcoded** in the
backend; port 8080 binds `0.0.0.0` and is contained only by the firewall.

### Global config highlights

```nginx
user root;                                    # workers run as root
disable_symlinks if_not_owner from=/home/;    # ← blocks symlink escapes; quiet hero
server_tokens off;  access_log off;           # [LOCAL] access_log off
ssl_protocols TLSv1.2 TLSv1.3;  ssl_session_tickets off;  ssl_stapling on;
real_ip_header CF-Connecting-IP;  set_real_ip_from <CF ranges>;   # correct here
gzip on;  brotli on;
include /etc/nginx/sites-enabled/*.conf;
```

`default.conf` catches unknown hosts: `ssl_reject_handshake on;` + `return 444;`

---

## 6. PHP-FPM and PHP CLI

### Pools — one file per site `[1 §8]`

All ten versions (7.1–8.5) are installed simultaneously, each with its own systemd unit.
A site picks a version; the pool goes in that version's `pool.d/`; only that service reloads.

```ini
[<domain>]
listen = 127.0.0.1:<port>
user = <siteUser>
group = <siteUser>
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 250          # [LOCAL here: 15]
pm.process_idle_timeout = 10s  # [LOCAL: 30s]
pm.max_requests = 100          # [LOCAL: 500]
request_terminate_timeout = 7200s   # [LOCAL: 60s]
pm.status_path = /status
catch_workers_output = yes
```

`pm = ondemand` is the right call for many mostly-idle tenants — memory is only consumed by
sites currently serving. But `max_children = 250` per site with **no global cap and no memory
ceiling** is the biggest resource-governance gap in the design.

**Hand-edits to pool tuning survive** — CloudPanel writes the pool at creation and deletes it
at teardown, but never rewrites tuning.

### Per-site PHP ini — via `PHP_VALUE`, not php.ini `[1 §8.4]`

```nginx
fastcgi_param PHP_VALUE "
error_log=/home/<user>/logs/php/error.log;
memory_limit=512M;
max_execution_time=60;
...";
```

Three consequences worth internalising:

- Changing a site's PHP settings means **rewriting the vhost and reloading nginx** — not
  touching PHP.
- These are `PHP_VALUE`, not `PHP_ADMIN_VALUE` — **tenants can override them with `ini_set()`**.
- **CLI runs get none of this**, because there is no FastCGI request.

### PHP CLI is global — the most common real-world gotcha `[1 §9]`

```
/usr/bin/php → /etc/alternatives/php → /usr/bin/php8.4     (server-wide, manual mode)
```

**FPM version and CLI version are completely independent.** A site on FPM 8.2 gets PHP 8.4
when its user types `php artisan`. CloudPanel solves this for Node.js (NVM per home) and
Python (`~/.python_version`) but **not for PHP**.

Fixes: pin the binary (`/usr/bin/php8.3 artisan …`), or create `~/bin/php` as a symlink —
the stock `~/.profile` already puts `$HOME/bin` first on `PATH`.

---

## 7. SSL/TLS

CloudPanel implements **ACME v2 itself** — no certbot, no lego, no acme.sh. `[1 §10]`

```
src/Site/Ssl/LetsEncryptClient.php   hand-rolled ACME v2 on Guzzle
Endpoints: acme-v02.api.letsencrypt.org  (+ staging for dry runs)
ACME account key: ONE per server, in config['le_private_key']
```

**Issuance flow** — the notable idea is the pre-flight:

```
1. resolve domain → SAN list
2. DRY RUN against STAGING: register, order, write challenges, validate
      → fail fast without burning production rate limits
3. REAL RUN against production: same sequence
4. NEW keypair + CSR for the certificate (distinct from the account key)
5. store in DB → write /etc/nginx/ssl-certificates/<domain>.{key,crt} → reload
```

**HTTP-01 challenge** at `<root>/.well-known/acme-challenge/<token>`, works because every
template carries this unconditionally:

```nginx
location ~ /.well-known { auth_basic off; allow all; }
```

`auth_basic off` + `allow all` means basic auth, Cloudflare-only mode and IP blocking are all
bypassed for the challenge path — otherwise renewal would break the moment a site enabled
basic auth.

**Storage:** certificates live **in the database** (`private_key`, `certificate`,
`certificate_chain` as CLOBs) and are *projected* to `/etc/nginx/ssl-certificates/`. Backup =
DB + `/home`, nothing else. ⚠️ Key files are mode **0644** — world-readable.

**Renewal** runs inside the `clp-agent` Go scheduler — there is no cron entry for it.

---

## 8. Databases

One shared MySQL/Percona instance. **Tenant separation is by MySQL grants only** — no
per-tenant server, no resource governor. `[2 §1]`

```sql
database_server (engine, version, host, port, user_name, password ENCRYPTED, certificate)
database        (site_id, database_server_id, name UNIQUE)
database_user   (database_id, user_name UNIQUE, password ENCRYPTED, permissions 'rw'|'ro')
```

A site may own **several** databases; a database belongs to exactly one site.

**Credentials are encrypted, not hashed** (`defuse/php-encryption`, keyed from `APP_SECRET`
in `.env`) — they must be recoverable to connect. **Consequence: `db.sq3` is useless without
`.env`.** Restore both together, always.

### Grants issued on `db:add`

```sql
CREATE USER '<u>'@'%' IDENTIFIED WITH mysql_native_password BY '<pw>';   -- MySQL
GRANT USAGE ON *.* TO '<u>'@'%';
-- rw:
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
      CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER ON `<db>`.* TO '<u>'@'%';
ALTER USER `<u>`@`%` REQUIRE NONE WITH MAX_QUERIES_PER_HOUR 0 MAX_CONNECTIONS_PER_HOUR 0
                                       MAX_UPDATES_PER_HOUR 0 MAX_USER_CONNECTIONS 0;
-- ro:
GRANT SELECT ON `<db>`.* TO '<u>'@'%';
FLUSH PRIVILEGES;
```

Three things to note: host is **`'%'`** not `localhost`; `mysql_native_password` is
**deprecated** in MySQL 8.4; `REQUIRE NONE WITH MAX_* 0` zeroes all per-user resource limits.

### Export / import

```bash
# export — compression inferred from the .gz suffix
mysqldump --force --opt --single-transaction --quick -h<host> -P<port> -u<user> \
          -p< /tmp/.clp_tmp_<sha1>  <db> [| gzip] | tee <file> > /dev/null

# import — credentials via --defaults-extra-file, client dropped to www-data
sudo setfacl -m u:www-data:--- /usr/bin/sh          # ← hardening: revoke shell first
sudo bash -c "cat <file>" | sudo -u www-data bash -c \
  "mysql --defaults-extra-file=<tmpfile> -f -h<host> -u<user> -P<port> <db>"
```

**Passwords never appear on the command line** — written to `chmod 0400` temp files, fed via
stdin redirect or `--defaults-extra-file`, deleted in `__destruct()`. Good pattern, worth
copying. Both use `--force`/`-f`, so both continue past errors.

⚠️ **`db:delete` also deletes that database's local dumps** (`/home/<user>/backups/databases/<db>/`).
No flag, no confirmation.

---

## 9. Backups

### There are three separate systems — none enabled by default `[2 §2]`

| # | System | Covers | Trigger | Retention |
|---|---|---|---|---|
| **A** | Panel self-backup | Panel app + `db.sq3` | `create_backup.sh`, on **update** | keep 3 |
| **B** | Local DB backup | MySQL dumps per site | `clpctl db:backup` — **you must schedule it** | 7 days |
| **C** | Remote backup | Site homes + vhosts (runs B first) | `/etc/cron.d/clp-rclone` | configurable |

```
   ┌──── C: remote-backup:create ────────────────────────────┐
   │  1. calls B internally                                  │
   │  2. per site: tar(home + vhost) ─► rclone ─► ☁         │
   │  3. purge remote dirs older than retention              │
   └─────────────────────────────────────────────────────────┘
                          ▲ C invokes B; B never invokes C
   ┌──── B: db:backup ───────────────────────────────────────┐
   │  per database ─► mysqldump ─► /home/<user>/backups/     │
   └─────────────────────────────────────────────────────────┘
   ┌──── A: create_backup.sh ────────────────────────────────┐
   │  panel app + db.sq3 ─► /home/clp/backups/  (on upgrade) │
   └─────────────────────────────────────────────────────────┘
```

**Running C gives you B for free; running B gives you nothing off-server.**

### Paths

```
B:  /home/<user>/backups/databases/<db>/<Y-m-d>/<db>_<unixts>.sql.gz
C:  <bucket>/<storageDir>/<Y-m-d>/<H.i>/home/<siteUser>/backup.tar
A:  /home/clp/backups/<Y-m-d_H-M-S>/app/{files/, data/db.sq3}
```

### The best idea in the codebase: self-describing archives

Before tarring, two sidecar files are written into the site home so they land **inside** the
archive:

- **`site-settings.json`** — type, domain, rootDirectory, siteUser, and the full
  `phpSettings` block (version + every ini value)
- **`site-vhost`** — a copy of the rendered nginx vhost

Combined with database dumps being taken **first** (so they ride inside the same tar), a
single `backup.tar` restores a site onto a bare server with no control-plane database.
Excludes: `.ssh`, `tmp`, `logs`.

### Panel self-backup uses `sqlite3 .backup`, not `cp`

That is SQLite's online backup API — transactionally consistent while the panel writes.
Copying a live `.sq3` with `cp` can corrupt it.

### What no system backs up

`/etc/php/*/fpm/pool.d/*.conf` (your pool tuning), global nginx config
(`nginx.conf`, `conf.d/`), MySQL server config, ufw rules. On this host that means the
**[LOCAL]** rate-limit and bad-bot files exist in exactly one place.

---

## 10. Cron

### Two independent layers `[2 §3]`

```
LAYER 1  DB table `cron_job` ──generated──► /etc/cron.d/<siteUser>   (runs as the site user)
LAYER 2  /etc/cron.d/clp-rclone            → remote-backup:create
         clp-agent internal Go scheduler   → LE renewal, monitoring  (NO cron file)
```

User crontabs are not used at all — `/var/spool/cron/crontabs/` is empty.

### Format

```cron
MAILTO=""
<min> <hour> <day> <month> <weekday> <siteUser> <command>
```

`/etc/cron.d` requires the **user field** (6th column) — that is what makes the job run as
the site user. `MAILTO=""` means output and errors are discarded.

The file is rewritten wholesale from the DB on every change; a site user *can* also create
their own crontab by hand, invisible to the UI until site deletion (`crontab -r` cleans it).

### ⚠️ The PHP version trap

Because `/usr/bin/php` is a server-wide symlink (§6), a cron job using bare `php` runs on the
machine default, not the site's FPM version. On this server **3 of 9 PHP cron jobs run on a
different version than their web requests.** `[2 §3.6]`

The `wget -q -O- https://site/cron` pattern sidesteps this entirely by going through
nginx→FPM, guaranteeing the site's real version and ini settings.

---

## 11. Access Control & Security

### Panel users are database rows, not Linux accounts `[3 §1]`

```sql
user (user_name UNIQUE, email UNIQUE, password HASHED, role, mfa, mfa_secret, status)
user_sites (user_id, site_id)     -- scopes ROLE_SITE_MANAGER to specific sites
Roles: ROLE_ADMIN | ROLE_SITE_MANAGER | ROLE_USER
```

Password hashing uses Symfony's `'auto'` hasher (bcrypt/argon2id) — genuinely modern, unlike
the DES `crypt()` used for **site** basic-auth files.

Four authenticators: `LoginFormAuthenticator` (with CSRF badge), `MfaAuthenticator` (TOTP),
`AutoLoginAuthenticator` (one-shot token file), `ApiTokenAuthenticator` (Bearer token).

### Two review findings `[3, Security Review]`

Both verified by **static inspection only** — no exploitation, nothing changed.

**Finding 1 (High) — the API token check can be bypassed.** Three facts chain:

1. The panel's nginx has `set_real_ip_from 0.0.0.0/0;` with `real_ip_header X-Forwarded-For` —
   it accepts that header from *anyone* and overwrites `$remote_addr`.
2. `fastcgi_params` passes the rewritten value as `REMOTE_ADDR`; no Symfony trusted-proxy
   config exists, so `getClientIp()` returns it verbatim.
3. `ApiTokenAuthenticator` **skips token validation entirely** when the client IP is
   `127.0.0.1` or `172.17.0.1`.

So the API's authentication decision rests on a client-controlled value. The audit log shows
*why* the wildcard exists — the panel is reached through Cloudflare on a custom domain, and
someone wanted real client IPs. Legitimate goal, dangerous implementation. The **site** nginx
already does this correctly (explicit CF ranges + `CF-Connecting-IP`); only the panel nginx
uses a wildcard.

**Finding 2 (Low–Moderate)** — `AutoLoginAuthenticator` concatenates an unvalidated `token`
query parameter into a filesystem path, then `@unlink`s it in a `finally`. `/autologin` is
`PUBLIC_ACCESS`. Arbitrary-file-deletion / existence-oracle risk as `clp`, not an auth bypass.

Both are in **stock CloudPanel 2.5.4**, not in local customisations.

### Other security posture notes

| Strength | Weakness |
|---|---|
| Per-site UID + FPM pool | TLS private keys mode `0644` — world-readable |
| `/home` mode `0711` — no enumeration | nginx workers run as **root** |
| `disable_symlinks if_not_owner from=/home/` | Cross-tenant FastCGI reachable on loopback¹ |
| Default vhost rejects unknown hosts (444) | No resource limits per tenant |
| Panel nginx/FPM run as `clp`, not root | `clp` has `NOPASSWD: ALL` — panel RCE = root |
| Panel passwords bcrypt/argon2id | Site basic-auth uses DES `crypt()` |
| MFA supported | phpMyAdmin exempt from panel basic auth |
| ACME staging dry-run | ACME client sets `verify => false` |

¹ `listen.allowed_clients = 127.0.0.1` filters by *address*, not by user — a local tenant can
speak FastCGI to another site's pool port. **Unix sockets with `listen.owner`/`mode 0660`
would fix this and remove port allocation entirely.** This is the single highest-value change
to the architecture.

### Firewall

CloudPanel wraps **ufw**; `firewall_rule` is the source of truth, ufw the projection. Rules
are rendered as `ufw [--dry-run] allow proto tcp from <ip> to any port <range>`, with success
detected by grepping output for `ERROR`. The `--dry-run` pattern mirrors `nginx -t` — validate
before commit.

---

## 12. Monitoring & Audit

### The audit log is genuinely good `[3 §4]`

```sql
event (created_at, user_name, user_role, event_name, event_data, source_ip_address, user_agent)
```

Actor, role, action, payload, source IP, user agent for every state-changing operation.
700+ rows here across ~25 event types (`LOGIN`, `SITE_PHP_CREATE`, `SITE_DATABASE_ADD`,
`SITE_CERTIFICATE_INSTALL`, `SITE_DELETE`, `FIREWALL_RULE_UPDATE`, …).

Two caveats: naming is inconsistent (`EVENT_SITE_VHOST_UPDATE` vs `SITE_*`), and **`clpctl`
CLI invocations are not audited** — only web actions appear.

### Notifications

`notification (hash, severity, subject, message, is_read)` — `hash` is a dedup key.
This is the **only** place asynchronous failures surface (e.g. `"Remote Backup failed: <domain>"`),
because the cron invocation discards all output.

### Monitoring is whole-instance only

Four parallel tables (`instance_cpu`, `instance_memory`, `instance_disk_usage`,
`instance_load_average`), each just `(created_at, value)`. Collected by `clp-agent`.

**290,135 rows in `instance_cpu` alone** — roughly 200 days at one sample/minute, and a
significant share of the 73 MB `db.sq3`.

**Not collected:** per-site CPU/memory/disk, FPM pool status, request rates, certificate
expiry alerts, backup success. Every pool already sets `pm.status_path = /status` — nothing
scrapes it. That is the cheapest available route to per-tenant visibility.

---

## 13. The Privileged Execution Layer

The part most worth studying for your own build. Every panel faces the same problem: **an
unprivileged web app needs to make root-level system changes.** `[3 §6]`

```
src/System/
├── Command.php          abstract base — a command is an OBJECT, not a string
├── CommandExecutor.php  runs it via Symfony Process (cwd /tmp/, default timeout 30s)
├── Process.php          extends Symfony Process; owns success semantics
└── Command/             ~50 concrete classes, one per operation
```

Each privileged operation is a named class with typed setters. Callers never build shell
strings; `escapeshellarg()` lives in one place per operation; success interpretation is
per-operation (`ufw` greps `ERROR`, `useradd` expects empty output, `tar` tolerates exit 1).

**Privilege boundary:**

```
Browser → clp-nginx (as clp) → clp-php-fpm (as clp) → sudo <cmd> → root
                                    ↑ /etc/sudoers.d/cloudpanel: clp ALL=(ALL) NOPASSWD: ALL
any user → clpctl → sudo /usr/bin/clpctlWrapper → php bin/clpctl (as root)
                    ↑ ALL ALL=(ALL) NOPASSWD: /usr/bin/clpctlWrapper
```

The panel running as `clp` rather than root is good — but `clp` has `NOPASSWD: ALL`, so the
boundary is a convention, not a restriction. **Panel code execution = root.**

### Hardening touches worth copying

- Secrets via `0400` temp files + stdin, never argv (`mysqldump -p< file`, `openssl passwd -6`)
- `setfacl -m u:www-data:--- /usr/bin/sh` before running the MySQL client as `www-data`
- `--dry-run` / `nginx -t` before applying
- `runInBackground(true)` for operations that may block (killing user processes)
- Treating tar's exit 1 (`file changed as we read it`) as success — correct for live backups

### The flaw

Several critical command classes implement:

```php
public function isSuccessful(): bool { return true; }
```

Confirmed hardcoded in `CreateDatabaseDumpCommand`, `ImportDatabaseDumpCommand`,
`TarCreateCommand`, `RcloneCopyCommand`, `DeleteOldFilesRecursiveCommand`. Combined with
`&> /dev/null` + `MAILTO=""` on generated cron and `remote-backup:create` exiting 0 when
unconfigured, **the entire backup and database path cannot report failure.**

---

## 14. Cheat Sheet: Constants & Formulas

```
FPM PORT          base = 11000 + (version_index × 1000);  +0 = default pool, +N = sites
                  allocation = max(existing ports in that version's pool.d) + 1

SITE ROOT         /home/<siteUser>/htdocs/<root_directory>
                  where root_directory = "<domain>/<template suffix>"

HOME PERMS        /home            0711 root:root      (traversable, not listable)
                  /home/<user>     0770 user:user      (dirs AND files 770)
                  /home/<user>/.ssh 0700, files 0600
                  umask 007 in ~/.bashrc
                  group <user> contains www-data as a member

FILE NAMED BY DOMAIN    /etc/nginx/sites-enabled/<domain>.conf
                        /etc/nginx/ssl-certificates/<domain>.{crt,key}
                        /etc/php/<ver>/fpm/pool.d/<domain>.conf
                        /etc/nginx/basic-auth/<domain>
FILE NAMED BY USER      /home/<user>/  /etc/logrotate.d/<user>  /etc/cron.d/<user>

SKELETON          resources/etc/skel/site-user/   (site users)
                  resources/etc/skel/ssh-user/    (extra SSH users; symlinks htdocs/logs/backups)

TIMEOUTS          default 30s | mysqldump/import 7200s | tar & rclone 21600s
                  rclone lsjson 20s | permissions reset 90s | backup cleanup find 360s

RETENTION         db:backup 7 days | panel self-backup keep 3 | logrotate 7 days daily
                  remote backup: configurable days, keyed on dir name parsing as a date

ACME              account key: ONE per server (config['le_private_key'])
                  challenge: <root>/.well-known/acme-challenge/<token>
                  staging dry-run before every production issuance

CERT TYPES        1 = SELF_SIGNED   2 = LETS_ENCRYPT   3 = IMPORTED
DB PERMISSIONS    'rw' | 'ro'      GRANT host is always '%'
BACKUP PROVIDERS  amazon-s3 | google-drive | digital-ocean-spaces | dropbox | sftp
                  | wasabi | custom-rclone        Frequency: daily | 3 | 6 | 12 (hours)
```

---

## 15. Live State of This Server

Consolidated audit across all three documents, as of 2026-09-06. Recorded as observation.

### Platform

```
CloudPanel     2.5.4, stable channel     instance_uid 836b167a7d073896
OS             Ubuntu 24.04.4 LTS        Kernel 6.17.0-22-generic
Database       Percona Server 8.4.10-10
Sites          ~30 (php, reverse-proxy, static)
PHP versions   7.1 … 8.5 all installed; sites use 8.2/8.3/8.4/8.5
Panel domain   manage.cijagani.in        Timezone Asia/Kolkata
```

### Backup coverage — the notable gap

| System | State | Evidence |
|---|---|---|
| A — Panel self-backup | Partial | 3 dirs in `/home/clp/backups/`, dated to **updates**, not a schedule |
| B — Local DB backup | ❌ Never run | Every `/home/*/backups/databases/` holds only `.gitignore` |
| C — Remote backup | ❌ Not configured | No `remote_backup_*` config keys; no `/etc/cron.d/clp-rclone` |

`rclone` is installed but has no config directory — the feature was never set up.

> **There are currently no database backups on this server.**
>
> This is worth reading as an *architectural* observation rather than a to-do: it is the
> **default outcome** of CloudPanel's design, not a misconfiguration. None of the three systems
> self-enables, `remote-backup:create` exits 0 when unconfigured, and the command classes
> hardcode success. A stock install looks healthy while backing up nothing.

### Access control

```
Panel users    2 — cijagani (ROLE_ADMIN, mfa=0), webdev (ROLE_USER, mfa=0)   ← MFA off on both
API tokens     0        ← nothing depends on the API endpoint
Panel basic auth   not enabled
Login events   309, from 22 distinct source IPs
```

The source IPs are mostly a **rotating residential range** (13 × `106.215.x`, 2 × `1.38.x`,
plus IPv6), with four **Cloudflare** addresses and `127.0.0.1`. A static IP allowlist on 8443
would therefore not suit this environment — identity-based access control (Cloudflare Access,
VPN) fits the actual usage pattern. The Cloudflare addresses also explain the
`set_real_ip_from 0.0.0.0/0` in the panel nginx that underlies Finding 1.

### Network exposure

```
80/tcp, 443/tcp, 443/udp   ALLOW Anywhere     correct for a web server
8443/tcp                   ALLOW Anywhere     panel + phpMyAdmin + API, world-reachable
2299/tcp                   ALLOW Anywhere     [LOCAL] SSH on a non-standard port
```

FPM ports (11000–20999) and the internal backend (8080) are **not** in the allowlist — they
are contained by the firewall rather than by loopback binding, which makes these ufw rules
more load-bearing than they look.

### Cron

```
Site cron files   10        cron_job rows  10
clp-rclone        absent
PHP pinning       3 of 9 PHP jobs run on a different version than their FPM pool
```

Specifically: `cijagani-sales` (FPM 8.2) and `corbitaltech-campaigns` (FPM 8.3) both run on
8.4 via bare `php`; `cijagani-help` is pinned but to 8.3 while its pool is 8.2.

### Monitoring

```
instance_cpu rows   290,135  (~200 days @ 1/min)      db.sq3  73 MB
Notifications       0 unread — nothing has ever reported a failure
Blocked IPs / bots  0 / 0       Basic-auth entries  1 (nas.cijagani.in)
Firewall rules      8
Certificates        32 self-signed, 32 Let's Encrypt, 3 imported
```

### [LOCAL] customisations found

- `nginx.conf`: tuned `open_file_cache`, `keepalive_requests 10000`, `gzip_comp_level 5`,
  a `429` error page, `access_log off` at http level (so **site access logs are not written**)
- `conf.d/`: `bad-bots.conf`, `rate-limits.conf` (6 zones), `security-headers.conf`,
  `cloudflare-ips.conf` — ⚠️ these define maps and zones but are **inert unless referenced**
  by a server block, and are not backed up by anything
- FPM pool tuning: `max_children` 250→15, `request_terminate_timeout` 7200s→60s, etc.
- `/etc/logrotate.d/mysql-slow-corbital` — custom slow-query-log rotation
- SSH on port 2299
- `/home/clp/backups/manual-rename-*/` — hand-made `.bak` copies

---

## 16. Reviewer's Assessment

One engineer's verdict after reading the running system and the source. `[3 §11]`

### Overall

> **CloudPanel is a well-architected product with a consistently weak feedback layer.**

The structural decisions are genuinely good — better than most control panels, commercial ones
included. The recurring flaw is not in *what* it does but in *whether it tells you it worked*.
That single axis explains almost every problem across the series, including why this server has
no backups while every surface reports success.

| Area | Assessment |
|---|---|
| Tenant isolation model | **Strong** — one Linux user per site is the right primitive |
| Config generation | **Strong** — DB-as-truth, template/processor engine, validate-before-apply |
| Privileged execution | **Good structure, weak verification** |
| Backup design | **Good ideas, poor defaults** |
| Database layer | **Adequate** — correct mechanics, permissive grants |
| Access control | **Adequate with one real flaw** |
| Observability | **Weak** — whole-instance only, no per-tenant anything |
| Resource governance | **Absent** — no cgroups, no quotas, no caps |

### What I'd copy without hesitation

1. **One Linux user per site** — highest-leverage decision in the whole design
2. **Database as truth, filesystem as projection** — re-render the server from `db.sq3`
3. **The placeholder/processor template engine** — clean, testable, extensible
4. **Self-describing backup archives** — sidecar metadata + dumps inside the tar
5. **Typed command objects** instead of scattered `shell_exec`
6. **Validate before apply** — `nginx -t`, `ufw --dry-run`, ACME staging dry-run
7. **Secrets never on the command line** — `0400` temp files, stdin, destructor cleanup
8. **Teardown ordering** — pool → vhost → processes → user → databases
9. **The audit log schema** — actor, role, action, payload, source IP, user agent
10. **Panel and tenant stacks fully separated**

### What I'd do differently

1. **Make failure loud.** Replace every hardcoded `isSuccessful() { return true; }`, stop
   discarding cron output, exit non-zero when a scheduled job is unconfigured, and give every
   scheduled operation a durable success record with alerting on its *absence*.
2. **Enable backups by default**, or refuse to complete setup without a target.
3. **Unix sockets instead of TCP for FPM pools** — fixes cross-tenant FastCGI access *and*
   removes port allocation entirely.
4. **Never authenticate on client IP**, and never `set_real_ip_from 0.0.0.0/0`.
5. **Per-tenant resource limits** — systemd slices + filesystem quotas.
6. **Per-tenant observability** — scrape the FPM `pm.status_path` that already exists.
7. **`php_admin_value` for limits**, not `PHP_VALUE` that tenants can override.
8. **Per-site PHP CLI** — a `~/bin/php` symlink at site creation, ten lines of code.
9. **Tighten grants** — `'localhost'` not `'%'`, real `MAX_USER_CONNECTIONS`, expose the `ro` path.
10. **Replace `NOPASSWD: ALL`** with a root daemon speaking a typed, validated protocol.

### The pattern worth internalising

> **CloudPanel is excellent at making the right thing happen, and poor at noticing when it
> didn't.**

Structure without feedback produces exactly what this server demonstrates: a correct,
well-organised, cleanly-generated configuration serving thirty sites reliably — with no
database backups, three cron jobs on the wrong PHP version, and an authentication check that
trusts a client-supplied header. None of it announced itself, because nothing was designed to.

The structural ideas will get you a long way in a short time. The feedback discipline is what
determines whether you can trust the result a year later.

### Fair context

- **The isolation model genuinely works.** Thirty tenants, real separation, no container
  overhead, and a teardown path that actually cleans up. Plenty of expensive panels do worse.
- **Several findings are shipped defaults, not neglect.** The backup gap, the missing resource
  limits and the CLI/FPM version split are all stock behaviour. An operator following the
  documented workflow lands exactly here — a product design problem, not an administration one.

---

## 17. Blueprint Checklist

A condensed build guide for your own server management system.

### Data model — minimum viable

```sql
site            (type, domain_name UNIQUE, system_user UNIQUE, root_directory,
                 vhost_template, template_name, reverse_proxy_url)
php_settings    (site_id UNIQUE, php_version, pool_socket, memory_limit, …)
certificate     (site_id, type, private_key, certificate, chain, expires_at, is_active)
db / db_user / database_server
cron_job        (site_id, minute, hour, day, month, weekday, command TEXT)
event           (actor, role, action, payload, source_ip, user_agent)
```

Keep the two-key discipline: **domain** keys per-hostname artifacts, **system user** keys
per-tenant artifacts.

### Creation sequence

```
1. validate; resolve domain with a Public Suffix List library
2. BEGIN transaction; insert rows; allocate socket path
3. useradd -m -k <skel> -s /bin/bash -d /home/<user>
4. mkdir -p the web root; write a placeholder index
5. self-signed cert → DB → /etc/nginx/ssl-certificates/
6. write FPM pool → reload php<ver>-fpm
7. write logrotate file
8. chown -R + chmod                         ← BEFORE the vhost
9. render vhost → temp → nginx -t → move into place → reload nginx
10. COMMIT     (on failure at any step, run the teardown for what exists)
```

### Copy verbatim

- [ ] One Linux user per site; `/home` `0711`; `770` + `umask 007`
- [ ] Skeleton directory with pre-created empty log files
- [ ] Placeholder/processor template engine; strip unresolved tokens
- [ ] DB-as-truth, files-as-projection — certificates in the DB
- [ ] Self-describing backup archives (sidecar JSON + vhost + dumps inside the tar)
- [ ] ACME staging dry-run before production issuance
- [ ] Unconditional, auth-exempt `/.well-known` in every template
- [ ] Teardown ordering: pool → vhost → processes → user → databases
- [ ] `disable_symlinks if_not_owner from=/home/`
- [ ] Catch-all default vhost: `ssl_reject_handshake` + `return 444`
- [ ] Separate panel stack from tenant stack
- [ ] Typed command objects; secrets via `0400` files; validate-before-apply

### Change

| CloudPanel does | Do instead |
|---|---|
| TCP ports for FPM pools | Unix sockets, `listen.owner`/`mode 0660` |
| Scan-and-increment port allocation (buggy comparator) | DB transaction, or no ports at all |
| Cert keys `0644` | `0640` or `0600` |
| `user root;` in nginx | `www-data`, using the existing per-site group membership |
| `PHP_VALUE` for everything | `php_admin_value` for limits |
| DES `crypt()` for basic auth | bcrypt (`$2y$`) |
| `sudo NOPASSWD` for `ALL` users | Restrict to a group; or a root daemon with a typed protocol |
| ACME with `verify => false` | Verify TLS |
| Global PHP CLI | Per-user `~/bin/php` symlink; pin the binary in generated cron |
| `isSuccessful() { return true; }` | Real exit-code checks |
| Backups opt-in and silent | On by default; loud on failure; verified after upload |
| `userdel -r` with no snapshot | Export/archive before destroying |
| Ten PHP versions installed | Only what is in use |
| Whole-instance monitoring only | Per-tenant metrics; scrape FPM `pm.status_path` |
| No resource limits | systemd slices + disk quotas |

### Add what CloudPanel lacks

- [ ] **Per-tenant resource limits** — `MemoryMax`, `CPUQuota`, `TasksMax` per site slice; disk quotas
- [ ] **Backup manifests** — run id, per-site archive, size, sha256, databases, PHP version
- [ ] **Three-tier retention** — hourly 2d / daily 30d / monthly 12m
- [ ] **Automated restore tests** — a backup never restored is a hypothesis
- [ ] **Dead-man's-switch monitoring** — alert on the *absence* of a success record
- [ ] **Certificate expiry alerting** — the data already exists, nothing uses it
- [ ] **Config drift detection** — re-render every vhost, diff against disk
- [ ] **Cron execution history** — start, end, exit code per run; a "run now" button
- [ ] **Audit CLI actions**, not just web actions
- [ ] **Read-only DB users** alongside every read-write one

---

## Appendix: One-Page Diagnostic Reference

```bash
DB=/home/clp/htdocs/app/data/db.sq3

# ── SITES ─────────────────────────────────────────────────────────────────
sqlite3 -header -column $DB "SELECT s.domain_name, s.user, s.type, p.php_version, p.pool_port
  FROM site s LEFT JOIN php_settings p ON p.site_id=s.id ORDER BY s.user;"
nginx -T | awk '/server_name <domain>/,/^}/'      # effective vhost
ps -u <siteUser> -o pid,etime,rss,cmd             # per-site processes
du -sh /home/*/htdocs | sort -h                   # per-site disk

# ── PHP ───────────────────────────────────────────────────────────────────
for v in /etc/php/*/fpm/pool.d; do grep -H '^listen = ' $v/*.conf; done   # port map
readlink -f /usr/bin/php                          # what bare `php` resolves to
nginx -t && php-fpm8.3 -t                         # validate before reload

# ── SSL ───────────────────────────────────────────────────────────────────
sqlite3 -header -column $DB "SELECT s.domain_name, c.type, c.expires_at
  FROM certificate c JOIN site s ON s.id=c.site_id
  WHERE c.expires_at < datetime('now','+21 days') ORDER BY c.expires_at;"

# ── DATABASE ──────────────────────────────────────────────────────────────
sqlite3 -header -column $DB "SELECT d.name, s.domain_name, s.user
  FROM \"database\" d JOIN site s ON s.id=d.site_id;"
mysql -e "SELECT table_schema, ROUND(SUM(data_length+index_length)/1024/1024,1) mb
  FROM information_schema.tables GROUP BY table_schema ORDER BY mb DESC;"

# ── BACKUPS ───────────────────────────────────────────────────────────────
find /home/*/backups/databases -name '*.sql.gz' -mtime -1 -ls    # empty = none last night
sqlite3 $DB "SELECT \"key\", value FROM config WHERE \"key\" LIKE 'remote_backup%';"
ls -la /etc/cron.d/clp-rclone /root/.config/rclone/rclone.conf 2>/dev/null
sqlite3 $DB "SELECT COUNT(*) FROM notification WHERE is_read=0;"  # async failure signal

# ── CRON ──────────────────────────────────────────────────────────────────
sqlite3 -header -column $DB "SELECT s.user, c.minute, c.hour, c.command
  FROM cron_job c JOIN site s ON s.id=c.site_id;"
grep -h -o '/usr/bin/php[0-9.]*' /etc/cron.d/* | sort | uniq -c   # version audit
grep CRON /var/log/syslog | grep <siteUser> | tail

# ── SECURITY / AUDIT ──────────────────────────────────────────────────────
sqlite3 -header -column $DB "SELECT id,user_name,role,mfa,status FROM user;"
sqlite3 -header -column $DB "SELECT created_at,user_name,event_name,source_ip_address
  FROM event ORDER BY id DESC LIMIT 40;"
sudo ufw status numbered
grep -n 'set_real_ip_from\|real_ip_header' /home/clp/services/nginx/nginx.conf

# ── PANEL ─────────────────────────────────────────────────────────────────
systemctl status clp-nginx clp-php-fpm clp-agent
tail -f /home/clp/logs/php/error.log
```

---

*End of summary. See documents 1–3 for full detail on any section.*
