# Vito True Site Isolation and Per-Site Tuning Plan

Status: architecture and implementation plan only

Repository reviewed: Vito `4.x`

CloudPanel reference reviewed: local `cloudpanel.io/` reverse-engineering documents

Related audit: [`VITO_SITE_ISOLATION_REVIEW.md`](VITO_SITE_ISOLATION_REVIEW.md)

## 1. Direct answer

Vito can safely host many sites and subdomains on one server, but the current implementation is not a complete security boundary between sites.

The correct target is **strong operating-system isolation per site**:

- one Linux UID and home directory per strictly isolated site;
- one PHP-FPM endpoint and configuration per site;
- a non-privileged Nginx worker identity;
- one authoritative PHP version used by FPM, CLI, deployments, workers, and cron;
- atomic, validated Nginx and FPM configuration changes;
- per-site CPU, memory, process, and disk limits;
- unique database/Redis credentials and no shared local service trust;
- no reuse of an unmanaged pre-existing Linux account;
- no control-plane account in every site's Unix group.

This gives strong isolation for mutually untrusted applications on a normal Linux host. It is still not the same as a separate VM. If customers can upload arbitrary hostile native code, require kernel-level containment such as a VM, microVM, or carefully hardened container/network namespace.

## 2. What the CloudPanel study proves

CloudPanel contains several patterns worth adopting:

- a unique Linux user per site;
- a separate PHP-FPM pool per site;
- database records as the desired state and generated files as projections;
- one generated vhost per domain;
- per-site logs and predictable directory contracts;
- an unknown-host catch-all response;
- config validation before activation;
- a typed privileged-operation layer;
- a site creation and deletion sequence with deliberate ordering.

It should not be copied literally. The local CloudPanel analysis also identifies these gaps:

- Nginx workers run as root on the inspected installation;
- FPM pools listen on loopback TCP ports, which do not separate local users;
- PHP CLI selection is server-wide rather than site-wide;
- PHP settings sent through FastCGI do not apply to CLI;
- there is no hard aggregate memory ceiling for all FPM pools;
- passwordless unrestricted sudo remains a broad privilege boundary;
- pool port discovery is race-prone;
- no disk or cgroup quota provides a complete noisy-neighbour boundary.

Vito already uses Unix FPM sockets, which is a better starting point than CloudPanel's loopback ports. Vito should retain Unix sockets but make each socket belong to a **site**, not to a shared user/version pair.

## 3. Current Vito flow

### 3.1 Site identity

During creation, Vito chooses either the server SSH user or an `IsolatedUser`. Multiple sites may intentionally share the same isolated user. The site path becomes `/home/<user>/<domain>`.

Current consequence:

- unique user: useful file and process separation;
- shared isolated user: sites are in the same trust boundary and can normally access each other's files and processes;
- server SSH user: no meaningful site isolation and especially dangerous for PHP because that account has passwordless sudo.

### 3.2 Nginx request flow

```text
DNS for app.example.com
        |
        v
Nginx :80/:443
        |
        +-- selects generated server_name vhost
        |
        +-- static file from /home/<site-user>/<domain>/...
        |
        `-- PHP request -> site/user FPM socket -> PHP worker UID
