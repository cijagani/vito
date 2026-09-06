# Vito Site Isolation and Shared-Server Flow Review

## Review scope

This is a source-level review of Vito `4.x` at commit
`c72f3d039cdc707e69b9659e30fa7727547a1175` (reviewed 2026-09-06). It focuses on:

- panel users and server-side Linux users;
- site creation, deployment, commands, cron jobs, and workers;
- Nginx host routing for multiple domains and subdomains;
- PHP-FPM pools and PHP CLI selection;
- what is and is not isolated when several sites share one server.

This review describes what the repository configures. A server can drift after provisioning, so the final section gives
commands for checking the effective production state.

## Short answer

Multiple sites such as `app.example.com`, `api.example.com`, and `admin.example.com` can run on one server. DNS sends
all three names to the same IP, and Nginx selects a site-specific virtual host from the HTTP `Host` name.

The sites receive meaningful Unix-account isolation **only when each site has a different isolated user**. This is a
useful defense-in-depth boundary, but it is not full sandboxing:

- sites still share the kernel, Nginx, PHP-FPM service instances, loopback network, databases/Redis, disk, CPU, and RAM;
- sites using the same isolated user intentionally share one security boundary, their files, SSH identity, tooling,
  cron context, and (for the same PHP version) FPM pool;
- Vito's server control account and project administrators can manage every site and can obtain root-level access;
- the current Nginx/control-user arrangement creates a likely cross-site file-read path that must be hardened before
  treating unique Unix users as a hostile-tenant boundary.

Therefore:

- **Same owner, mutually trusted applications:** one server with a unique isolated user per site is reasonable.
- **Related sites that intentionally need shared files/tooling:** sharing one isolated user works, but those sites are
  not isolated from each other.
- **Untrusted customers or mutually hostile workloads:** use separate VMs/servers (or a separately designed container,
  namespace, MAC-policy, and resource-control layer). The current Vito site user is not a complete tenant sandbox.

## The identities Vito uses

The word “user” refers to several different identities. They should not be confused.

| Identity | Where it exists | Purpose | Security boundary |
| --- | --- | --- | --- |
| Panel user | Vito database | Signs into Vito and belongs to projects as `owner`, `admin`, or `user` | Controls what the person can view/change in the panel |
| Initial provider user | Remote server, often `root` or `ubuntu` | Used only to bootstrap a new/custom server | Initial privileged entry point |
| Vito server user | Remote server, normally `vito` | Persistent SSH/control account used by Vito | Passwordless sudo; trusted control plane, not a tenant |
| Isolated site user | Remote server, e.g. `site_app` | Owns a home directory and runs site processes | The main per-site Unix UID/GID boundary |
| Nginx worker user | Remote server, currently the Vito server user | Reads static files and connects to app/FPM backends | Shared by every Nginx-hosted site |
| PHP-FPM pool user | Remote server | Executes PHP for one isolated username and PHP version | Separate UID for unique users; shared when the username is shared |
| Database user | MySQL/PostgreSQL | Grants access to selected databases | Separate from the Linux user; must be provisioned and linked explicitly |

Panel project roles are broad. The policy helpers give `owner`, `admin`, and `user` read access, but only `owner` and
`admin` write access. The server console requires write access. An owner/admin can select the server user, any isolated
user, and normally `root`, so panel administration is a server-wide trust role, not a site-only role. See
[`HasRolePolicies`](app/Traits/HasRolePolicies.php), [`ConsoleController`](app/Http/Controllers/ConsoleController.php),
and [`Server::sshLoginUsers()`](app/Models/Server.php).

## End-to-end flow

### 1. Server provisioning creates the control plane

1. Vito connects using the provider's initial SSH account.
2. It creates the configured server account, normally `vito`.
3. That account is added to the `sudo` group and receives `NOPASSWD:ALL` in `/etc/sudoers`.
4. Vito stores one server key pair and uses it for later SSH operations.
5. Services such as Nginx, PHP-FPM, Supervisor, databases, and Redis are installed centrally for the server.

