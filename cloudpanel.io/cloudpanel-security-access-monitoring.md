# CloudPanel Architecture — Access Control, Security, Monitoring & the Execution Layer

> **Third and final part of the series.**
> Companions: `cloudpanel-architecture.md` (sites, nginx, SSL, PHP-FPM, isolation) and
> `cloudpanel-database-backup-cron.md` (database, backup, cron).
>
> Same method: reverse-engineered from the **live CloudPanel 2.5.4 install** on this server,
> reading the running configuration and decoding the obfuscated application source.
> **[LOCAL]** marks modifications specific to this host.
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

## Security Review — Authentication Layer

Two issues found while documenting the authentication layer, verified by **static inspection
of the configuration and source on this host**. I did not attempt to exploit either, and
nothing was changed.

Both are recorded here as **review findings**. The mitigations are written out because
understanding *why* each design fails is the useful part for your own build — not because
anything needs doing today.

### Finding 1 — Panel API token check can be bypassed via a forwarded-IP header

**Severity: High.** Three facts combine into one chain.

**(a)** The panel's own nginx trusts `X-Forwarded-For` from *every* source.
`/home/clp/services/nginx/nginx.conf`:

```nginx
real_ip_recursive on;
set_real_ip_from 127.0.0.1;
set_real_ip_from 10.0.0.0/8;
set_real_ip_from 172.16.0.0/12;
set_real_ip_from 192.168.0.0/16;
set_real_ip_from 0.0.0.0/0;        # ← trusts ALL sources
real_ip_header X-Forwarded-For;
```

`set_real_ip_from 0.0.0.0/0` tells the realip module to accept the `X-Forwarded-For` header
from any client and overwrite `$remote_addr` with it. With `real_ip_recursive on` the chain is
walked from the right skipping trusted addresses — and since every address is trusted, the
value that survives is the leftmost, which is entirely client-supplied.

**(b)** That rewritten address reaches PHP. `fastcgi_params` line 16:

```nginx
fastcgi_param REMOTE_ADDR $remote_addr;
```

No Symfony trusted-proxy configuration exists anywhere in the app (`.env`, `config/`, `src/`,
`public/index.php` — all checked), so `$request->getClientIp()` returns `REMOTE_ADDR` verbatim.

**(c)** `src/Security/ApiTokenAuthenticator.php` skips token validation for two client IPs:

```php
private static array $whitelistedIps = ["172.17.0.1", "127.0.0.1"];

public function authenticate(Request $request): Passport {
    $clientIP = $request->getClientIp();
    if (in_array($clientIP, self::$whitelistedIps)) {
        // → returns a valid passport for user "api" WITHOUT checking any token
    }
    // otherwise: require Bearer token, look it up in api_token, else "Unauthorized"
}
```

`config/packages/security.yaml` routes `^/api` through this authenticator, and
`src/Controller/ApiController.php` is present and served.

**The consequence:** the API's authentication decision rests on a value the client controls.
`172.17.0.1` is the default Docker bridge gateway; `127.0.0.1` is loopback. Neither is a
trustworthy assertion of origin once (a) is in place.

**How this would be corrected.** The audit log shows this panel is reached through Cloudflare on
`manage.cijagani.in` (§8.1), so the wildcard was almost certainly added to recover real client
IPs behind the CDN. That goal is legitimate — keep it, but scope the trust:

```nginx
# /home/clp/services/nginx/nginx.conf
# REPLACE:
#   set_real_ip_from 0.0.0.0/0;
#   real_ip_header X-Forwarded-For;
# WITH explicit Cloudflare ranges and CF's own header:
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
# … the full published list from https://www.cloudflare.com/ips …
real_ip_header CF-Connecting-IP;
```

The **site** nginx already does exactly this correctly (companion doc §7.5) — only the panel
nginx uses a wildcard, so you can copy the pattern from a config already on this box. The
existing `/etc/nginx/cloudflare/ips` file is in `allow <cidr>;` form and cannot be included
directly; generate a `set_real_ip_from` variant from the same source.

Then:

```bash
sudo nginx -t -c /home/clp/services/nginx/nginx.conf && sudo systemctl reload clp-nginx
```

If nothing fronts the panel, delete the `real_ip` block entirely instead.

**What a hardened configuration would also include:**

- **Enable MFA** on both panel accounts — it is currently **off on both** (§8.1). This is the
  highest-value, lowest-effort change on the list.
- Put `manage.cijagani.in` behind **Cloudflare Access / Zero Trust**. Note that a plain ufw
  source allowlist on 8443 is *not* practical here: administrative access arrives from a
  rotating residential range (22 distinct IPs in the audit log), so identity-based access
  control fits this environment far better than IP-based.
- `clpctl cloudpanel:enable-basic-auth` — covers `/api`, but note it exempts `/phpmyadmin`
  (§1.6).
- Keep `api_token` empty if you do not use the API. **Currently 0 tokens are defined**, so
  nothing legitimate depends on this endpoint here.

### Finding 2 — Autologin token is used unsanitised in a filesystem path

**Severity: Low–Moderate.** `src/Security/AutoLoginAuthenticator.php`:

```php
private function getTokenFile(string $token): string {
    return sprintf("%s/var/.token_%s", $this->kernel->getProjectDir(), $token);
}
```

The `token` query parameter is concatenated into a path with no validation, then
`file_exists()` / `file_get_contents()` on it, and in a `finally` block `@unlink($tokenFile)`.
`/autologin` is `PUBLIC_ACCESS` in `security.yaml`, so this is reachable unauthenticated.

Reading is well constrained — the content must parse as JSON containing `userName` and
`expiration`, or an exception is thrown. The **unlink in `finally` runs regardless**, as the
`clp` user. Treat this as an arbitrary-file-deletion and file-existence-oracle risk rather
than an authentication bypass.

**Containment, if it mattered:** there is no supported configuration switch for this;
gating 8443 behind Cloudflare Access or a VPN would be the practical answer. If you reimplement this pattern, validate
the token against `^[A-Za-z0-9]{32,}$` before it ever touches a path.

### Scope note

Both findings are in **CloudPanel itself**, not in this server's local customisations. They
apply to a stock 2.5.4 install. Report upstream if you wish; the mitigations above are
independent of a vendor fix.

---

## Table of Contents