```

Subdomains work because Nginx selects a `server` block by `server_name`. DNS and TLS must still cover each hostname. This routing is independent from process isolation: two correctly routed subdomains can still share one Unix user, one FPM pool, Redis, and server resources.

Vito currently writes the vhost and reloads Nginx. It does not provide a last-known-good, validate-then-activate transaction around that write.

### 3.3 PHP-FPM flow

For isolated sites, Vito currently creates:

```text
/etc/php/<version>/fpm/pool.d/<user>.conf
/run/php/php<version>-fpm-<user>.sock
```

The pool runs as the site user. However, it is keyed by user and PHP version, so sibling sites sharing that user/version also share:

- pool tuning;
- worker processes;
- socket;
- error log;
- process capacity and failure pressure.

The pool socket is hardcoded to owner/group `vito`, and Nginx workers also run as the server SSH user. This couples the web data plane to the passwordless-sudo control account.

### 3.4 PHP CLI, deployments, workers, and cron

Vito's managed deployment shell exposes `/usr/bin/php<site-version>` and aliases `php` during that managed session. That is a good partial solution.

It is not yet universal:

- interactive shells rely on the normal user `PATH`;
- Supervisor stores the command as entered and adds only the current tooling paths;
- cron stores the command in the user's crontab and invokes `bash -lc`;
- a bare `php` in a worker or cron can therefore use the server default CLI;
- FPM-only PHP settings do not automatically apply to CLI commands.

Changing the site PHP version must update every one of these consumers as a single logical operation.

## 4. Isolation levels Vito should expose

Do not label every separate user as “fully isolated.” Expose an honest isolation profile.

| Profile | Intended use | Boundary |
|---|---|---|
| Shared | Same owner and mutually trusted applications | Sites may share UID, tooling, and optionally runtime resources |
| Isolated | Default production site | Unique UID, site socket/pool, filesystem boundary, pinned CLI, per-site tuning |
| Hardened | Mutually untrusted applications on one host | Isolated plus per-site FPM service, systemd slice, quotas, tighter network/service access |
| VM/container | Hostile arbitrary code or regulatory boundary | Separate kernel/namespace boundary; outside normal vhost isolation |

Rules:

1. A new site's default should be `isolated` with a new system user.
2. Sharing a user must be an explicit “shared trust group” choice with a warning.
3. A shared-user site must never show “fully isolated.”
4. Legacy sites using the control account should be marked `legacy_unisolated` and offered migration.
5. Vito must reject a username that already exists on the server unless it is a Vito-managed identity with a matching immutable marker.

## 5. Target architecture

```text
Vito application/control plane
        |
        | typed, validated privileged operation
        v
Vito agent or restricted SSH management identity
        |
        +-- render -> validate -> atomically activate Nginx config
        +-- render -> validate -> atomically activate site FPM config
        +-- manage site UID, files, quota, systemd slice
        `-- report effective state and drift

Internet -> Nginx worker (www-data, no sudo)
                  |
                  +-- static files allowed by traversal/ACL rules
                  `-- /run/php/vito-site-<site-id>.sock
                                  |
                                  v
                         PHP worker as site UID
                                  |
          +-----------------------+-----------------------+
          |                       |                       |
    site filesystem       site DB credential       site Redis ACL/socket

Site CLI / deploy / cron / workers
        `-- the same SiteRuntimeProfile -> /usr/bin/php<version>
```

### Core invariant

Every runtime artifact is addressed by immutable site ID, while domains remain routing aliases:

```text
pool:      vito-site-<site-id>
socket:    /run/php/vito-site-<site-id>.sock
vhost:     /etc/nginx/sites-available/vito-site-<site-id>.conf
logs:      /var/log/vito/sites/<site-id>/...
slice:     site-<site-id>.slice
```

A domain can change without renaming every runtime artifact. Human-readable comments can retain the current domain.

## 6. Proposed data model

Keep `sites.php_version` initially for compatibility, but add one authoritative relation for runtime configuration.

### `site_runtime_profiles`

One row per site:

```text
site_id                    unique foreign key
isolation_profile          shared | isolated | hardened
php_version                installed service version
fpm_service_mode           shared_master | dedicated_master
fpm_process_manager        ondemand | dynamic
fpm_max_children
fpm_start_servers          nullable
fpm_min_spare_servers      nullable
fpm_max_spare_servers      nullable
fpm_idle_timeout_seconds   nullable
fpm_max_requests
request_timeout_seconds
slow_request_seconds       nullable
memory_limit_mb
max_execution_time_seconds
max_input_time_seconds
max_input_vars
post_max_size_mb
upload_max_filesize_mb
cpu_quota_percent          nullable
memory_high_mb             nullable
memory_max_mb              nullable
tasks_max                  nullable
disk_quota_mb              nullable
desired_revision
applied_revision
last_applied_at            nullable
last_apply_error           encrypted or redacted text
```

Known fields should be columns, not an unrestricted PHP/INI text box. This permits validation, UI explanations, audit, and capacity calculations. An advanced INI allowlist may be added later.

### `site_web_profiles`

One row per site:

```text
site_id                    unique foreign key
client_max_body_size_mb
fastcgi_read_timeout_seconds
proxy_connect_timeout_seconds
proxy_read_timeout_seconds
static_cache_policy
rate_limit_profile         nullable
symlink_policy
access_log_enabled
desired_revision
applied_revision
last_apply_error
```

### Reservation and audit tables