The important consequence is that `vito` is effectively root. It is intentionally trusted and must never be considered
part of the tenant boundary. Evidence: [`InstallServer`](app/Actions/Server/InstallServer.php),
[`create-user.blade.php`](resources/views/ssh/os/create-user.blade.php), and
[`Server::sshKey()`](app/Models/Server.php).

Nginx is configured to run its workers as this same server account, not as a dedicated `www-data`/`nginx` account. See
[`Nginx::install()`](app/Services/Webserver/Nginx.php) and
[`nginx.blade.php`](resources/views/ssh/services/webserver/nginx/nginx.blade.php).

### 2. The panel creates the site record first

For a new site, [`CreateSite`](app/Actions/Site/CreateSite.php):

1. validates the site type, primary domain, username, and type-specific fields;
2. rejects reserved/system usernames and the server's SSH username;
3. creates or reuses one `IsolatedUser` row identified by `(server_id, username)`;
4. creates the `Site` with `/home/<user>/<domain>` as its path;
5. creates a primary hosted-domain row and optional aliases;
6. creates default commands/deployment metadata; and
7. dispatches `CreateJob` to the `ssh` queue.

New v4 sites must therefore choose an isolated username. Compatibility code still recognizes old sites whose raw user
is the server account as non-isolated. See [`Site::isIsolated()`](app/Models/Site.php) and the
[`isolated_users` migration](database/migrations/2026_05_24_100123_create_isolated_users_table.php).

### 3. The SSH job creates or reuses the Linux account

The site type's `install()` method calls `isolate()` before creating the vhost or cloning code. Isolation operations use
a cache lock keyed by server and isolated username so two concurrent jobs do not create/delete the same shared account
or pool simultaneously.

The Linux script then:

1. checks `id -u <user>`;
2. creates the user when it does not exist, otherwise treats it as reusable;
3. creates `/home/<user>`, `.logs`, `tmp`, `bin`, and `.ssh`;
4. installs the **same Vito server public key** in that user's `authorized_keys`;
5. adds the Vito server account to the isolated user's group;
6. recursively changes ownership of the home directory to the isolated user;
7. applies `0750` to the home, `0700` to private directories, and `0600` to `authorized_keys`; and
8. sets Bash as the login shell.

Evidence: [`AbstractSiteType::isolate()`](app/SiteTypes/AbstractSiteType.php) and
[`create-isolated-user.blade.php`](resources/views/ssh/os/create-isolated-user.blade.php).

Sharing an existing `IsolatedUser` row skips creation of a new security principal: both sites intentionally use the
same Linux UID and home directory.

### 4. Nginx maps a hostname to the site

For each site, Vito creates:

- `/etc/nginx/sites-available/<primary-domain>`;
- a symlink in `/etc/nginx/sites-enabled/`; and
- one or more `server_name` entries for the active primary/alias domains.

For PHP, Nginx serves static files from the site's configured web directory and sends `.php` requests to a Unix socket.
For Node/Bun/proxied sites, it sends traffic to `localhost:<port>`. Unknown hostnames reach the `000-default` Vito splash
instead of a particular site.

Evidence: [`GenerateNginxConfig`](app/Actions/Webserver/GenerateNginxConfig.php),
[`AbstractGenerateConfig`](app/Actions/Webserver/AbstractGenerateConfig.php), and the
[`Nginx vhost template`](resources/views/ssh/services/webserver/nginx/vhost.mustache).

DNS is a separate prerequisite. Creating a site does not inherently create the public A/AAAA/CNAME record. The domain
must already resolve to the server (manually or through separately managed DNS), after which Vito verifies it by serving
a challenge. A wildcard DNS record such as `*.example.com -> server IP` can avoid adding one DNS record per subdomain,
but each actual site still needs its own Vito/Nginx vhost. See
[`CheckDomainJob`](app/Jobs/HostedDomain/CheckDomainJob.php) and
[`VerifyHostedDomain`](app/Actions/HostedDomain/VerifyHostedDomain.php).

### 5. PHP-FPM provides process separation

For an isolated PHP site, the upstream is:

```text
/run/php/php<version>-fpm-<isolated-user>.sock
```

The pool:

- runs with `user=<isolated-user>` and `group=<isolated-user>`;
- uses a socket owned by `vito:vito` with mode `0660`;
- has a five-child dynamic pool by default;
- sets `open_basedir` to `/home/<isolated-user>/:/tmp/`; and
- stores upload/session temp files and PHP errors under that user's home.

This means two unique usernames get different UIDs and pools. Two sites with the same username **and the same PHP
version share the same pool, socket, error log, and five-child capacity**. The same username on two PHP versions gets two
pools, but both still execute with the same UID and can access the same files.

Changing a site's PHP version creates the new pool, updates the vhost, and removes the old pool only when no sibling
still uses that `(isolated user, PHP version)` pair. Deleting the last sibling removes the pool and Linux account.

Evidence: [`fpm-pool.blade.php`](resources/views/ssh/services/php/fpm-pool.blade.php),
[`PHP::createFpmPool()`](app/Services/PHP/PHP.php), [`UpdatePHPVersion`](app/Actions/Site/UpdatePHPVersion.php), and
[`DeleteSite`](app/Actions/Site/DeleteSite.php).

`open_basedir` is an additional PHP file-API restriction. It is not a kernel sandbox and does not isolate sockets,
network connections, CPU/RAM, subprocesses, or non-PHP programs.

### 6. Deployments and saved site commands run as the site user

Vito always opens the underlying SSH connection with the privileged server control account. For a site operation,
[`SSH::exec()`](app/Helpers/SSH.php) wraps the payload in:

```bash
sudo -u <site-user> bash <<'EOF'
cd ~
...
EOF
```

The deployment/command runner then changes to the site/release path, exports site variables and tooling paths, enables
shell aliases, and executes the user-controlled script. Its `php` alias points to `/usr/bin/php<site.php_version>`, so
**Vito-managed deployments and saved site commands use the site's selected PHP CLI version**, independently of the
server-wide default.

Evidence: [`DeployJob`](app/Jobs/Site/DeployJob.php),
[`ExecuteCommandJob`](app/Jobs/Site/ExecuteCommandJob.php), [`OS::runScript()`](app/SSH/OS/OS.php), and
[`Site::environmentAliases()`](app/Models/Site.php).

Classic deployment runs in the site's main path. Modern deployment builds a new release as the same isolated user,
links shared resources, runs pre-flight steps, and changes the current release symlink.

### 7. Interactive terminal, workers, and cron have different CLI behavior

These execution paths are not identical:

| Path | Effective user | Working directory/environment | PHP selected by `php` |
| --- | --- | --- | --- |
| Deployment | Site user | Site/release directory; Vito exports variables/tooling and aliases | Site PHP version via alias |
| Saved site command | Site user | Site directory; same Vito environment/aliases | Site PHP version via alias |
| Browser terminal | Direct SSH login as selected user | Real interactive login shell/home | Server-wide `/usr/bin/php` default unless user invokes `phpX` |
| Supervisor worker | Configured `user=`; site worker normally site user | Site directory; tooling PATH is injected | Server-wide `/usr/bin/php` unless command uses `phpX`/`$PHP_PATH` explicitly |
| Cron | Selected user's own crontab | `bash -lc`; normally starts from that user's home | Server-wide `/usr/bin/php` unless command uses an explicit binary |

The browser terminal authenticates directly as the selected Linux account with Vito's server private key; it does not
use the deployment wrapper. Site tooling adds profile/PATH lines for interactive sessions, but PHP itself remains a
server-level CLI choice. See [`TerminalSession`](app/WebSocket/TerminalSession.php),
[`Supervisor`](app/Services/ProcessManager/Supervisor.php), and [`CronJob`](app/Models/CronJob.php).

For a Laravel worker or scheduler, prefer an explicit command such as:

```bash
cd /home/site_api/api.example.com && /usr/bin/php8.4 artisan queue:work
cd /home/site_api/api.example.com && /usr/bin/php8.4 artisan schedule:run
```