1. [Panel Access Control](#1-panel-access-control)
2. [Firewall Management](#2-firewall-management)
3. [Per-Site Security Controls](#3-per-site-security-controls)
4. [Audit Log & Notifications](#4-audit-log--notifications)
5. [Monitoring](#5-monitoring)
6. [The Privileged Execution Layer](#6-the-privileged-execution-layer)
7. [Panel Internals: Services, File Manager, Updates](#7-panel-internals)
8. [Live State of This Server](#8-live-state-of-this-server)
9. [Blueprint: Building Your Own](#9-blueprint-building-your-own)
10. [Command Reference](#10-command-reference)

---

## 1. Panel Access Control

### 1.1 The user model

```sql
user (
  id, timezone_id -> timezone(id),
  user_name VARCHAR(64) UNIQUE,
  first_name, last_name,
  email VARCHAR(128) UNIQUE,
  password VARCHAR(255),        -- hashed (Symfony "auto" hasher → bcrypt/argon2id)
  role VARCHAR(255),            -- ROLE_ADMIN | ROLE_SITE_MANAGER | ROLE_USER
  mfa BOOLEAN,
  mfa_secret VARCHAR(64) UNIQUE,
  status BOOLEAN,               -- active / inactive
  created_at, updated_at
);

user_sites (user_id, site_id)   -- scopes a non-admin user to specific sites
```

Constants from `Entity/User.php`:

```php
ROLE_ADMIN | ROLE_SITE_MANAGER | ROLE_USER
PASSWORD_MIN_LENGTH = 8      PASSWORD_MAX_LENGTH = 100
MFA_SECRET_LENGTH   = 16     DEFAULT_TIMEZONE    = 'UTC'
STATUS_ACTIVE = true         STATUS_NOT_ACTIVE   = false
```

Unlike site users (which are **real Linux accounts** — companion doc §4), panel users exist
only in the database. The two namespaces are entirely separate: a panel user has no shell
account, and a site user cannot log into the panel.

`user_sites` is the multi-tenancy mechanism: a `ROLE_SITE_MANAGER` sees only the sites
mapped to them, while `ROLE_ADMIN` sees everything and can reach `/admin/*`.

**Password hashing** is delegated to Symfony's `'auto'` hasher
(`config/packages/security.yaml`), which resolves to bcrypt or argon2id depending on what PHP
offers — genuinely modern, unlike the DES `crypt()` used for site basic-auth files
(companion doc §15.6).

### 1.2 Authentication chain

`config/packages/security.yaml` defines three firewalls:

```yaml
firewalls:
  dev:   pattern: ^/(_(profiler|wdt)|css|images|js)/     security: false
  api:   pattern: ^/api    custom_authenticator: ApiTokenAuthenticator
  main:  provider: custom_user_provider
         custom_authenticators:
           - LoginFormAuthenticator
           - AutoLoginAuthenticator
         logout: { path: clp_logout, target: clp_login }
```

Four authenticators, each with a distinct job:

| Authenticator | Trigger | Credential |
|---|---|---|
| `LoginFormAuthenticator` | POST to `clp_login` | username + password + **CSRF token** |
| `MfaAuthenticator` | `/login/mfa` | TOTP code against `user.mfa_secret` |
| `AutoLoginAuthenticator` | `/autologin?token=…` | one-shot token file in `var/.token_<token>` |
| `ApiTokenAuthenticator` | `Authorization: Bearer …` on `^/api` | row in `api_token` — **or a whitelisted client IP** (§Finding 1) |

`LoginFormAuthenticator` correctly includes a `CsrfTokenBadge`, so the login form is CSRF
protected.

**MFA** is TOTP-based (`endroid/qr-code-bundle` renders the enrolment QR). `mfa_secret` is
`UNIQUE` across the table — a slightly odd constraint, since secrets are random per user and
uniqueness is incidental rather than meaningful.

`AutoLoginAuthenticator` implements a one-time login link: a JSON file
`{userName, expiration}` is written to `<projectDir>/var/.token_<token>`, the token is passed
as a query parameter, and the file is `unlink`ed in a `finally` block — so it is genuinely
single-use. The user must exist and be `STATUS_ACTIVE`, and `expiration` is checked against
now. The design is sound; the path handling is not (§Finding 2).

### 1.3 Access control rules

```yaml
access_control:
  - { path: ^/admin/user/creation, roles: PUBLIC_ACCESS }   # first-run setup
  - { path: ^/autologin,           roles: PUBLIC_ACCESS }
  - { path: ^/admin,               roles: [ ROLE_ADMIN ] }
  - { path: ^/login$,              roles: PUBLIC_ACCESS }
  - { path: ^/login/mfa$,          roles: PUBLIC_ACCESS }
  - { path: ^/locale/change$,      roles: PUBLIC_ACCESS }
  - { path: ^/pma$,                roles: PUBLIC_ACCESS }
  - { path: ^/pma/logout$,         roles: PUBLIC_ACCESS }
  - { path: ^/,                    role:  IS_AUTHENTICATED_FULLY }
```

Only the **first matching rule** applies, so ordering is load-bearing — `^/admin/user/creation`
must precede `^/admin` or first-run setup would be impossible.

Note `^/admin/user/creation` being public: it is the initial-admin bootstrap. Verify it is
inert once an admin exists; a stale bootstrap route is a classic panel weakness.

### 1.4 API tokens

```sql
api_token (id, name VARCHAR(255), token VARCHAR(255), created_at, updated_at);
```

Flat table, no expiry column, no scoping, no per-token permissions, no last-used tracking.
A token is all-or-nothing access to the API. Lookup is a plain equality match
(`findOneBy(["token" => $apiToken])`), so tokens are stored and compared **in plaintext**.

**On this host: 0 tokens are defined.**

If you build your own: store a hash, add `expires_at`, `last_used_at`, `scopes`, and compare
in constant time.

### 1.5 Panel basic auth

```bash
clpctl cloudpanel:enable-basic-auth --userName=... --password='...'
clpctl cloudpanel:disable-basic-auth
```

Writes `/home/clp/services/nginx/basic-auth/credentials`. The panel vhost applies it
conditionally:

```nginx
set $basicAuth "off";
if (-f /home/clp/services/nginx/basic-auth/credentials) { set $basicAuth "on"; }
if ($request_uri ~ ^/phpmyadmin)                        { set $basicAuth "off"; }
auth_basic $basicAuth;
auth_basic_user_file /home/clp/services/nginx/basic-auth/credentials;
```

The file's existence is the on/off switch. **phpMyAdmin is explicitly exempted** — see §1.6.

### 1.6 phpMyAdmin exposure

Three facts stack up on this host:

1. phpMyAdmin is served at `https://<panel>:8443/phpmyadmin`
2. The panel vhost **turns basic auth off** for that path
3. Port 8443 is `ALLOW Anywhere` in ufw

So phpMyAdmin is reachable from the internet, protected only by MySQL credentials, and
unprotected by panel basic auth even when that is enabled. `^/pma$` is additionally
`PUBLIC_ACCESS` in `access_control`.

**Observation:** gating 8443 behind Cloudflare Access or a VPN would contain this, Finding 1
and Finding 2 at once (a static IP allowlist would not suit this environment — §8.1).

### 1.7 Custom domain for the panel

`Security/Admin/CustomDomain.php` lets the panel be reached on a real hostname with a real
certificate instead of `IP:8443`:

```
WELL_KNOWN_DIRECTORY     /home/clp/htdocs/app/files/public/.well-known/
ACME_CHALLENGE_DIRECTORY /home/clp/htdocs/app/files/public/.well-known/acme-challenge/
PRIVATE_KEY_FILE         /etc/nginx/ssl-certificates/custom-domain.key
CERTIFICATE_FILE         /etc/nginx/ssl-certificates/custom-domain.crt
VHOST_FILE               /etc/nginx/sites-enabled/custom-domain.conf
CUSTOM_DOMAIN_MOTD_FILE  /etc/.clp_custom_domain
```

Note the vhost goes into the **site** nginx (`/etc/nginx/sites-enabled/`), not the panel's
own nginx — so the custom domain is served on 443 by the main instance and proxied to the
panel. ACME challenges are written into the panel's public directory, chowned to `clp`,
dirs `750` / files `770`. Renewal is a dedicated command,
`lets-encrypt:renew:custom-domain-certificate`.

**On this host:** `custom_domain = manage.cijagani.in`, and `custom-domain.conf` plus
`custom-domain.crt`/`.key` are present.

---

## 2. Firewall Management

### 2.1 Architecture

CloudPanel wraps **ufw** rather than writing iptables rules directly. `src/Ufw/`:

```
Ufw/Firewall.php              — the façade: allowTcpRule, allowUdpRule, enable, disable, reset
Ufw/Firewall/AllowTcpRule.php — value object: {ip, portRange}
Ufw/Firewall/AllowUdpRule.php
Ufw/Command/AllowTcpRule.php  — renders the shell command
Ufw/Command/AllowUdpRule.php
Ufw/Command/{Enable,Disable,Reset}.php
```

The generated command (`Ufw/Command/AllowTcpRule.php`):

```bash
/usr/bin/sudo /usr/sbin/ufw [--dry-run] allow proto tcp from <ip> to any port <portRange>
```

Success detection is by **string matching on the output**:

```php
$isSuccessful = false === str_contains($output, "ERROR");
```

Crude, but at least it is a real check — most command classes in the codebase hardcode
`return true` (see §6.3).

### 2.2 The `--dry-run` pattern

`allowTcpRule($ip, $portRange, $dryRun = false)` — the same rule can be rendered with
`ufw --dry-run` first to validate syntax without applying it. This is the same
validate-then-commit discipline as `nginx -t` before reload, and it is worth copying: for any
generated system config, always have a cheap "would this be accepted?" path.

### 2.3 Data model and live state

```sql
firewall_rule (id, port_range VARCHAR(255), source VARCHAR(255),
               description VARCHAR(255), created_at, updated_at);
```

The table is the source of truth; ufw is the projection — the same DB-as-truth pattern used
for vhosts and FPM pools throughout CloudPanel.

**Live ufw state on this host (8 rules):**

```
80/tcp     ALLOW  Anywhere        # HTTP
443/tcp    ALLOW  Anywhere        # HTTPS
443/udp    ALLOW  Anywhere        # QUIC / HTTP3
8443/tcp   ALLOW  Anywhere        # ← PANEL UI, open to the internet
2299/tcp   ALLOW  Anywhere        # [LOCAL] SSH on a non-standard port
(+ the four IPv6 equivalents)
```

`80`, `443` and `443/udp` are correct for a web server. **`8443` open to `Anywhere` is the
weak point** — it exposes the panel login, phpMyAdmin (§1.6), and the API (§Finding 1).

Recommended change:

```bash
sudo ufw delete allow 8443/tcp
sudo ufw allow from <your.ip.address> to any port 8443 proto tcp
sudo ufw status numbered
```

Do the same for 2299 if your administrative access comes from known addresses.

Note that PHP-FPM ports (`11000`–`20999`) and the internal nginx backend (`8080`) are not in
the allowlist — they are contained by the firewall rather than by binding to loopback
(companion doc §17.9). That makes these ufw rules more load-bearing than they first appear:
loosening the default policy would expose every site's FastCGI backend.

---

## 3. Per-Site Security Controls

These are rendered into each vhost by the `{{settings}}` processor (companion doc §7.2).
Covered here from the data-model side.

### 3.1 The tables

```sql
basic_auth  (id, user_name, password, whitelisted_ips, is_active, ...)
blocked_bot (id, site_id, name)        -- matched against $http_user_agent
blocked_ip  (id, site_id, ip)          -- matched against $remote_addr
```

Plus two boolean columns on `site`: `allow_traffic_from_cloudflare_only` and
`page_speed_enabled`.

### 3.2 What each renders

| Control | nginx output | Notes |
|---|---|---|
| Basic auth | `auth_basic "Restricted Area"` + `auth_basic_user_file /etc/nginx/basic-auth/<domain>` | with optional `satisfy any` + `allow <ip>` whitelist |
| Blocked bots | `if ($http_user_agent ~* (a\|b\|c)) { return 444; }` | spaces in names → `\s` |
| Blocked IPs | `if ($remote_addr ~ "^(1.2.3.4\|…)$") { return 403; }` | uses `$http_cf_connecting_ip` when CF-only is on |
| Cloudflare only | `include /etc/nginx/cloudflare/ips;` | an allow-list of CF ranges |

**The basic-auth hash is weak.** From `Updater::createBasicAuthFile()`:

```php
sprintf("%s:%s", $userName, crypt($password, base64_encode($password)))
```

DES `crypt()`, salted from the password itself, 8 significant characters. nginx supports
bcrypt (`$2y$`) in htpasswd files — use that instead if you reimplement this.

**Live state here:** 1 basic-auth entry (`nas.cijagani.in`), 0 blocked IPs, 0 blocked bots.

### 3.3 [LOCAL] The `conf.d` layer

This host adds a bot/rate-limit layer that stock CloudPanel does not have — see companion
doc §7.5 for the full contents. Summary:

```
/etc/nginx/conf.d/bad-bots.conf          map $http_user_agent → $is_bad_bot / $is_good_bot
/etc/nginx/conf.d/rate-limits.conf       6 limit_req_zones + limit_conn_zone, 429 status
/etc/nginx/conf.d/security-headers.conf  opt-in header bundle
/etc/nginx/conf.d/cloudflare-ips.conf    duplicate CF ranges
```

⚠️ **These define maps and zones but are inert unless referenced.** `bad-bots.conf` only
takes effect where a server block contains `if ($is_bad_bot) { return 403; }`; the rate-limit
zones only apply where a `limit_req zone=...` directive appears; `security-headers.conf` must
be explicitly `include`d. Grep the vhosts to confirm which sites actually use them:

```bash
grep -l 'is_bad_bot\|limit_req \|security-headers' /etc/nginx/sites-enabled/*.conf
```

Also note these are **not backed up by anything** (companion doc §2.6) and are **overwritten
if the panel re-renders a vhost**, since the panel does not know about them.

---

## 4. Audit Log & Notifications

### 4.1 The event log

```sql
event (
  id, created_at,
  user_name, user_role,
  event_name VARCHAR(255),
  event_data CLOB,
  source_ip_address VARCHAR(255),
  user_agent CLOB
);
```

A genuinely useful audit trail — actor, role, action, payload, source IP and user agent for
every state-changing operation. This is one of CloudPanel's better-designed pieces.

**Event taxonomy observed on this host** (top entries, 700+ rows total):

```
LOGIN                            309
EVENT_SITE_VHOST_UPDATE           98
SITE_CERTIFICATE_INSTALL          65
SITE_ROOT_DIRECTORY_UPDATE        52
SITE_PHP_SETTINGS_UPDATE          31
SITE_PHP_CREATE                   26
SITE_DATABASE_ADD                 22
SITE_CERTIFICATE_DELETE           16
SITE_CRON_JOB_ADD                 13
ADMIN_USER_UPDATE                 10
SITE_USER_SETTINGS_UPDATE          9
SITE_REVERSE_PROXY_CREATE          7
SITE_VARNISH_CACHE_PURGE           5
SITE_DATABASE_USER_EDIT            5
SITE_DELETE                        3
SERVICE_RESTART                    2
FIREWALL_RULE_UPDATE               2
ADMIN_CUSTOM_DOMAIN_ENABLE         2
SITE_WORDPRESS_CREATE              1
```

Note the naming inconsistency: most are `SITE_*`, but two use an `EVENT_` prefix
(`EVENT_SITE_VHOST_UPDATE`, `EVENT_SITE_CLOUDFLARE_SETTINGS`). Cosmetic, but it means
`WHERE event_name LIKE 'SITE_%'` silently misses rows — worth knowing before you write
alerting queries against it.

**What is *not* audited:** `clpctl` CLI invocations do not appear to generate events — the
309 `LOGIN` rows and all state changes carry a real `user_name` and `source_ip_address`, i.e.
they came through the web UI. Anything done over SSH with `clpctl` is invisible here. Plan
accordingly if you rely on this for compliance.

Useful queries:

```sql
-- who changed what, recently
SELECT created_at, user_name, user_role, event_name, source_ip_address
  FROM event ORDER BY id DESC LIMIT 50;

-- logins from unexpected addresses
SELECT created_at, user_name, source_ip_address, user_agent
  FROM event WHERE event_name = 'LOGIN'
  AND source_ip_address NOT IN ('<your.known.ip>')
  ORDER BY id DESC;

-- destructive actions
SELECT * FROM event
 WHERE event_name LIKE '%DELETE%' ORDER BY id DESC;
```

### 4.2 Notifications

```sql
notification (id, created_at, updated_at, hash VARCHAR(255),
              severity INTEGER, subject VARCHAR(255), message CLOB,
              url VARCHAR(255), is_read BOOLEAN);
```

`hash` is a deduplication key — the same recurring problem updates one row rather than
creating thousands. Sensible.

`Notification/NotificationQueue.php` is the entry point. This is where **backup failures
surface** (`"Remote Backup failed: <domain>"` — see backup doc §2.4.9), and it is the *only*
place they surface, because the cron invocation discards all output.

**Live state: 0 notifications** — consistent with remote backup never having been configured.

> If you take one monitoring action from this document: alert on
> `SELECT COUNT(*) FROM notification WHERE is_read = 0 AND severity >= <warn>`.
> It is the panel's single aggregation point for asynchronous failures.

### 4.3 Announcements

```sql
announcement (…)   -- 1 row on this host
```

Vendor messages fetched by `AnnouncementCheckCommand`, driven by the agent. Purely
informational — but note it implies the panel makes **outbound calls to CloudPanel
infrastructure** on a schedule.

---

## 5. Monitoring

### 5.1 Data model — four parallel time-series tables

```sql
instance_cpu          (id, created_at, value INTEGER);
instance_memory       (id, created_at, value INTEGER);
instance_disk_usage   (id, created_at, value INTEGER);
instance_load_average (id, created_at, value …);
```

Deliberately minimal: a timestamp and a single scalar per row. No per-site attribution, no
per-process breakdown — this is **whole-instance** monitoring only.

**Live volume: 290,135 rows in `instance_cpu` alone.** At one sample per minute that is
roughly 200 days of history, and it is a meaningful contributor to the 73 MB `db.sq3`.

### 5.2 Collection and retention

Collection is performed by **`clp-agent`** (the Go daemon), not by cron and not by PHP —
which is why there is no cron entry for it (companion doc §3.4, backup doc §3.8).

Retention is `MonitoringDataCleanCommand`, also agent-driven. Given the row count above, the
window is generous.

`src/Monitoring/Chart.php` and `LoadAverageChart.php` render these into the dashboard graphs.

### 5.3 What is deliberately not monitored

| Not collected | Consequence |
|---|---|
| Per-site CPU/memory | Cannot identify which tenant is causing load |
| Per-site disk usage | No quota enforcement or billing signal |
| PHP-FPM pool status | `pm.status_path = /status` is configured in every pool but not scraped |
| Per-site request rates | No traffic attribution |
| Certificate expiry alerts | `certificate.expires_at` exists but nothing alerts on it |
| Backup success/failure | Only via notifications, if configured |

**The FPM status endpoint is the easy win.** Every pool already sets
`pm.status_path = /status`, so per-site worker counts and request rates are one scrape away:

```bash
# per-site FPM status via the pool's own port
SCRIPT_NAME=/status SCRIPT_FILENAME=/status REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect 127.0.0.1:19003 2>/dev/null
```

Combined with `ps -u <siteUser>` and `du -sh /home/<user>`, that closes most of the
per-tenant visibility gap without touching CloudPanel.

### 5.4 Practical additions

```bash
# certificate expiry — the data is already there, nothing alerts on it
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT s.domain_name, c.type, c.expires_at
     FROM certificate c JOIN site s ON s.id = c.site_id
    WHERE c.expires_at < datetime('now','+21 days')
    ORDER BY c.expires_at;"

# disk per tenant
du -sh /home/*/htdocs 2>/dev/null | sort -h | tail -20

# database size per tenant
mysql -e "SELECT table_schema, ROUND(SUM(data_length+index_length)/1024/1024,1) mb
            FROM information_schema.tables GROUP BY table_schema ORDER BY mb DESC;"

# monitoring table growth (db.sq3 is 73MB and mostly this)
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT 'cpu', COUNT(*) FROM instance_cpu
    UNION ALL SELECT 'mem', COUNT(*) FROM instance_memory
    UNION ALL SELECT 'disk', COUNT(*) FROM instance_disk_usage;"
```

---

## 6. The Privileged Execution Layer

This is the part most worth studying if you are building your own panel. Every panel faces
the same problem: **a web application running as an unprivileged user needs to perform
root-level system changes.** How CloudPanel solves it determines its entire security posture.

### 6.1 The three-class pattern

```
src/System/
├── Command.php           abstract base — a command is an OBJECT, not a string
├── CommandExecutor.php   runs it via Symfony Process
├── Process.php           extends Symfony Process; owns success semantics
└── Command/              ~50 concrete commands, one per operation
    ├── CreateUserCommand.php      DeleteUserCommand.php
    ├── ChownCommand.php           ChmodCommand.php  FindChmodCommand.php
    ├── WriteFileCommand.php       DeleteFileCommand.php  CopyFileCommand.php
    ├── ServiceReloadCommand.php   ServiceRestartCommand.php  ServiceStatusCommand.php
    ├── NginxConfigTestCommand.php
    ├── CreateDatabaseDumpCommand.php  ImportDatabaseDumpCommand.php
    ├── TarCreateCommand.php       RcloneCopyCommand.php
    ├── KillUserProcessesCommand.php
    └── … ~50 total
```

The abstract base:

```php
abstract class Command {
    protected ?string $name, $command, $description, $output, $runAsUser;
    protected bool $isSuccessful = false;
    protected bool $runInBackground = false;

    abstract public function getCommand(): string;      // renders the shell string
    abstract public function isSuccessful(): bool;       // interprets the result
    public function setRunAsUser(string $u): void;       // → sudo -u <u>
    public function setRunInBackground(bool $f): void;
}
```

**Why this is a good design:** each privileged operation is a named class with typed setters.
Callers never build shell strings. Argument escaping lives in exactly one place per operation
(`escapeshellarg()` inside `getCommand()`), and success interpretation is per-operation
(`ufw` greps for `ERROR`, `useradd` expects empty output, `tar` tolerates exit 1).

Compare to the alternative most panels reach for — `shell_exec("chown -R $user $path")`
scattered across controllers — and the advantage is obvious.

### 6.2 The executor

```php
class CommandExecutor {
    public function execute(Command $command, $timeout = 30): void {
        $process = Process::fromShellCommandline($command->getCommand(), "/tmp/");
        $process->setCommand($command);

        if ($command->runInBackground()) {
            $process->start();                  // fire and forget
        } else {
            $process->setTimeout($timeout);     // DEFAULT 30 SECONDS
            $process->run();
            if (false === $process->isSuccessful()) {
                throw new RuntimeException($process->getErrorOutput());
            }
        }
        // on exception: rethrow with "Command \"<name> : <full command>\" failed, …"
    }
}
```

Points that matter:

- **Working directory is hardcoded `/tmp/`** — no command may rely on an inherited cwd.
- **Default timeout is 30s**, overridden per call: 7200s for dumps/imports, 21600s for
  tar/rclone, 90s for permission resets, 360s for backup cleanup, 20s for `rclone lsjson`.
- `fromShellCommandline` means the string **is** interpreted by a shell — pipes and
  redirects work (the dump command relies on this), but it also means escaping discipline
  inside `getCommand()` is the only thing preventing injection.
- Failure detail is put into the exception message **including the full rendered command** —
  convenient for debugging, but that string can contain paths and, in some commands, file
  names derived from user input. Be careful about surfacing it to end users.

### 6.3 Success semantics — and the flaw

```php
class Process extends Symfony\Component\Process\Process {
    public function isSuccessful(): bool {
        if ($this->command instanceof TarCreateCommand) {
            $exitCode = $this->getExitCode();
            $isSuccessful = in_array($exitCode, [0, 1]);   // tar returns 1 on
                                                            // "file changed as we read it"
        } else {
            $isSuccessful = parent::isSuccessful();
        }
        $output = trim($this->getErrorOutput() ?: $this->getOutput());
        $this->command->setOutput($output);
        if (false === $this->command->isSuccessful()) {     // ← delegates to the Command
            $isSuccessful = false;
            $this->addErrorOutput($output);
        }
        return $isSuccessful;
    }
}
```

Treating tar's exit 1 as success is correct and thoughtful — backing up a live site *will*
hit changing files, and failing the whole run for that would be wrong.

**But the delegation to `$this->command->isSuccessful()` is where it falls apart.** Several
of the most important command classes implement it as:

```php
public function isSuccessful(): bool { return true; }
```

Confirmed hardcoded `true` in: `CreateDatabaseDumpCommand`, `ImportDatabaseDumpCommand`,
`TarCreateCommand`, `RcloneCopyCommand`, `DeleteOldFilesRecursiveCommand`.

Some do real checks:

| Command | Check |
|---|---|
| `CreateUserCommand` | `empty($output)` — useradd is silent on success |
| `Ufw\Command\AllowTcpRule` | `!str_contains($output, "ERROR")` |
| `TarCreateCommand` (exit code) | handled in `Process`, exit 0 or 1 |

The net effect, combined with the cron-level `&> /dev/null` and `MAILTO=""` documented in
the backup doc §6.4: **the backup and database paths cannot report failure.** This is the
single most consequential design flaw across all three documents, and it is why this server
has zero backups while every surface reports success.

### 6.4 The privilege boundary

```
Browser ──HTTPS──► clp-nginx (runs as `clp`)
                        │ fastcgi_pass unix:/run/clp-php-fpm.sock  (srw-rw---- clp:clp)
                        ▼
                   clp-php-fpm  (PHP 8.1, runs as `clp`)
                        │ Symfony app builds a Command object
                        │ CommandExecutor → Symfony Process → /bin/sh
                        ▼
                   `sudo <command>`         ← /etc/sudoers.d/cloudpanel:
                        │                      clp ALL=(ALL) NOPASSWD: ALL
                        ▼
                     root
```

And the CLI path:

```
any user ──► clpctl (bash wrapper, escapes \ ` ( ) $ ")
                 └─► sudo /usr/bin/clpctlWrapper        ← ALL ALL=(ALL) NOPASSWD: <this>
                       └─► php8.1 … bin/clpctl <args>   (as root)
```

Two observations:

1. **The panel's nginx and PHP-FPM run as `clp`, not root** — good, and notably better than
   the *site* nginx which runs as root (companion doc §15.2). The socket is `srw-rw---- clp:clp`,
   so only `clp` can reach the panel's FPM.
2. **`clp` has `NOPASSWD: ALL`.** So the boundary is not really a boundary: any code
   execution as `clp` is root. The `sudo` calls are a convention for clarity, not a
   restriction. This is the standard control-panel trade-off, but be clear-eyed that
   compromising the panel PHP process is equivalent to compromising the machine.

For your own build, the stronger pattern is a **root daemon with a typed request protocol**:
the web app sends `{"op":"create_site","domain":"…","user":"…"}` over a unix socket; the
daemon validates against a schema and executes only the operations it knows. The web app then
holds no sudo rights at all, and the set of possible privileged actions is enumerable rather
than "anything".

### 6.5 Notable hardening touches worth copying

Genuinely good ideas found in the command layer:

- **Passwords never on the command line.** Written to `chmod 0400` temp files and fed via
  stdin redirect (`mysqldump -p< file`) or `--defaults-extra-file`, deleted in `__destruct()`.
  Keeps secrets out of `/proc/*/cmdline` where any local user could read them.
- **`openssl passwd -6`** for the site user password — hashing happens in a subshell reading
  from a file, so the plaintext never appears in an argument.
- **`setfacl -m u:www-data:--- /usr/bin/sh` before database import** — revokes shell access
  from the account that will run the MySQL client, as a guard against a malicious dump
  achieving command execution. (Note this is a persistent, global ACL change.)
- **`--dry-run` before applying ufw rules**, and `NginxConfigTestCommand` (`nginx -t`) before
  reload.
- **`KillUserProcessesCommand` with `runInBackground(true)`** during site deletion — killing
  a user's processes can block, and the teardown should not stall on it.
- **`escapeshellarg()` on every interpolated value** inside `getCommand()`.

---

## 7. Panel Internals

### 7.1 Service inventory

| Unit | Runs as | Binds | Purpose |
|---|---|---|---|
| `clp-nginx.service` | `clp` | `:8443` | panel UI, separate `nginx.conf` |
| `clp-php-fpm.service` | `clp` | `/run/clp-php-fpm.sock` | the Symfony app, PHP **8.1** |
| (file-manager pool) | `clp` | `/run/clp-fm-php-fpm.sock` | file manager, separate pool |
| `clp-agent.service` | **root** | — | Go scheduler: monitoring, LE renewal, announcements |

```ini
# clp-nginx
ExecStartPre=/usr/sbin/nginx -t -q -g 'daemon on; master_process on;' -c /home/clp/services/nginx/nginx.conf
ExecStart=/usr/sbin/nginx -g 'daemon on; master_process on;' -c /home/clp/services/nginx/nginx.conf

# clp-php-fpm
ExecStart=/usr/sbin/php-fpm8.1 -R -F -y /home/clp/services/php-fpm/fpm/php-fpm.conf \
                                        -c /home/clp/services/php-fpm/fpm/php.ini
```

Note `ExecStartPre` runs `nginx -t` before every start — the panel refuses to start on a
broken config rather than failing silently.

The panel is pinned to **PHP 8.1** regardless of what the sites use, and it ships its own
`php.ini` and `php-fpm.conf` under `/home/clp/services/php-fpm/`. Complete isolation from the
tenant PHP stack.

### 7.2 The file manager

Served from `/home/clp/htdocs/app/files/public/file-manager/` through its **own FPM pool**
on `/run/clp-fm-php-fpm.sock` (`srw-rw---- root:clp`), with deliberately large limits:

```nginx
fastcgi_param PHP_VALUE "
  error_log=/home/clp/logs/php/error.log;
  memory_limit=1024M;
  post_max_size=3G;
  upload_max_filesize=3G;
  max_execution_time=3600;";
```

Separating it into its own pool is right: a 3 GB upload holding a worker for an hour should
not consume the pool that serves the control panel UI.

Since the pool runs as `clp` and `clp` is `NOPASSWD: ALL`, the file manager can read and
write anywhere. It is effectively a root file browser behind panel authentication.

### 7.3 Updates and release channel

```bash
/usr/bin/clp-update                      # the updater binary
clpctl cloudpanel:set-release-channel    # stable | beta
```

`config` keys: `app_version` (2.5.4 here), `release_channel` (`stable`), `instance_uid`.

`create_backup.sh` (backup doc §2.2) runs as part of the update flow — which is why the three
panel backups on this host are dated to update events rather than a schedule.

Doctrine migrations under `migrations/` version the SQLite schema, so an update can evolve
the database structure. `doctrine_migration_versions` tracks what has been applied.

### 7.4 Vhost templates

```
APP_VHOST_GIT_REPOSITORY='https://github.com/cloudpanel-io/vhost-templates.git'
APP_VHOST_DIRECTORY='2-http3'
APP_HTTP3=true
```

Templates are pulled from a **public GitHub repository** and imported into the
`vhost_template` table (`clpctl vhost-templates:import`). 31 templates on this host
(Laravel 12/13, WordPress, Symfony 7/8, Magento 2, Drupal 10/11, TYPO3 14, …).

`APP_VHOST_DIRECTORY='2-http3'` selects the template generation — which is how the HTTP/3
`listen 443 quic` lines got into every vhost.

You can add your own with `clpctl vhost-template:add --name='MyApp' --file=template.conf`.
Custom templates live only in the database, so they survive updates but **only exist in
`db.sq3`** — back that up.

### 7.5 Outbound connections the panel makes

Worth knowing for egress-restricted environments:

| Destination | Purpose |
|---|---|
| `acme-v02.api.letsencrypt.org` | certificate issuance/renewal |
| `acme-staging-v02.api.letsencrypt.org` | the pre-flight dry run |
| `github.com/cloudpanel-io/vhost-templates.git` | template import |
| CloudPanel announcement endpoint | `AnnouncementCheckCommand` |
| Cloud provider APIs | snapshots (AWS/DO/GCE/Hetzner/Vultr), if configured |
| rclone remote | backups, if configured |
| Dropbox OAuth | token refresh, if Dropbox is the backup target |

---

## 8. Live State of This Server

Consolidated audit for the areas in this document, as of 2026-09-06.

### 8.1 Access control

```
Panel users        2
  cijagani   ROLE_ADMIN        mfa=0  active
  webdev     ROLE_USER         mfa=0  active
API tokens         0        ← nothing depends on the API
Panel basic auth   NOT enabled (no /home/clp/services/nginx/basic-auth/credentials)
Custom domain      manage.cijagani.in
Login events       309
Distinct source IPs in the audit log: 22
```

Three observations follow from this:

**MFA is disabled on both accounts.** The panel supports TOTP (§1.2) and it is not in use.
With 8443 world-reachable, a password is the only thing between the internet and
`ROLE_ADMIN`. For reference, MFA is enrolled per-user in the panel UI, and recovery is:

```bash
# via the panel UI: user profile → Two-Factor Authentication
# to recover if a device is lost:
clpctl user:disable-mfa --userName=<user>
```

**A second account exists.** `webdev` (`ROLE_USER`). Its `user_sites` mapping determines which
sites it can reach — this is the query that shows the scoping:

```bash
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT u.user_name, s.domain_name FROM user_sites us
     JOIN user u ON u.id = us.user_id JOIN site s ON s.id = us.site_id;"
```

**Panel access comes from many, mostly dynamic, addresses.** The 22 distinct source IPs
break down as:

```
106.215.x.x  (13 addresses)   dynamic residential range — same ISP block
1.38.x.x     (2)              dynamic residential
59.95.102.155                 also the panel's masquerade_address
47.11.98.146                  another dynamic address
2402:3a80:…                   IPv6, dynamic
172.69.179.153, 172.71.202.33, 172.71.198.180, 162.158.227.114
                              ← CLOUDFLARE ranges
127.0.0.1                     local
```

This corrects an assumption worth stating plainly: **a static IP allowlist on 8443 is not
practical here**, because administrative access genuinely arrives from a rotating residential
range. The options that would suit it:

- **Cloudflare Access / Zero Trust** in front of `manage.cijagani.in` — identity-based rather
  than IP-based, and the panel is already reached through Cloudflare (see below).
- **A VPN or WireGuard** bastion, with 8443 restricted to the tunnel address.
- **`ufw allow from 106.215.0.0/16`** as a coarse compromise — better than `Anywhere`,
  though it still admits the whole ISP block.
- **MFA** plus `cloudpanel:enable-basic-auth` (remembering the phpMyAdmin exemption, §1.6).

**The Cloudflare addresses in the audit log are directly relevant to Finding 1.** Their
presence shows the panel is reached through Cloudflare on its custom domain — which is very
likely *why* `set_real_ip_from 0.0.0.0/0` was set in the panel's nginx: someone wanted real
client IPs in the logs behind the CDN. That is a reasonable goal implemented in the most
dangerous possible way. The correct fix keeps the benefit and removes the risk — replace the
wildcard with Cloudflare's published ranges only:

```nginx
# /home/clp/services/nginx/nginx.conf — replace `set_real_ip_from 0.0.0.0/0;` with:
include /etc/nginx/cloudflare/ips-real-ip;   # set_real_ip_from <cf range>; per line
real_ip_header CF-Connecting-IP;             # not X-Forwarded-For
```

The site nginx already does exactly this correctly (`real_ip_header CF-Connecting-IP` with
explicit `set_real_ip_from` ranges — companion doc §7.5). The panel nginx is the one that
was configured with a wildcard. Note the existing `/etc/nginx/cloudflare/ips` file is in
`allow <cidr>;` form, so it cannot be reused directly; generate a `set_real_ip_from` variant.

### 8.2 Network exposure

```
80/tcp, 443/tcp, 443/udp   ALLOW Anywhere    ✅ correct for a web server
8443/tcp                   ALLOW Anywhere    ⚠️ panel + phpMyAdmin + API, world-reachable
2299/tcp                   ALLOW Anywhere    ⚠️ [LOCAL] SSH, world-reachable
```

### 8.3 Security posture summary

| Item | State |
|---|---|
| Panel nginx `set_real_ip_from 0.0.0.0/0` | ⚠️ **Finding 1** — fix this |
| Autologin token path handling | ⚠️ **Finding 2** |
| 8443 open to the internet | ⚠️ restrict by source IP |
| phpMyAdmin exempt from panel basic auth | ⚠️ |
| Panel runs as `clp`, not root | ✅ good |
| `clp` has `NOPASSWD: ALL` | ⚠️ inherent to the design |
| Panel password hashing (`auto`) | ✅ bcrypt/argon2id |
| **MFA on panel accounts** | ⚠️ **disabled on both users** |
| Panel basic auth | ⚠️ not enabled |
| Site basic-auth hashing (DES `crypt`) | ⚠️ weak |
| Audit log | ✅ present and detailed (700+ events) |
| Notifications | 0 — nothing has reported a failure |
| Blocked IPs / bots | 0 / 0 |
| Firewall rules | 8 |

### 8.4 Monitoring

```
instance_cpu rows     290,135     (~200 days at 1/min)
db.sq3 size           73 MB       — largely monitoring history
Notifications         0 unread
Per-site metrics      none collected
Cert expiry alerting  none
```

---

## 9. Blueprint: Building Your Own

### 9.1 Access control

**Copy:**
- Separate panel identities from system identities entirely.
- Role + `user_sites` scoping for multi-tenant delegation.
- Symfony's `'auto'` password hasher (or equivalent: argon2id).
- TOTP MFA with a `mfa` boolean plus secret.
- CSRF badge on the login form.
- One-time login tokens that are `unlink`ed after use.

**Fix:**

| CloudPanel | Do instead |
|---|---|
| API auth bypassed for "trusted" client IPs | Never authenticate on client IP; require the token always |
| `set_real_ip_from 0.0.0.0/0` | Trust only the proxy ranges you actually run |
| API tokens stored in plaintext, compared with `=` | Store a hash; compare in constant time |
| No token expiry/scope/last-used | Add `expires_at`, `scopes`, `last_used_at` |
| Unsanitised token in a file path | Validate `^[A-Za-z0-9]{32,}$` before any path use |
| phpMyAdmin exempt from basic auth | No exemptions; put DB tooling behind the same gate |
| Panel port open by default | Bind to localhost + require a tunnel, or ship an IP allowlist prompt |

### 9.2 The execution layer — the part most worth copying

The `Command` / `CommandExecutor` / `Process` triad is a genuinely good pattern. Reimplement
it with these changes:

```python
@dataclass
class Command:
    name: str
    timeout: int = 30
    run_as: str | None = None
    background: bool = False
    def render(self) -> list[str]: ...          # ARGV LIST, not a shell string
    def succeeded(self, rc: int, out: str, err: str) -> bool:
        return rc == 0                          # ← real default, never `return True`
```

| CloudPanel | Do instead |
|---|---|
| `Process::fromShellCommandline()` (shell string) | argv list, `shell=False` — injection becomes structurally impossible |
| `isSuccessful() { return true; }` | Default to `rc == 0`; override only with justification |
| Failures swallowed | Every execution logged with rc, duration, and stderr |
| `clp` has `NOPASSWD: ALL` | Root daemon with a typed, schema-validated request protocol |
| Full command string in exception messages | Log it; do not surface it to users |

Where a pipeline genuinely needs a shell (the mysqldump chain), isolate it in a single
audited class rather than making shell mode the default for all fifty.

**Keep** the good hardening: secrets via `0400` temp files and stdin, `--dry-run`/`-t`
validation before applying, per-operation success semantics (tar's exit 1), and background
execution for operations that may block.

### 9.3 Audit and notification

**Copy the event schema wholesale** — actor, role, action, payload, source IP, user agent is
exactly right.

**Add:**
- Audit CLI invocations too, not just web actions (CloudPanel misses these).
- Consistent event naming — no `EVENT_` prefix on some and not others.
- Retention/rotation for the event table.
- A `severity` on events, not just notifications.

**For notifications**, keep the `hash` dedup key, and build alerting on top:
unread-count-by-severity is the one metric that catches asynchronous failures.

### 9.4 Monitoring

CloudPanel's whole-instance scalars are the bare minimum. Add:

- **Per-site attribution** — scrape each FPM pool's `pm.status_path` (already configured!),
  plus `du` per home and per database.
- **Certificate expiry alerting** — the `expires_at` data already exists.
- **Backup success as a first-class metric** — `last_successful_backup_at` per site, alert on
  staleness. A dead-man's switch is the correct shape here.
- **Retention** on the time-series tables; 290k rows in one table on a 73 MB SQLite file is
  the beginning of a problem.

### 9.5 The meta-lesson

Across all three documents, CloudPanel's architecture is **strong on structure and weak on
feedback**:

- Excellent: DB-as-truth with files as projection; the template/processor engine; one Linux
  user per site; typed command objects; self-describing backup archives; validate-before-apply.
- Weak: hardcoded success returns, discarded output, silent no-ops, authentication decisions
  based on spoofable input, and no per-tenant resource accounting.

If you build on these ideas, keep the structure and invert the feedback posture: **every
scheduled operation should leave a durable success record, and the absence of that record
should page someone.**

---

## 10. Command Reference

### 10.1 Users and access

```bash
clpctl user:add --userName=john --email=john@example.com \
                --firstName=John --lastName=Doe --password='...' \
                --role=admin            # admin | site-manager | user
clpctl user:list
clpctl user:reset-password --userName=john --password='...'
clpctl user:disable-mfa --userName=john
clpctl user:delete --userName=john --force

clpctl cloudpanel:enable-basic-auth --userName=... --password='...'
clpctl cloudpanel:disable-basic-auth
```

### 10.2 Security diagnostics

```bash
# ── ACCESS ────────────────────────────────────────────────────────────────
DB=/home/clp/htdocs/app/data/db.sq3

# panel users, roles, MFA status
sqlite3 -header -column $DB \
  "SELECT id, user_name, email, role, mfa, status FROM user;"

# API tokens (any row here is a live credential)
sqlite3 -header -column $DB "SELECT id, name, created_at FROM api_token;"

# is panel basic auth active?
ls -la /home/clp/services/nginx/basic-auth/credentials 2>/dev/null || echo "not enabled"

# ── FINDING 1: verify the real_ip exposure ────────────────────────────────
grep -n 'set_real_ip_from\|real_ip_header' /home/clp/services/nginx/nginx.conf
#   a line reading "set_real_ip_from 0.0.0.0/0;" means the panel trusts
#   X-Forwarded-For from anyone → remove it and reload clp-nginx.

sudo nginx -t -c /home/clp/services/nginx/nginx.conf
sudo systemctl reload clp-nginx

# ── FIREWALL ──────────────────────────────────────────────────────────────
sudo ufw status numbered
sqlite3 -header -column $DB "SELECT * FROM firewall_rule;"

# restrict the panel to your own address
sudo ufw delete allow 8443/tcp
sudo ufw allow from <your.ip> to any port 8443 proto tcp

# ── AUDIT ─────────────────────────────────────────────────────────────────
# recent activity
sqlite3 -header -column $DB \
  "SELECT created_at, user_name, user_role, event_name, source_ip_address
     FROM event ORDER BY id DESC LIMIT 40;"

# logins from unfamiliar addresses
sqlite3 -header -column $DB \
  "SELECT created_at, source_ip_address, user_agent FROM event
    WHERE event_name='LOGIN' ORDER BY id DESC LIMIT 40;"

# distinct source IPs ever seen
sqlite3 $DB "SELECT DISTINCT source_ip_address FROM event;"

# destructive actions
sqlite3 -header -column $DB \
  "SELECT created_at, user_name, event_name FROM event
    WHERE event_name LIKE '%DELETE%' ORDER BY id DESC;"

# ── NOTIFICATIONS (where async failures surface) ──────────────────────────
sqlite3 -header -column $DB \
  "SELECT created_at, severity, subject, is_read FROM notification
    ORDER BY id DESC LIMIT 20;"
sqlite3 $DB "SELECT COUNT(*) FROM notification WHERE is_read = 0;"

# ── PER-SITE CONTROLS ─────────────────────────────────────────────────────
sqlite3 -header -column $DB \
  "SELECT s.domain_name, s.allow_traffic_from_cloudflare_only, s.page_speed_enabled
     FROM site s;"
sqlite3 $DB "SELECT COUNT(*) FROM blocked_ip;  SELECT COUNT(*) FROM blocked_bot;"
ls -la /etc/nginx/basic-auth/

# which vhosts actually use the [LOCAL] conf.d maps?
grep -l 'is_bad_bot\|limit_req \|security-headers' /etc/nginx/sites-enabled/*.conf

# ── MONITORING ────────────────────────────────────────────────────────────
# certificates expiring soon (nothing alerts on this by default)
sqlite3 -header -column $DB \
  "SELECT s.domain_name, c.type, c.expires_at
     FROM certificate c JOIN site s ON s.id = c.site_id
    WHERE c.expires_at < datetime('now','+21 days') ORDER BY c.expires_at;"

# monitoring table sizes
sqlite3 $DB "SELECT 'cpu', COUNT(*) FROM instance_cpu
       UNION ALL SELECT 'mem', COUNT(*) FROM instance_memory
       UNION ALL SELECT 'disk', COUNT(*) FROM instance_disk_usage;"
ls -lh $DB

# per-site FPM worker status (pool port from php_settings)
sqlite3 $DB "SELECT s.domain_name, p.php_version, p.pool_port
               FROM site s JOIN php_settings p ON p.site_id = s.id;"

# ── PANEL SERVICES ────────────────────────────────────────────────────────
systemctl status clp-nginx clp-php-fpm clp-agent
tail -f /home/clp/logs/php/error.log
tail -f /home/clp/logs/nginx/error.log
```

---

## 11. Reviewer's Assessment

A consolidated verdict across all three documents, written as one webserver/systems engineer's
opinion after reading the running system and the source. Offered as input to your own design
work, not as criticism of a running production box.

### 11.1 Overall

**CloudPanel is a well-architected product with a consistently weak feedback layer.**

The structural decisions are genuinely good — better than most control panels, commercial ones
included. The recurring flaw is not in *what* it does but in *whether it tells you it worked*.
That single axis explains almost every problem in these three documents, including why this
server has no backups while every surface reports success.

If I had to grade it by area:

| Area | Assessment |
|---|---|
| Tenant isolation model | **Strong** — one Linux user per site is the right primitive |
| Config generation | **Strong** — DB-as-truth, template/processor engine, validate-before-apply |
| Privileged execution | **Good structure, weak verification** — typed command objects, hardcoded success |
| Backup design | **Good ideas, poor defaults** — self-describing archives, but nothing is enabled |
| Database layer | **Adequate** — correct mechanics, permissive grants |
| Access control | **Adequate with one real flaw** — solid Symfony auth, undermined by IP trust |
| Observability | **Weak** — whole-instance only, no per-tenant anything |
| Resource governance | **Absent** — no cgroups, no quotas, no caps |

### 11.2 What I'd copy without hesitation

These are the ideas worth carrying into your own tooling:

1. **One Linux user per site.** Everything else — file isolation, process isolation, logs,
   cron, teardown — falls out of this one decision for free. It is the highest-leverage
   choice in the whole design.
2. **Database as truth, filesystem as projection.** Vhosts, FPM pools, logrotate and cron
   files are all *generated*. You can re-render the entire server from `db.sq3`. This makes
   drift detectable and config reviewable.
3. **The placeholder/processor template engine.** One class per `{{placeholder}}`, each owning
   exactly one substitution, with unresolved tokens stripped at the end. Clean, testable, and
   trivially extensible.
4. **Self-describing backup archives.** Writing `site-settings.json` and `site-vhost` into the
   home immediately before tarring, and dumping databases *first* so they ride inside the same
   archive. One tar restores a site onto a bare machine with no control plane. This is the
   best single idea in the codebase.
5. **Typed command objects** instead of scattered `shell_exec`. Escaping lives in one place per
   operation; success semantics are per-operation.
6. **Validate before apply** — `nginx -t` before reload, `ufw --dry-run` before commit, ACME
   staging dry-run before production issuance. Cheap, and it prevents the worst outcomes.
7. **Secrets never on the command line** — `0400` temp files fed via stdin or
   `--defaults-extra-file`, removed in destructors.
8. **Teardown ordering** — pool → vhost → processes → user → databases. Traffic stops first,
   identity goes last.
9. **The audit log schema** — actor, role, action, payload, source IP, user agent.
10. **Panel and tenant stacks fully separated** — different nginx, different FPM, different
    PHP version. A broken site config cannot take down the panel.

### 11.3 What I'd do differently

In rough order of how much it matters:

1. **Make failure loud.** This is the big one. Replace every hardcoded
   `isSuccessful() { return true; }`, stop discarding cron output, exit non-zero when a
   scheduled job is unconfigured, and give every scheduled operation a durable success record
   with alerting on its *absence*. A dead-man's switch would have caught this server's backup
   gap on day one.
2. **Enable backups by default**, or refuse to complete setup until a target is configured.
   An opt-in backup system is a backup system that is off.
3. **Unix sockets instead of TCP for FPM pools.** `listen.allowed_clients = 127.0.0.1` filters
   by address, not by user — any local tenant can reach another's pool. Sockets with
   `listen.owner`/`mode 0660` make the kernel enforce it, and remove port allocation entirely.
4. **Never authenticate on client IP**, and never `set_real_ip_from 0.0.0.0/0`. Finding 1 is
   the direct consequence of doing both.
5. **Add per-tenant resource limits** — systemd slices and filesystem quotas. Currently one
   site can starve the box, and nothing measures which one is doing it.
6. **Per-tenant observability.** Every FPM pool already exposes `pm.status_path`; nothing
   scrapes it. This is a large gap closed cheaply.
7. **`php_admin_value` for limits**, not `PHP_VALUE` — the current scheme lets tenants raise
   their own `memory_limit` with `ini_set()`.
8. **Fix PHP CLI per site.** Node.js gets NVM per home and Python gets `~/.python_version`;
   PHP gets a machine-wide symlink. A `~/bin/php` symlink at site creation would close it in
   ten lines, and would have prevented the three cron/FPM version mismatches on this server.
9. **Tighten the grant model** — `'localhost'` not `'%'`, real `MAX_USER_CONNECTIONS`, and
   expose the read-only user path that the code already supports.
10. **Replace `clp ALL=(ALL) NOPASSWD: ALL`** with a root daemon speaking a typed, validated
    protocol. Then the web tier holds no sudo rights and the privileged action set is
    enumerable rather than unbounded.

### 11.4 The pattern worth internalising

The three documents keep circling the same distinction, and it is the thing I would carry
forward above any specific technique:

> **CloudPanel is excellent at making the right thing happen, and poor at noticing when it
> didn't.**

Structure without feedback produces exactly what this server demonstrates: a correct,
well-organised, cleanly-generated configuration serving thirty sites reliably — with no
database backups, three cron jobs on the wrong PHP version, and an authentication check that
trusts a client-supplied header. None of it announced itself, because nothing was designed to.

When you build your own, the structural ideas in §11.2 will get you a long way in a short
time. The discipline in §11.3 item 1 is what determines whether you can trust it a year later.

### 11.5 Fair context

Two things worth saying in CloudPanel's favour:

- **The isolation model genuinely works.** Thirty tenants, real separation, no container
  overhead, and a teardown path that actually cleans up. Plenty of expensive panels do worse.
- **Several findings here are defaults, not neglect.** The backup gap, the missing resource
  limits and the CLI/FPM version split are all shipped behaviour. A busy operator following
  the documented workflow lands exactly where this server is — which is a product design
  problem, not an administration one.

---

## Series Index

| # | Document | Covers |
|---|---|---|
| **0** | `cloudpanel-summary.md` | **Start here.** Everything condensed into one standalone read — core idea, port map, per-site file manifest, all subsystems, cheat sheet, live audit, reviewer's assessment, blueprint checklist |
| 1 | `cloudpanel-architecture.md` | Site model, per-vhost filesystem layout, nginx template engine, PHP-FPM pools & port allocation, PHP CLI, SSL/ACME, isolation, site creation & deletion |
| 2 | `cloudpanel-database-backup-cron.md` | Database model & grants, dump/import internals, the three backup systems, rclone/remote backup, cron generation, restore procedures |
| 3 | **`cloudpanel-security-access-monitoring.md`** ← this document | Panel auth & MFA, API tokens, firewall, per-site security controls, audit log, monitoring, the privileged execution layer, reviewer's assessment |

---

*End of document.*