- `server_hostname_reservations`: unique `(server_id, normalized_hostname)` covering primary domains, aliases, and redirects.
- `server_port_reservations`: unique `(server_id, protocol, port)` for reverse proxies, Octane, Node, and other listeners.
- `server_runtime_operations`: actor, operation type, site, desired revision, timestamps, outcome, and redacted error.

Reservations must be acquired inside a database transaction before dispatching SSH work. Application validation alone is not concurrency-safe.

### Always-on security invariants

The apply layer should fail closed with explicit exceptions when an internally impossible state is detected. These checks complement input validation and tests:

- an `isolated` or `hardened` site has exactly one Vito-managed UID and does not share it;
- every runtime/web profile and reservation belongs to the same server and site being applied;
- a site-keyed socket, pool, vhost, log path, and slice are derived from the immutable site ID;
- the selected PHP version is installed on the target server before any file is changed;
- the activated config checksum equals the rendered desired revision;
- an operation is never marked applied before validation, reload, and health probing succeed;
- audit messages contain opaque IDs and redacted errors, never credentials or private key material.

These are internal guarantees, so violations should throw and stop the operation rather than log and continue. Invalid user input should still produce normal validation errors.

## 7. Step-by-step implementation plan

### Phase 0 — agree on the security contract

Before code changes, record these decisions in an ADR:

1. Is unique UID per new site mandatory by default? Recommended: yes.
2. Will shared users remain supported? Recommended: yes, but rename the feature to “shared trust group.”
3. Does `hardened` require a separate FPM master per site? Recommended: yes.
4. Are arbitrary user-provided Nginx templates allowed? Recommended: keep for owners only, validate and audit every apply.
5. What is the supported threat model? Distinguish buggy apps, compromised PHP apps, and hostile native code.

Deliverable: `docs/adr/...-site-runtime-isolation.md`, after approval to add/use that documentation folder.

### Phase 1 — add desired-state models without changing servers

1. Add the runtime, web, hostname reservation, port reservation, and operation models/migrations.
2. Backfill one runtime/web profile per existing site from `sites.php_version` and `type_data.php`.
3. Classify existing sites:
   - control-account user -> `legacy_unisolated` migration state;
   - shared `isolated_user_id` -> `shared`;
   - unique isolated user -> `isolated`.
4. Continue reading old fields during the transition, but write new settings only through Actions.
5. Add API Resource fields only after confirming API scope with the maintainer.

Acceptance:

- migration is additive and reversible;
- no SSH is performed by the migration;
- backfilled effective values reproduce current generated vhosts;
- duplicate hostname/port preflight reports conflicts instead of silently dropping data.

### Phase 2 — create a safe configuration apply framework

Build small Actions/Services matching Vito's architecture:

```text
RenderSiteNginxConfig
ValidateSiteNginxConfig
ApplySiteNginxConfig
RenderSiteFpmConfig
ValidateSiteFpmConfig
ApplySiteFpmConfig
ApplySiteRuntimeProfile
```

Remote apply sequence:

1. acquire a server/site operation lock;
2. render from database desired state;
3. write to a root-owned staging path;
4. validate syntax against the complete service configuration;
5. snapshot the currently active file;
6. atomically rename staging into place;
7. reload only the affected service;
8. run a health probe;
9. mark `applied_revision` only after success;
10. restore the previous file and reload if activation or health probing fails.

Nginx validation must use `nginx -t`. FPM validation must call the version-specific FPM binary/config test. Never replace last-known-good configuration before validation succeeds.

### Phase 3 — separate management, web, and tenant identities

1. Change Nginx workers from the server SSH/control user to a dedicated non-sudo identity such as `www-data`.
2. Stop adding the control user to every site's Unix group.
3. Grant the Nginx worker only what it needs:
   - execute/traverse parent directories;
   - read public assets;
   - connect to the site's FPM socket;
   - write nowhere in application code.
4. Keep root-owned Nginx config and certificate directories outside site homes.
5. Change private keys to `0600` or narrowly scoped `0640`.
6. Add a Vito ownership marker for every managed Linux account. Refuse unmanaged account reuse.

Recommended filesystem contract:

```text
/home                             root:root 0711
/home/<site-user>                 <user>:<user> 0750
/home/<site-user>/<domain>        <user>:<user> 0750
application directories          0750
application files                0640
public read access               ACL/group granted only where needed
storage/cache writable paths     site user only; Nginx does not need write access
.ssh                              0700; authorized_keys 0600
tmp and private logs              0700
```