### 8. Deletion follows the shared-user boundary

Deleting a site removes its Nginx vhost and site path. Vito preserves a PHP pool if another sibling uses the same
username and PHP version. It preserves the Linux account if any sibling uses the username. Only deletion of the last
sibling deletes the Linux user and its entire home directory.

This is correct for Vito-managed shared users, but makes accidental reuse of a pre-existing Linux account especially
dangerous (see Finding 3).

## What is actually isolated?

| Resource or capability | Unique isolated users | Shared isolated user | Notes |
| --- | --- | --- | --- |
| Direct Unix file access | Mostly separated by UID/GID and `0750` home | Not separated | Nginx shared-user caveat below |
| PHP execution UID | Different | Same | Pool is also shared for same PHP version |
| Node/Bun/worker UID | Different when configured correctly | Same | Supervisor itself is shared/root-managed |
| Deployment and saved commands | Run as each site's user | Same UID and full mutual access | Panel owner/admin can operate all sites |
| SSH login | Different accounts | Same account | One Vito server key is authorized across accounts |
| Tooling/runtime versions | Per isolated user | Shared | Changing tooling affects all sibling sites |
| Cron | Per Linux user's crontab | Shared crontab | Site association is panel metadata, not an OS boundary |
| Database data | Not automatically isolated | Not automatically isolated | Requires a distinct DB and least-privilege DB user |
| Redis/Valkey | Shared local service | Shared local service | No per-site ACL/database boundary is created |
| Loopback ports/network | Shared | Shared | Any local process can attempt connections to local services |
| Nginx | Shared | Shared | One daemon and configuration for all sites |
| PHP installation/extensions/global INI | Shared by PHP version | Shared | Per-site values are only selected overrides |
| CPU, RAM, disk I/O, process count | Shared; no site cgroup quota found | Shared | FPM has a child count, not a complete resource quota |
| Kernel and OS packages | Shared | Shared | Kernel/daemon exploit affects the whole server |
| Vito control plane | Server-wide | Server-wide | Server user is passwordless sudo; admins can open privileged terminal |

## Findings and gaps

### Finding 1 — High: the shared Nginx/control identity weakens cross-site file confidentiality

Confirmed facts:

- the `vito` control account has passwordless sudo;
- Nginx workers run as that account;
- that account is added to every isolated user's group;
- site homes are group-traversable (`0750`) and site paths are recursively set to `0755`;
- the vhost does not configure an Nginx `disable_symlinks` policy.

Derived attack path (needs a safe live-server regression test): a compromised site user can create a harmless-looking
symlink inside its own public tree that points to a group-readable file under another site's home. Nginx resolves the
request as `vito`, which can traverse both homes, and may serve the target even though the attacking Unix user cannot
read it directly. The dot-file URI rule does not necessarily help when the requested symlink name itself is not dotted.

Recommended design:

1. run Nginx under a dedicated, non-sudo web account;
2. make FPM sockets accessible to that web account instead of the privileged Vito account;
3. grant only the minimum execute/read ACLs needed for each site's public directory;
4. add and test a symlink policy compatible with modern deployment's same-owner `current` links; and
5. add a regression test proving site A cannot make Nginx return a non-public marker owned by site B.

### Finding 2 — Critical on upgraded servers: a legacy non-isolated PHP site can run as passwordless-sudo `vito`

The isolated-user migration deliberately leaves a site unlinked when its user equals the server SSH user. The default
PHP-FPM pool is changed from `www-data` to the server user during PHP installation. With the default configuration that
is `vito`, which has `NOPASSWD:ALL`.

Consequently, code execution in a legacy/non-isolated PHP site can become root by invoking sudo. New site validation
prevents choosing the server account, but it does not automatically migrate old sites.

Inventory every site where `isolated_user_id` is null or the effective site user equals the server SSH user. Recreate or
migrate those sites under real isolated users before relying on site separation.

### Finding 3 — High on pre-used/custom servers: an existing unrelated Linux account is treated as Vito-managed