Set a predictable per-site umask (`0027` for unique users; a documented group-friendly policy only for shared trust groups). Do not use blanket recursive `755`.

### Phase 4 — make PHP-FPM a site resource

Replace user-keyed pools with site-keyed pools:

```ini
[vito-site-123]
user = site_123
group = site_123

listen = /run/php/vito-site-123.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = <validated profile value>
pm.process_idle_timeout = <profile value>s
pm.max_requests = <profile value>

request_terminate_timeout = <profile value>s
request_slowlog_timeout = <profile value>s
slowlog = /var/log/vito/sites/123/php-slow.log
catch_workers_output = yes

php_admin_value[memory_limit] = <hard limit>M
php_admin_value[max_execution_time] = <hard limit>
php_admin_value[upload_tmp_dir] = /home/site_123/tmp
php_admin_value[session.save_path] = /home/site_123/tmp
php_admin_value[error_log] = /var/log/vito/sites/123/php-error.log
```

Important decisions:

- `ondemand` is the recommended default for many mostly idle sites.
- `dynamic` remains available for latency-sensitive sites and must require start/min/max spare values.
- `pm.max_children` must be bounded by the site's memory budget and the server's aggregate capacity.
- hard ceilings belong in `php_admin_value`; preferences may remain in FastCGI `PHP_VALUE` only when runtime override is intentionally allowed.
- `open_basedir` is defence in depth, not the primary sandbox. Unix UID and directory permissions are the primary boundary.

For the `isolated` profile, all site pools may continue under the shared version-specific FPM master. For `hardened`, create one FPM master systemd unit per site so its entire process tree can live in the site's cgroup.

### Phase 5 — make PHP CLI deterministic everywhere

Introduce one `SiteRuntimeCommand`/environment resolver used by:

- deployment scripts;
- one-off site commands;
- Composer tooling;
- Supervisor workers;
- cron jobs;
- interactive site SSH instructions;
- maintenance commands generated by Vito.

At site creation or PHP version change:

```text
/home/<site-user>/bin/php -> /usr/bin/php<configured-version>
```

Also render an explicit environment:

```text
VITO_SITE_ID=<id>
PHP_VERSION=<version>
PHP_BINARY=/usr/bin/php<version>
PATH=/home/<user>/bin:<tooling paths>:/usr/local/bin:/usr/bin:/bin
```

Rules:

1. Vito-generated worker and cron commands use the absolute PHP binary.
2. User-entered bare `php` is normalized or executed with the site PATH.
3. CLI limits that must match FPM are passed with a generated site CLI INI directory or explicit `-d` flags; document intentional differences.
4. A PHP version switch stages the new pool, CLI link/environment, vhost, workers, and cron before committing the database state.
5. Failure rolls every consumer back to the old version.

### Phase 6 — harden Nginx and make vhost tuning structured

1. Keep Mustache generation as Vito's projection mechanism.
2. Add typed template data from `site_web_profiles` rather than concatenating arbitrary directives.
3. Add a catch-all HTTP vhost returning `444` and a catch-all TLS server rejecting unknown handshakes where supported.
4. Add and test `disable_symlinks if_not_owner from=/home/` or an equivalent policy.
5. Test the policy with Vito's release/current symlink deployment layout before enabling globally.
6. Separate per-site access/error logs and make rotation part of provisioning.
7. Ensure `/.well-known/acme-challenge/` remains reachable without site auth.
8. Reserve every primary domain, alias, redirect hostname, and application port transactionally.

Panel fields should include:

- upload/body size;
- FastCGI/proxy timeouts;
- static asset cache policy;
- rate-limit profile;
- access-log toggle;
- force HTTPS;
- trusted proxy/real-IP mode;
- basic auth and IP access controls;
- advanced template override with preview, diff, validation result, and restore-default action.

Never save a database “applied” state merely because template rendering succeeded. The server-side syntax and health checks must succeed.

### Phase 7 — put workers and cron inside the same boundary