The creation script checks whether the username exists, but there is no ownership marker proving that Vito created it.
If `alice` already exists outside Vito, creating a site with username `alice` reuses the account, recursively changes
ownership of `/home/alice`, adds the Vito key, changes permissions/shell, and adds the control user to its group. Deleting
the last such site later runs `userdel -r -f alice`.

Reserved names reduce collisions with common service accounts but do not solve arbitrary real-user collisions.

Before mutation, require a Vito-owned marker plus expected UID/home/group attributes. If an account exists without the
marker, fail site creation and require an explicit import workflow.

### Finding 4 — High for multi-tenant data: local Redis/Valkey is shared, not per-site isolated

Installation uses the distribution defaults. No per-site Redis ACL user, password, instance, or socket is created.
Network enablement can add one service-wide password, but that is still a server-service credential, not a tenant
boundary. Subject to the live distro configuration, a locally bound default Redis commonly accepts local clients
without authentication.

Any mutually untrusted applications using the same service can potentially read/overwrite keys or run destructive
commands. Use separate instances/sockets/containers or properly designed Redis ACL users and command/key-prefix rules;
Redis logical database numbers are not a security boundary.

### Finding 5 — Medium: hostname uniqueness is incomplete during initial site creation

Primary-domain validation checks only `sites.domain` on the server. Initial aliases are format-validated but are not
checked against every other hosted domain. The database uniqueness constraint is only `(site_id, domain)`. The later
“add hosted domain” flow correctly checks all hosted domains on the same server.

This allows an initial alias to collide with another site's primary/alias, or a new primary to equal an existing alias.
Nginx will then have duplicate `server_name` ownership and one site can unexpectedly receive the traffic.

Use one server-scoped hostname uniqueness invariant for both primary and alias creation, backed by data design that is
safe under concurrent requests.

### Finding 6 — Medium: reverse-proxy ports are validated but not reserved uniquely

Node/Bun/proxied site ports are checked only for the range `1024-65535`. No per-server uniqueness validation or
reservation was found. Two sites can select the same port; the second process may fail to bind, or Nginx may route both
hostnames to whichever process owns the port.

Reserve ports per server transactionally and verify the effective listener before marking a worker/site ready.

### Finding 7 — Medium: PHP CLI selection differs across execution paths

Deployments and saved commands alias `php` to the site's selected version. Interactive terminals, Supervisor commands,
and cron entries do not receive that alias. A worker shown as belonging to a PHP 8.4 site can therefore run the
server-wide default PHP 8.3 if its command simply starts with `php`.

Generate explicit `/usr/bin/php<version>` commands for site-bound workers/cron, or provide one shared wrapper that
applies the complete site shell environment consistently.

### Finding 8 — Medium: the FPM socket owner is hard-coded to `vito`

The control username is configurable through `SSH_USER`, and Nginx uses the configured username, but the isolated FPM
pool template hard-codes `listen.owner = vito` and `listen.group = vito`. A non-default control username can therefore
lose access to isolated FPM sockets.

Render the configured dedicated web user into the pool template and test with `SSH_USER` set to a non-default value.

### Finding 9 — Availability isolation is limited

No per-site cgroup/systemd resource boundary, filesystem quota, process limit, or network namespace was found. One site
can consume disk, CPU, RAM, PIDs, connection slots, local ports, or shared service capacity and affect every other site.
The FPM `pm.max_children=5` value limits one pool's concurrent PHP workers, but does not cap memory/CPU/disk and is shared
when sites share a user/pool.

Use per-site cgroups/systemd slices and quotas if noisy-neighbor resistance matters. Use separate VMs for hard limits.

### Finding 10 — The documentation overstates the guarantee

The current isolation guide says a compromised site “cannot reach another.” That is stronger than the implementation:
shared users explicitly have full mutual access, unique users share local services and resources, and Finding 1 presents
a plausible cross-user read path through Nginx.

Describe the feature as Unix-user process/file isolation with shared-server limitations, not “full isolation.”

## Recommended setup for multiple subdomain sites

For applications owned by the same organization but not intended to share files:

| Hostname | Vito site user | Site path | PHP/port | Data credential |
| --- | --- | --- | --- | --- |
| `app.example.com` | `ex_app` | `/home/ex_app/app.example.com` | PHP 8.4 pool `ex_app` | DB/user `ex_app` only |
| `api.example.com` | `ex_api` | `/home/ex_api/api.example.com` | PHP 8.4 pool `ex_api` | DB/user `ex_api` only |
| `admin.example.com` | `ex_admin` | `/home/ex_admin/admin.example.com` | unique proxy port, e.g. 3103 | separate credentials |

Use this checklist:

1. Point every hostname (or a wildcard DNS record) to the server's public IP.
2. Create one Vito site per hostname, not aliases, when they are independent applications.
3. Give every independent site a new, unique isolated username.
4. Never reuse a username merely for convenience; reuse means intentional mutual trust.
5. Give every reverse-proxy site a unique internal port and bind the app to `127.0.0.1` where possible.
6. Create separate databases and least-privilege DB users. Do not reuse `.env` credentials.
7. Do not treat Redis DB numbers/key prefixes as isolation; isolate the memory-data layer separately.
8. Use explicit PHP binaries in workers and cron.
9. Restrict Vito owner/admin membership because those roles control the entire server.
10. Migrate any legacy site running as `vito` before hosting additional workloads.
11. Apply the Nginx identity/symlink hardening from Finding 1 before allowing untrusted site code.
12. If different customers can deploy arbitrary code, place them on separate servers/VMs.

## Safe production verification checklist

Run these on the managed server and substitute real usernames/domains. They inspect state without exposing application
secrets.

### Identity and privilege

```bash
id vito
sudo -n -l -U vito
ps -eo user,group,pid,cmd | grep '[n]ginx'
getent passwd site_a site_b
getent group site_a site_b
```

Expected: isolated users are not sudoers; the control user is in their groups; record the actual Nginx worker identity.

### Filesystem boundary

```bash
namei -l /home/site_a/app.example.com/public
namei -l /home/site_b/api.example.com/public
sudo -u site_a test -r /home/site_b/api.example.com && echo UNEXPECTED_READABLE || echo direct-read-blocked
```

Use disposable marker files to test the Nginx symlink scenario from Finding 1; never point the test at `.env`, keys, or
real customer data. Remove the marker and symlink immediately after the check.

### Nginx and hostname routing

```bash
sudo nginx -t
sudo nginx -T | grep -E 'server_name|root |fastcgi_pass|proxy_pass'
curl -I --resolve app.example.com:80:127.0.0.1 http://app.example.com/
curl -I --resolve api.example.com:80:127.0.0.1 http://api.example.com/
```

Confirm each hostname appears once and reaches the intended root/socket/port.

### PHP-FPM and CLI

```bash
sudo grep -R -E '^(user|group|listen|listen.owner|listen.group|listen.mode|php_admin_value\[open_basedir\])' /etc/php/*/fpm/pool.d/
sudo ss -xlpn | grep php
sudo -iu site_a bash -lc 'whoami; type -a php; php -v; /usr/bin/php8.4 -v'
```

Confirm the web pool UID and socket match the site and that interactive/default CLI behavior is understood.

### Workers, cron, ports, and shared services

```bash
sudo supervisorctl status
sudo grep -R -E '^(directory|command|user|environment)=' /etc/supervisor/conf.d/
sudo -u site_a crontab -l
sudo ss -ltnp
sudo -u site_a redis-cli PING
```

The Redis command is only a harmless reachability check. If it succeeds without credentials, local Redis is not an
authentication boundary between sites.

## Final verdict

Vito's multiple-subdomain routing design works. Its unique-user model also gives real, useful Unix UID separation for
site processes. However, the accurate description is **shared-server isolation with per-user file/process boundaries**,
not full isolation.

Do not share an isolated username between sites that should distrust each other. Even with unique usernames, address the
Nginx/control-user cross-read design, legacy `vito` sites, shared Redis/data services, hostname/port collisions, and
resource limits before treating one server as a secure multi-customer hosting platform.