1. Require a site association for site workers and site cron jobs.
2. Derive their user, working directory, environment, PHP binary, logs, and resource profile from the site; do not trust separately submitted values.
3. Move hardened workers from generic Supervisor into site-scoped systemd units, or launch them into the correct site slice.
4. Pin cron binaries and include a stable Vito marker so synchronization cannot confuse user-created lines with managed entries.
5. On PHP version/profile changes, regenerate affected workers and cron atomically.
6. Stop/kill site processes before removing the site user during deletion.

### Phase 8 — add hard resource and service isolation

For every hardened site, generate:

```ini
# /etc/systemd/system/site-123.slice
[Slice]
MemoryHigh=<soft limit>
MemoryMax=<hard limit>
CPUQuota=<percentage>
TasksMax=<count>
IOWeight=<weight>
```

Place the dedicated FPM unit and Vito-managed workers in the slice. Add:

- per-UID or per-project filesystem quotas;
- log size and retention limits;
- backup space accounting;
- per-site DB user/database with least privilege;
- per-site Redis ACL credentials, key prefixes plus ACL restrictions, or a per-site Unix-socket Redis instance for stronger boundaries;
- alerts for memory pressure, task exhaustion, disk quota, slow requests, and repeated restarts.

Important: Unix users share the host network namespace. They can normally reach localhost databases, Redis, and application ports. Unix permissions on FPM sockets solve FPM cross-connection, but full network isolation requires a namespace/container/firewall design. Do not claim hardened network isolation until an integration test proves it.

### Phase 9 — reduce the privileged control surface

Short-term:

1. centralize privileged SSH templates by operation;
2. validate every domain, username, path, service version, port, and numeric tuning field before rendering;
3. pass secrets through protected files or stdin, not command arguments;
4. audit actor, target, operation, desired revision, and outcome;
5. narrow sudoers to required commands where practical.

Long-term recommended design:

- install a small root-owned Vito agent on the managed server;
- expose a versioned typed protocol over a protected Unix socket or mutually authenticated channel;
- accept only schema-validated operations such as `ApplyNginxSiteConfig`, `ApplyFpmSiteConfig`, `CreateSiteIdentity`, and `DeleteSiteIdentity`;
- never accept an arbitrary root shell command from the panel;
- remove `NOPASSWD:ALL` from the normal management identity.

This is a major security boundary and should be a separate project after the site-keyed runtime work is stable.

### Phase 10 — migration and rollout

Preflight every server:

1. inventory sites using the control user;
2. inventory shared isolated users;
3. detect unmanaged/pre-existing accounts;
4. detect duplicate primary/alias hostnames;
5. detect duplicate proxy/application ports;
6. inventory current FPM pools, sockets, PHP versions, workers, cron, permissions, and symlinks;
7. calculate estimated aggregate FPM memory exposure;
8. snapshot database records and remote configs.

Migration order per site:

1. create or verify the managed site identity;
2. stage directory ownership/ACL changes;
3. create the site-keyed FPM pool/socket;
4. create the site CLI link and environment;
5. regenerate workers and cron;
6. stage and validate the new vhost;
7. atomically switch the vhost to the site socket;
8. health check HTTP and PHP;
9. remove the old user-keyed pool only when no site references it;
10. mark the runtime revision applied.

Roll out to one disposable server, then one low-risk site, then a small canary group. Keep the previous config and pool until the post-switch health check passes.

## 8. Deletion sequence

Deletion must remove reachability before destroying data:

1. require an optional snapshot/export decision;
2. stop site workers and cron;
3. stop/remove the site FPM pool or dedicated service;
4. replace/disable the vhost and validate/reload Nginx;
5. revoke certificates, credentials, Redis ACLs, and DB access;
6. terminate remaining processes owned by the site UID;
7. archive or remove home/log data according to retention policy;
8. remove quota and systemd artifacts;
9. remove the managed Linux user only if no shared trust group references it;
10. delete database rows and reservations;
11. record a final redacted audit event.

The operation must be resumable. A failed step should leave a visible `deletion_failed` state and a safe retry path rather than silently deleting the Vito row.

## 9. Required security and integration tests

Unit/feature tests with `SSH::fake()` should verify rendering, validation, locks, state transitions, and rollback commands. A disposable Ubuntu integration environment must prove the operating-system boundary.

Mandatory acceptance tests:

1. Site A's PHP and shell user cannot read or modify Site B's private files.
2. An Nginx request through a Site A symlink to Site B is denied.
3. Site A cannot connect to Site B's FPM socket.
4. Nginx can connect to each legitimate site socket without belonging to all site groups.
5. FPM, deploy, one-off command, worker, cron, and interactive shell all report the configured PHP major/minor version.
6. A PHP version switch either changes all consumers or rolls all of them back.
7. Invalid Nginx/FPM configuration never replaces the last-known-good file.
8. Concurrent attempts to reserve the same hostname or port result in one success and one validation failure.
9. Exhausting one site's process/memory/disk allowance does not take another site down in the hardened profile.
10. A compromised site UID cannot invoke privileged Vito management operations.
11. Unknown HTTP hosts return the catch-all response; unknown TLS hosts do not expose a tenant certificate.
12. Deletion stops processes and reachability before removing credentials and files.
13. Reapplying the same desired revision is idempotent.
14. Drift between database desired state and remote files is detected and repairable.

## 10. Panel experience

Add an “Isolation & Runtime” settings area with these sections:

- **Isolation:** current profile, Linux identity, whether it is shared, migration readiness, and honest boundary description.
- **PHP & FPM:** version, process manager, children, idle/max requests, timeouts, memory/upload/input settings, effective socket, and estimated memory exposure.
- **CLI & Processes:** effective PHP binary and version for deploy, workers, cron, and interactive SSH.
- **Nginx:** structured limits, cache, rate limits, logs, vhost preview, config validation, and effective deployed revision.
- **Resources:** CPU, memory, tasks, disk, and current usage.
- **Drift & Health:** desired/applied revisions, last apply, health result, detected drift, and repair action.

Every risky save should show:

- what service will reload/restart;
- whether requests may briefly fail;
- generated config diff;
- validation result;
- rollback result if apply fails.

## 11. Agreed implementation order

Do not implement everything in one pull request. The work should follow these stages and should not begin a later stage until the preceding boundary is stable.

### Stage 1 — foundation

Working branch: `feat/site-isolation-foundation`

Scope:

1. Record the isolation profiles and security invariants in an ADR.
2. Add the authoritative runtime/web profile models and additive schema.
3. Add hostname, port, desired-revision, and operation/audit primitives.
4. Add immutable site-based runtime artifact naming.
5. Add reusable render, validate, apply, rollback, and operation-lock contracts.
6. Backfill existing sites without changing their live server configuration.
7. Add tests for model boundaries, reservations, revisions, locks, and failure states.

This stage must not change live Nginx, PHP, user, worker, or cron behaviour. Its purpose is to give later work one safe source of truth and one configuration lifecycle.

### Stage 2 — site creation and Nginx

Suggested branch: `feat/site-isolation-site-nginx`

Scope:

1. Make a unique Vito-managed Linux identity the default for new isolated sites.
2. Detect and reject unmanaged pre-existing account reuse.
3. Treat shared users as explicit shared trust groups.
4. Reserve all primary domains, aliases, redirects, and application ports transactionally.
5. Establish the filesystem ownership, permissions, log, and temporary-directory contract during creation.
6. Change Nginx workers to a dedicated non-sudo identity.
7. Generate site-ID-keyed vhost artifacts.
8. Stage, validate, atomically activate, health-check, and roll back Nginx configuration.
9. Add the unknown-host catch-all and tested symlink policy.
10. Make site creation resumable and clean up only artifacts created by the failed operation.

This stage is complete when multiple domain and subdomain sites route correctly and their static/private files remain separated without PHP being part of the proof.

### Stage 3 — PHP CLI and PHP-FPM

Suggested branch: `feat/site-isolation-php-runtime`

Scope:

1. Replace user/version-keyed FPM pools with site-ID-keyed pools and Unix sockets.
2. Validate, atomically apply, reload, health-check, and roll back FPM configuration.
3. Introduce the single site runtime resolver for PHP binary, version, PATH, and CLI settings.
4. Apply that resolver to deployments, one-off commands, Composer, interactive SSH, workers, and cron.
5. Make PHP version switching transactional across FPM, vhost, CLI, workers, and cron.
6. Migrate existing shared pools without interrupting sibling sites.
7. Prove cross-site socket denial and identical PHP version selection across all execution paths.

### Stage 4 — optimization and per-site tuning

Suggested branch: `feat/site-runtime-tuning`

Scope:

1. Add typed Nginx and FPM tuning controls to the panel.
2. Support `ondemand` and `dynamic` FPM profiles with safe validation.
3. Add server-capacity warnings and aggregate FPM memory calculations.
4. Add slow logs, per-site metrics, config previews, effective values, drift detection, and repair.
5. Add hardened site FPM units, systemd slices, task/memory/CPU limits, and filesystem quotas.
6. Load-test defaults before publishing optimization presets.

Optimization must follow measured workload data. Do not present one set of FPM values as correct for every application.

### Stage 5 — remaining isolation work

Suggested branches should be split by concern rather than combined into one large change.

- per-site database and Redis access boundaries;
- deletion, export, snapshot, and disaster-recovery improvements;
- restricted typed Vito agent and removal of unrestricted sudo;
- network namespace/container options for hostile workloads;
- fleet-wide migration, monitoring, and operational runbooks.

Every stage should include backward compatibility, focused tests, disposable-server integration proof, and rollback documentation. Finish and review one branch before creating the next implementation branch.

## 12. What “done” means

Vito may describe the normal profile as “isolated” only when all of these are true:

- unique Vito-managed UID;
- no shared site group or control-account membership dependency;
- site-specific FPM pool and Unix socket;
- non-privileged Nginx worker;
- enforced filesystem and symlink boundary;
- deterministic site PHP CLI across all execution paths;
- unique hostnames and ports;
- validated atomic config changes;
- separate DB and cache credentials;
- clear disclosure that CPU, memory, disk, and network are shared unless hardened controls are enabled.

Use “hardened” only after cgroup/quota and local-service access tests pass. Never use “VM-level isolation” for a shared-host vhost design.

## 13. Repository evidence used

Vito implementation:

- [`app/Actions/Site/CreateSite.php`](app/Actions/Site/CreateSite.php)
- [`app/Actions/Site/UpdatePHPSettings.php`](app/Actions/Site/UpdatePHPSettings.php)
- [`app/Actions/Site/UpdatePHPVersion.php`](app/Actions/Site/UpdatePHPVersion.php)
- [`app/Actions/Site/DeleteSite.php`](app/Actions/Site/DeleteSite.php)
- [`app/Actions/Webserver/GenerateNginxConfig.php`](app/Actions/Webserver/GenerateNginxConfig.php)
- [`app/Services/Webserver/Nginx.php`](app/Services/Webserver/Nginx.php)
- [`app/Services/PHP/PHP.php`](app/Services/PHP/PHP.php)
- [`app/Helpers/SiteShellEnvironment.php`](app/Helpers/SiteShellEnvironment.php)
- [`app/Models/CronJob.php`](app/Models/CronJob.php)
- [`app/Models/Worker.php`](app/Models/Worker.php)
- [`resources/views/ssh/os/create-user.blade.php`](resources/views/ssh/os/create-user.blade.php)
- [`resources/views/ssh/os/create-isolated-user.blade.php`](resources/views/ssh/os/create-isolated-user.blade.php)
- [`resources/views/ssh/services/php/fpm-pool.blade.php`](resources/views/ssh/services/php/fpm-pool.blade.php)
- [`resources/views/ssh/services/webserver/nginx/nginx.blade.php`](resources/views/ssh/services/webserver/nginx/nginx.blade.php)
- [`resources/views/ssh/services/webserver/nginx/vhost.mustache`](resources/views/ssh/services/webserver/nginx/vhost.mustache)
- [`resources/views/ssh/services/process-manager/supervisor/worker.blade.php`](resources/views/ssh/services/process-manager/supervisor/worker.blade.php)
- [`resources/views/ssh/cron/update.blade.php`](resources/views/ssh/cron/update.blade.php)

CloudPanel local study:

- [`cloudpanel.io/cloudpanel-architecture.md`](cloudpanel.io/cloudpanel-architecture.md)
- [`cloudpanel.io/cloudpanel-security-access-monitoring.md`](cloudpanel.io/cloudpanel-security-access-monitoring.md)
- [`cloudpanel.io/cloudpanel-database-backup-cron.md`](cloudpanel.io/cloudpanel-database-backup-cron.md)
- [`cloudpanel.io/cloudpanel-summary.md`](cloudpanel.io/cloudpanel-summary.md)

The CloudPanel documents are used as design evidence and comparison material, not as proof that every CloudPanel release or installation has identical runtime configuration.
