# Vito — Server Optimization & Tuning

**Final implementation plan** · 2026-08-30
**Supersedes:** the exploratory revisions of this document
**Sources:** `g:\useful-scripts\360` (KB + scripts) · Vito 4.x codebase (branch `4.x`)

> **Build status.** Phases 0–6 are implemented on `feature/server-optimization`.
> Phase 7 (AI advisor) and phase 8 (polish) are not started. What was actually
> built, what differs from this design, and what still needs checking by hand live
> in [OPTIMIZATION_STATUS.md](OPTIMIZATION_STATUS.md) — read that for current
> state. This document remains the reasoning behind the decisions.
>
> Two parts of phase 2 landed differently. **PgBouncer was not built**: it is a
> separate service Vito does not manage, and tuning it would mean managing it
> first. The **history UI was not built** either — plans are stored and rollback
> works from the current plan, but there is no list of past plans to browse.

---

## Contents

1. [Summary](#1-summary)
2. [Why this is worth building](#2-why-this-is-worth-building)
3. [What the codebase already gives us](#3-what-the-codebase-already-gives-us)
4. [Architecture](#4-architecture)
5. [The KB ruleset — source of truth](#5-the-kb-ruleset--source-of-truth)
6. [Resource budget](#6-resource-budget)
7. [Per-site load class](#7-per-site-load-class)
8. [Execution: how commands reach the server](#8-execution-how-commands-reach-the-server)
9. [Data model](#9-data-model)
10. [Guardrails](#10-guardrails)
11. [PostgreSQL optimizer](#11-postgresql-optimizer)
12. [MySQL / MariaDB optimizer](#12-mysql--mariadb-optimizer)
13. [Remaining components](#13-remaining-components)
14. [User interface](#14-user-interface)
15. [AI advisor (Phase 7)](#15-ai-advisor-phase-7)
16. [Testing strategy](#16-testing-strategy)
17. [Delivery phases](#17-delivery-phases)
18. [Risks](#18-risks)
19. [Delivery vehicle: core, not plugin](#19-delivery-vehicle-core-not-plugin)
20. [Remaining decisions](#20-remaining-decisions)
21. [Appendix — 360 source map](#21-appendix--360-source-map)

---

## 1. Summary

**The problem.** Vito provisions servers well but never tunes them. A freshly built box
runs Debian defaults — `shared_buffers` at 128MB on a 16GB machine, `pm.max_children`
unrelated to available RAM. Users must either accept default performance or hand-tune
over SSH, which Vito then has no knowledge of.

**The solution.** A tuning subsystem that derives every value from the specific machine,
explains each recommendation, previews changes before applying them, and can reverse
anything it did.

**The approach.** Port the decision pipeline from the 360 toolkit — not just its numbers:

```
DETECT → BUDGET → COMPUTE → PROPOSE → BACKUP → VALIDATE → APPLY → VERIFY → ROLLBACK
```

**Scope decisions taken:**

| Decision | Choice |
| --- | --- |
| First component | **PostgreSQL**, then MySQL/MariaDB, then PHP-FPM, nginx, OS, Redis |
| AI | **Deferred to Phase 7.** Phases 0-6 contain none |
| Knowledge base | Extracted to **versioned YAML rulesets**, not embedded in code |
| Engine selection | Per **`Service` row** — Vito knows the engine and version |
| Multi-site RAM split | Per-site **load class** (`low`/`medium`/`high`), weights 1/3/6 |
| First release | **Read-only.** Analyse and explain; apply comes in Phase 2 |

**Estimated effort:** ~10 weeks to Phase 6 (full tuning, no AI); ~12 with the AI advisor.

---

## 2. Why this is worth building

Three arguments, in order of strength.

**It closes a real gap in the product.** Vito's own value proposition is "manage your
server without knowing Linux." Tuning is precisely where that promise currently breaks:
the panel installs PostgreSQL and then leaves the user to discover that its defaults
assume a 1GB machine from 2005.

**Nobody else does this well.** Forge, Ploi, RunCloud and CloudPanel all provision;
none derive tuning values from the machine, explain them, and offer rollback. This is a
genuine differentiator rather than catch-up work.

**The knowledge already exists and is proven.** The 360 toolkit is running in
production. This project is largely a port of validated logic into a safer, more
legible delivery mechanism — not original research. That materially lowers the risk.

**The honest counter-argument:** it is a large subsystem touching production databases.
That is why the first release writes nothing (§17), why the rollback manifest is built
before breadth (Phase 2), and why AI is deferred until the safety rails exist.

---

## 3. What the codebase already gives us

Verified by reading branch `4.x`:

| Capability | Where |
| --- | --- |
| Full per-server stack inventory | `Service` rows — type, version, `type_data`, `is_default` |
| Every site and its runtime | `Site` — `type`, `php_version`, `isolated_user_id`, workers |
| Rich metric history | `Metric` — load, memory, per-core CPU, `cpu_steal_percent`, swap, **`oom_kill_count`** |
| Config file paths per service | `RegisterServiceType::configPaths()`, version-templated |
| Remote exec with `set -e` + error detection | `app/Helpers/SSH.php:191` |
| Remote file read / write with owner | `OS.php:254`, `SSH.php:292` |
| Service reload / restart | `app/SSH/OS/Systemd.php` |
| **An existing probe pattern to copy** | `OS::resourceInfo()` — `OS.php:365` |
| Queued SSH jobs with uniqueness | `InstallJob` — `Queueable` + `UniqueQueue`, `ssh` queue |
| SSH test fake | `app/Support/Testing/SSHFake.php` |

Two findings worth calling out:

**`OS::resourceInfo()` is already the probe we need.** It runs one Blade script emitting
`key:value` lines and parses them with a regex. The optimization probe must extend this
exact pattern — same format, same parsing approach — not invent a parallel JSON one.

**`Metric.oom_kill_count` is already collected and unused.** It is the single clearest
signal that a box is over-provisioned. Surfacing it is nearly free and immediately
useful.

**The gap:** no plan/preview step, no backup manifest, **no rollback**.
`Actions/PHP/UpdatePHPIni` `sed`s a value into `php.ini` and restarts FPM; if that OOMs
the box, nothing records that Vito did it.

---

## 4. Architecture

Three layers. The first two are deterministic and ship first; the third is optional and
arrives last.

```
┌─ CONTEXT ───────────────────────────────────────────────────┐
│ DB facts (free) + one SSH probe → ServerFacts DTO           │
│ Deterministic · cacheable · rendered to Markdown for humans │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─ ENGINE ────────────────────────────────────────────────────┐
│ ResourceBudget + YAML rulesets → concrete proposals         │
│ Pure PHP · no SSH · no AI · fully unit-testable             │
│ Owns EVERY value that can be derived arithmetically         │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─ ADVISOR (Phase 7, optional) ───────────────────────────────┐
│ Selects/adjusts rules within declared bounds · explains ·   │
│ prioritises · flags conflicts the engine cannot see         │
│ Never invents values · never emits shell                    │
└─────────────────────────────────────────────────────────────┘
                            ↓
        PLAN → approve → BACKUP → VALIDATE → APPLY → VERIFY
                            ↓
                    ROLLBACK on regression
```

### Code layout

Mirrors the existing `app/Services/*` conventions.

```
app/Support/Optimization/
    ResourceBudget.php          port of 360 compute_budget(). Pure. Tested.
    RulesetLoader.php           reads + validates YAML rulesets
    FormulaEvaluator.php        sandboxed arithmetic (symfony/expression-language)
    ChangeWriter.php            THE single write path: backup → write → validate → reload

app/DTOs/
    ServerFacts.php  Budget.php  TuningProposal.php  TuningPlanResult.php

app/Optimizers/
    OptimizerInterface.php  AbstractOptimizer.php
    Database/PostgresOptimizer.php  Database/MysqlOptimizer.php
    PHP/FpmOptimizer.php  Webserver/NginxOptimizer.php
    OS/KernelOptimizer.php  Redis/RedisOptimizer.php

app/Models/          OptimizationPlan  OptimizationProposal  OptimizationChange
app/Enums/           SiteLoadClass  OptimizationPlanStatus  ProposalSeverity  ApplyMethod
app/Actions/Optimization/   Probe  GeneratePlan  ApplyPlan  VerifyPlan  RollbackPlan
app/Jobs/Optimization/      ProbeServerJob  ApplyPlanJob
app/Policies/               OptimizationPlanPolicy      (HasRolePolicies)
app/Http/Controllers/       ServerOptimizationController (RouteAttributes, named routes)
app/Http/Resources/         OptimizationPlanResource  OptimizationProposalResource

resources/optimization/rules/*.yaml       the KB, versioned
resources/views/ssh/optimization/*.blade.php
resources/js/pages/servers/optimization/*.tsx
```

**Conventions to follow** (from `CLAUDE.md`): models extend `AbstractModel`; enums
implement `VitoEnum` with `getColor()`/`getText()`; all logic in Actions with
`Validator::make()`; jobs use `Queueable` + `UniqueQueue` wrapped in `$this->run()`;
API Resources whitelist fields and call `->getText()`/`->getColor()` on enums;
policies use `HasRolePolicies`; dialogs via the registry and `useDialog()`.

**No new architecture-test exceptions.** If a rule fails, the code is wrong.

---

## 5. The KB ruleset — source of truth

The highest-value artefact in this project is not code. It is converting the KB from
**prose an engineer reads** into **data a program executes**.

Today that knowledge is split across `360/lib/common.sh` (formulas), `360/0*.sh`
(application), `360/CLAUDE.md` (guardrails) and `360/OPTIMIZATION_V2/*.md` (24
explanation documents). Ported straight into Blade templates it becomes unversioned,
untestable and impossible to explain in a UI.

### Format

One YAML file per component: `resources/optimization/rules/postgresql.yaml`

```yaml
component: postgresql
ruleset_version: 1
applies_to:
  service: postgresql
  versions: ['15', '16', '17', '18']

rules:
  - key: shared_buffers
    formula: "db_buffer_mb * 0.80"
    unit: MB
    bounds: { min: 128, max_expr: "total_ram_mb * 0.40" }
    apply: restart              # connections drop — NOT reload
    severity_if_default: high
    default_hint: "128MB"
    why: |
      PostgreSQL's own page cache. The packaged default of 128MB bears no
      relation to the machine's RAM and is the most common misconfiguration
      in this stack. Deliberately not the full DB reserve — PG also needs
      work_mem, maintenance_work_mem and per-connection memory from it.
    kb_ref: OPTIMIZATION_V2/14-DATABASE-AND-CONNECTION-POOLING.md

  - key: work_mem
    formula: "(total_ram_mb * 0.25) / max_connections"
    unit: MB
    bounds: { min: 4 }
    apply: reload
    why: |
      Allocated PER sort/hash operation, and one query may use several across
      many connections — so it MULTIPLIES by connection count. A 25%-of-RAM
      envelope divided by max_connections bounds the worst case. This is the
      most common cause of PostgreSQL OOM.
    kb_ref: OPTIMIZATION_V2/14-DATABASE-AND-CONNECTION-POOLING.md

guardrails:
  - id: pg-skip-when-remote
    when: "db_local == false"
    action: skip_component
    message: "Database is remote — its RAM is budgeted on that machine."
```

### Why this shape earns its keep

- **One source of truth.** Engine evaluates `formula`, UI renders `why`, AI later cites
  `kb_ref`. No drift between what is applied and what is explained.
- **Testable.** Pest asserts every rule against fixture hardware: *16GB, 4 cores, SSD,
  local DB → `shared_buffers = 3932MB`*. Formulas stop being folklore.
- **Versioned.** `ruleset_version` lets rules evolve without silently changing behaviour;
  each plan records the version that produced it.
- **Bounded.** `bounds` is what makes the AI safe later — it may adjust *within* a range
  it cannot exceed.
- **Blast radius is data.** `apply: restart|reload` drives the confirm dialog directly,
  so guardrail #7 is enforced structurally rather than by remembering.
- **Extensible.** A new component is a new YAML file, not new PHP.

### Formula evaluation

`symfony/expression-language` with a whitelisted variable set — arithmetic only, no
function calls, **never `eval`**. Variables come from `ServerFacts` + `Budget`. An
unknown variable is a load-time error, caught by the ruleset test, not at runtime on a
production box.

---

## 6. Resource budget

A direct port of `360/lib/common.sh :: compute_budget()` (L436-522) into pure PHP.
This is the single most important class in the system: **no SSH, no AI, no I/O** — a
`ServerFacts` in, a `Budget` out.

### The RAM pie

| Reserve | Rule |
| --- | --- |
| **OS** | 5% of RAM, floor 512MB — kernel, sshd, cron, monitoring |
| **Database** | 30% *if the DB is local*, else **0** |
| **Redis** | 20% *if Redis is local*, else 0 → becomes `maxmemory` |
| **Workers** | 12% if queue workers or websockets run here |
| **OPcache** | `(SHM + JIT) × number of PHP versions` |
| **FPM pool** | everything left, floor 256MB |

Two subtleties the 360 comments call out, both of which must survive the port:

- **OPcache is allocated once per PHP version**, outside per-worker RSS. A box with 8.3
  and 8.4 installed allocates twice. Counting it once over-commits RAM by ~1GB.
- **`AVG_WORKER_RSS_MB` must be measured, not guessed.** Guessing causes OOM or waste.
  The probe measures it; the default of 80MB applies only when no pool is running yet.

`MAX_WORKERS = pool ÷ measured RSS`, floor 2, then split across sites by load class (§7).

### 6.1 `db_local` — where the database runs

**Definition.** `db_local` is a single yes/no fact: *is the database running on this same
server, or on a different machine?*

```
db_local = true                       db_local = false
┌──────────────────────┐              ┌─────────────┐      ┌──────────────┐
│ ONE SERVER           │              │ APP SERVER  │      │ DB SERVER    │
│ nginx · PHP-FPM      │              │ nginx       │─────▶│ PostgreSQL   │
│ PostgreSQL ◀─ here   │              │ PHP-FPM     │ VPC  │ (RDS or a    │
│ Redis                │              │ Redis       │      │  separate    │
└──────────────────────┘              └─────────────┘      │  droplet)    │
                                                            └──────────────┘
```

**Why it matters.** It decides who gets ~30% of the machine. On a 16GB box:

| Reserve | `db_local = true` | `db_local = false` |
| --- | --- | --- |
| Database | **4.8GB** | **0** |
| PHP-FPM pool | ~5.3GB | **~10.1GB** |

Getting it backwards fails in one of two ways:

- **Wrongly `true`** → ~4.8GB reserved for a database that is not there. PHP-FPM is sized
  far below what the box supports. No error; just permanent, invisible under-capacity.
- **Wrongly `false`** → PHP-FPM is sized as if it owns memory PostgreSQL is already
  using. Both compete, the box swaps, then something is OOM-killed.

It also gates guardrail #10: never tune `postgresql.conf` on a server whose database
lives elsewhere.

**Detection (resolved).** Infer it from the `Service` rows Vito already owns:

```
a database-type Service exists on this server  →  db_local = true   (reserve 30%)
no database service                            →  db_local = false  (reserve 0)
```

Vito provisioned the box, so it cannot miss a database it installed. This is correct for
effectively every real server.

**One override, for one case.** The inference misreads a server where a database service
exists but the application actually points at an external host — a leftover from testing,
an old migration, or an install that was never used. Vito would reserve 30% for a
database serving zero queries.

So the value is shown in server settings and can be overridden:

```
Database location:   ● Local (detected — PostgreSQL 17 installed)
                     ○ Remote
```

Default follows the inference; the override exists only to catch the otherwise-silent
case. Stored on the server record and included in `ServerFacts`.

### Testability

This is why the class is pure. A Pest dataset asserts the whole pie:

```php
dataset('budgets', [
    '8GB · remote DB · local Redis · 1 PHP' => [
        'facts' => ['ram' => 8192, 'db_local' => false, 'redis_local' => true, 'php' => ['8.4']],
        'expect' => ['db' => 0, 'redis' => 1638, 'workers' => /* … */],
    ],
    // …
]);
```

Every number in the product traces back to a test like this.

---

## 7. Per-site load class

Vito is multi-site; 360 assumes one primary app. An even split is wrong the moment a
busy Laravel API shares a box with three brochure WordPress sites — and asking users for
a percentage is a question they cannot answer accurately. Three named classes are
answerable from knowledge they actually have.

### The enum

Follows `app/Enums/HostedDomainType.php` exactly.

```php
enum SiteLoadClass: string implements VitoEnum
{
    use HasEnumHelpers;

    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public function weight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 3,
            self::HIGH => 6,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LOW => 'default',
            self::MEDIUM => 'info',
            self::HIGH => 'warning',
        };
    }

    public function getText(): string
    {
        return $this->value;
    }
}
```

Stored as `sites.load_class`, cast to the enum, added to `$fillable`, **default
`MEDIUM`** — existing sites keep today's even-split behaviour until someone opts in.

### Weights 1 / 3 / 6 — deliberately non-linear

A high-traffic app does not need 3× a brochure site; it needs closer to an order of
magnitude, because the brochure site is served mostly from nginx/static while the API
spends real time in PHP.

**8GB box · FPM pool 4096MB · measured RSS 80MB → 51 workers**, hosting 1 high +
1 medium + 2 low:

| Site | Class | Weight | Share | `pm.max_children` |
| --- | --- | --- | --- | --- |
| api.example.com | high | 6 | 6/11 | **27** |
| app.example.com | medium | 3 | 3/11 | **13** |
| blog.example.com | low | 1 | 1/11 | **4** |
| docs.example.com | low | 1 | 1/11 | **4** |

An even split gives all four 12 — starving the API while holding ~24 workers of RAM for
two mostly-static sites.

### Rules the engine enforces

- **Floor of 2 children per site**, funded from the pool *before* weighting — otherwise
  one `high` site on a small box rounds every other site to zero.
- **Sites with no FPM pool are excluded** (static, Node/reverse-proxied) — detect via
  `Site.type`. They hold no PHP workers and take no slice.
- **`low` sites are candidates for `pm = ondemand`**, which returns idle RAM to the box
  instead of holding it. On a multi-site server this is plausibly the largest single win
  available, and it falls straight out of having a load class.
- **`pm.max_children` is a ceiling, not a reservation.** The budget's job is to keep the
  *worst case* inside RAM.

### It also derives concurrency class

360's Postgres and PgBouncer formulas branch on a server-wide `CONCURRENCY_CLASS`.
Rather than asking a second near-duplicate question, derive it:

```
any site high → high     all sites low → low     otherwise → medium
```

Presented as a derived value the user may override.

### UI copy

| Class | Shown to the user |
| --- | --- |
| **Low** | Brochure, docs or a low-traffic blog. Mostly cached or static |
| **Medium** | A normal application with steady traffic *(default)* |
| **High** | The primary app on this server — API, dashboard, or heavy traffic |

---

## 8. Execution: how commands reach the server

No AI is involved. Vito already has every primitive, and per project standards
**no `exec()`, `shell_exec()`, `system()`, `passthru()` or Symfony `Process`** — all
SSH goes through the `SSH` facade.

### 8.1 Primitives in use

| Need | Primitive |
| --- | --- |
| Run a Blade script | `$server->ssh()->exec($view, 'log-name')` |
| Write a remote file with owner | `$server->ssh()->write($path, $content, 'root')` |
| Read a remote file | `$server->os()->readFile($path)` |
| Reload / restart a unit | `$server->systemd()->reload()` / `->restart()` |
| Pass values safely | `->variables([...])` — `escapeshellarg`'d |
| Persist output for the UI | `->setLog($serverLog)` |

Two behaviours of `exec()` matter and are already correct:

1. **`set -e` is prefixed to every command** (`SSH.php:209`) — a failing step aborts
   rather than silently continuing.
2. **Failure is detected two ways** (`SSH.php:235`): non-zero exit status **or** the
   string `VITO_SSH_ERROR` in output. Optimization scripts follow the existing
   convention: `... || { echo 'VITO_SSH_ERROR'; exit 1; }`.

### 8.2 The probe

**Extends `OS::resourceInfo()`** (`OS.php:365`) rather than inventing a parallel
mechanism: one Blade script, `key:value` lines, one regex parse, one round-trip.
Vito is remote — unlike 360, which runs on the box — so round-trips are the cost to
minimise.

Additional keys the optimizer needs beyond `resource-info`:

```
disk_rotational, virtualisation, php_versions, fpm_avg_rss_mb, fpm_active_children,
pg_shared_buffers, pg_work_mem, pg_max_connections, mysql_innodb_buffer_pool,
nginx_worker_processes, redis_maxmemory, redis_maxmemory_policy, nofile_limit
```

### 8.3 The write path — one class, no exceptions

`ChangeWriter` is the **only** code that mutates a remote config. Every change:

1. **Read** current content (`os()->readFile`)
2. **Hash** it; abort if it differs from the hash recorded at plan time — someone
   hand-edited the file since (drift)
3. **Back up** content + hash into `optimization_changes` — *nothing is written before
   this succeeds*
4. **Write** the new content
5. **Validate** with the component's checker
6. **On validation failure → restore the backup immediately and throw.** A broken config
   is never activated
7. **Reload**, or restart only where the ruleset says `apply: restart`
8. **Verify** the value took effect and the service still serves

### 8.4 Drop-in files, not in-place `sed`

A deliberate improvement on 360. Rather than editing vendor configs:

| Component | Managed file |
| --- | --- |
| PostgreSQL | `/etc/postgresql/{v}/main/conf.d/zz-vito-tuning.conf` |
| MySQL | `/etc/mysql/mysql.conf.d/zz-vito-tuning.cnf` |
| PHP-FPM | `/etc/php/{v}/fpm/conf.d/zz-vito-tuning.ini` |
| nginx | `/etc/nginx/conf.d/zz-vito-tuning.conf` |
| sysctl | `/etc/sysctl.d/60-vito-tuning.conf` |

Rollback becomes a file delete, diffs stay readable, and vendor package upgrades never
conflict. Where a setting cannot live in a drop-in (`pm.*` in a pool file), edit the
real file — with a backup.

### 8.5 Validators

| Component | Command |
| --- | --- |
| PostgreSQL | `postgres -C shared_buffers -D <datadir>` |
| MySQL | `mysqld --validate-config` |
| nginx | `nginx -t` |
| PHP-FPM | `php-fpm{v} -t` |
| sysctl | `sysctl -p --dry-run` (or load into a test namespace) |
| Redis | parse-check, then `CONFIG SET` for runtime-settable keys |

### 8.6 Where it runs

A queued job — `ShouldQueue` + `Queueable` + `UniqueQueue`, wrapped in
`$this->run($key, …)`, on the `ssh` queue, matching `InstallJob`. Never in the HTTP
request: SSH is slow, and `UniqueQueue` prevents two tuning runs racing on one server.
`failed(Exception $e)` marks the plan failed and triggers rollback.

### 8.7 Rejected: shipping the 360 bash scripts

Uploading 360 and scraping `run.sh --plan` would preserve the logic cheaply. Rejected
because it means a second, opaque source of truth: output must be screen-scraped,
failures are hard to attribute, backups live outside Vito's database (so the UI cannot
offer rollback), and `/etc/corbital-360/state.json` duplicates what `optimization_plans`
should own. **The formulas port; the bash does not.**

---

## 9. Data model

```
sites
    + load_class            string, default 'medium', cast SiteLoadClass

optimization_plans
    id, server_id, service_id (nullable), status (enum), source (engine|ai|hybrid)
    ruleset_versions (json), facts (json), budget (json)
    created_by, applied_at, rolled_back_at, timestamps

optimization_proposals
    id, plan_id, component, config_key
    current_value, proposed_value, unit
    severity (enum), apply_method (enum: reload|restart)
    rationale, kb_ref, source (engine|ai), confidence (nullable)
    status (pending|accepted|rejected|applied|skipped)

optimization_changes          ← THE ROLLBACK MANIFEST
    id, plan_id, target_path, action (created|modified)
    backup_content (encrypted), backup_hash
    applied_at, reverted_at
```

`optimization_changes` is what turns this from "a panel that writes configs" into "a
panel you can trust with production."

**Enums** (all implement `VitoEnum`): `SiteLoadClass`, `OptimizationPlanStatus`,
`ProposalSeverity`, `ApplyMethod`.

**Retention:** plans are small; keep them. Prune `backup_content` on plans older than
90 days that were never rolled back, keeping the metadata.

---

## 10. Guardrails

Hard-won lessons from `360/CLAUDE.md`. A UI makes them easy to violate by accident, so
each becomes **code**, not documentation.

| # | Rule | Enforcement |
| --- | --- | --- |
| 1 | Redis cache (`allkeys-lru`) and queue (`noeviction` + AOF) stay separate | Engine refuses an eviction policy on a queue instance |
| 2 | Page cache never on authenticated routes — `fastcgi_hide_header Set-Cookie` in the main PHP location destroys the session the CSRF token belongs to → **419 on every POST** | Guest-location only; not expressible in the UI for the main location |
| 3 | `opcache.validate_timestamps=0` requires a deploy-time FPM reload | Gated on Vito's deploy hook reloading FPM |
| 4 | Never lower nginx `error_log` below `error` — loses the messages that explain 502s | Validator rejects |
| 5 | HSTS off unless explicitly requested — irreversible for the `max-age` | Opt-in + irreversibility warning |
| 6 | App FPM workers never run as `www-data` | Verify checks socket ownership |
| 7 | Never restart without stating blast radius | `apply: restart` drives a confirm dialog naming what drops |
| 8 | Validate before activating | Mandatory step in `ChangeWriter` |
| 9 | Never present an unmeasured change as an optimization | `UNVERIFIED` badge |
| 10 | DB tuning only when the DB is local | `db_local == false` → skip component |
| 11 | `innodb_flush_log_at_trx_commit=2` is a durability trade-off, not an optimization | Opt-in, risk stated plainly |

Implemented as a `GuardrailValidator` run over every proposal — engine-generated or, in
Phase 7, AI-generated — **before it is shown to the user**. A violating proposal is
dropped and logged, never rendered for approval.

---

## 11. PostgreSQL optimizer

First component (Phases 1-2). Ported from `360/07-database.sh` L195-250.

### Values

| Key | Formula | Apply | Note |
| --- | --- | --- | --- |
| `shared_buffers` | `db_buffer_mb * 0.80` | **restart** | PG page cache. Not the full reserve — PG also needs work_mem/maintenance/connection RAM |
| `effective_cache_size` | `total_ram_mb * 0.55` | reload | **Not an allocation** — a planner hint. Wrong values cause seq-scans, not OOM |
| `max_connections` | `100`, or `200` if concurrency high | restart | Keep modest behind PgBouncer |
| `work_mem` | `(total_ram_mb * 0.25) / max_connections`, floor 4MB | reload | **Per sort/hash op**; multiplies by connections. The classic OOM source |
| `maintenance_work_mem` | `256MB` | reload | |
| `wal_buffers` | `64MB` | restart | |
| `checkpoint_completion_target` | `0.9` | reload | |
| `default_statistics_target` | `100` | reload | |
| `random_page_cost` | `1.1` SSD/NVMe · `4.0` HDD | reload | From probed `disk_rotational` |
| `effective_io_concurrency` | `200` SSD/NVMe · `2` HDD | reload | |

Validation: `postgres -C shared_buffers -D <datadir>` before reload.

### PgBouncer

From `360/13-pgbouncer.sh`, transaction pooling:

| Key | Formula |
| --- | --- |
| `pool_mode` | `transaction` |
| `default_pool_size` | low `cores×2` (min 8) · medium `cores×3` (min 12) · high `cores×5` (max 50) |
| `max_client_conn` | `pool_size × 50`, min 500 |
| `reserve_pool_size` | `pool_size / 4`, min 2 |

> **Laravel caveat.** Transaction pooling breaks session-level state — prepared
> statements, `LISTEN/NOTIFY`, advisory locks. Laravel needs PDO emulated prepares.
> Vito must **warn** when proposing PgBouncer, not silently apply it.

### Engine selection

Rules are chosen from `Service.name` + `Service.version` — Vito knows exactly which
engine and major version is installed, so no detection guesswork.

**Mixed-engine guard:** a server *can* hold both MySQL and PostgreSQL —
`Actions/Service/Install.php` does not forbid it. Rule *selection* is unaffected, but the
same `db_buffer_mb` reserve cannot be handed to both engines. When two database services
exist: tune the `is_default` one and surface a notice about the other.

---

## 12. MySQL / MariaDB optimizer

Phase 3, same pipeline.

### On the KB warning

`360/CLAUDE.md` calls its MySQL branch "unmaintained with known defects." Having read
`07-database.sh` L57-160, that needs qualifying: the branch is substantially complete
and its values are sound — buffer pool from the budget, `O_DIRECT`, pool instances,
slow-query log, systemd FD override — with real reasoning in its comments.

The honest reading is **not battle-tested by you** (your production stack is Postgres)
rather than known-wrong. That is a testing gap, and it is closed by porting it into a
system that previews every change and can roll it back.

### Values

| Key | Value |
| --- | --- |
| `innodb_buffer_pool_size` | from `db_buffer_mb` — the `shared_buffers` equivalent |
| `innodb_buffer_pool_instances` | derived from pool size |
| `innodb_flush_method` | `O_DIRECT` — avoids double-caching against the OS page cache |
| `innodb_flush_log_at_trx_commit` | `2` — **opt-in only** (see below) |
| `innodb_io_capacity` / `_max` | `2000` / `4000` (SSD) |
| `innodb_log_file_size` | `512M` |
| `max_connections` / `thread_cache_size` / `wait_timeout` | derived / `32` / `60` |
| `table_open_cache` / `table_definition_cache` | `4000` / `2000` |
| `slow_query_log` + `long_query_time` | `1`, `2` |
| `bind-address` / `skip-name-resolve` | `127.0.0.1` / `ON` |

### Two things to fix rather than copy

1. **360 `mset` appends under `[mysqld]` via `sed`** — fragile against a multi-section
   `my.cnf`. Use the managed drop-in (§8.4) instead.
2. **360 restarts MySQL unconditionally.** Here it is `apply: restart` with an explicit
   confirm stating the blast radius.

`innodb_flush_log_at_trx_commit=2` trades up to one second of data loss on power failure
for ~5-10× write throughput. That is a **durability decision, not an optimization** —
opt-in, with the risk stated plainly. A user running payments should say no.

### Variants: MariaDB now, Percona later

**Percona Server is not currently an option — Vito does not support it.** A grep across
`app/`, `config/`, `resources/` and `database/` returns zero references;
`ServiceTypeServiceProvider` registers only **MySQL**, **MariaDB** and **PostgreSQL**.

So Phase 3 targets **MySQL and MariaDB**, and Percona is added later *if* Vito gains a
Percona service. That ordering is not a compromise — it is the only thing available.

The ruleset is built to absorb it without rework. All three share the InnoDB core and
differ in a small, known way:

```yaml
variants:
  mysql:    { thread_pool: false }
  mariadb:  { thread_pool: true }
  percona:  { thread_pool: true }     # inert until a Percona service exists
```

```yaml
  - key: thread_handling
    value: pool-of-threads
    when: "variant.thread_pool == true"
    apply: restart
  - key: thread_pool_size
    formula: "min(cores, 8)"
    when: "variant.thread_pool == true"
    apply: restart
```

This mirrors what 360 already does at `07-database.sh:143` —
`mysql --version | grep -qiE 'percona|mariadb'` gates exactly these two keys — so the
variant model is validated by the existing script rather than invented here.

**Adding Percona later is then two steps**, neither touching the optimizer:

1. Register a Percona service in Vito (the larger piece — install scripts, versions,
   config paths; independent of this project).
2. Add `percona` to `applies_to.service` in the ruleset. The `variant` entry already
   exists.

**What Percona would add beyond MySQL**, when it arrives: the thread pool above (its main
scaling advantage under high connection counts), plus `userstat` and a richer slow-query
log for diagnostics. Worth having, not worth blocking Phase 3 for.

---

## 13. Remaining components

Phases 4-5, same pipeline throughout.

**PHP-FPM + OPcache** (`360/05-php-fpm.sh`) — `pm.max_children` from the budget split by
load class, `pm` mode (`ondemand` for low-traffic sites), `pm.max_requests`, OPcache SHM
and JIT buffer sized once per version, `realpath_cache`, `memory_limit`. Per-pool, per
PHP version.

**nginx** (`360/04-nginx.sh`) — `worker_processes` (= cores), `worker_connections` from
the FD tier, `keepalive_timeout`, gzip, buffer sizes, `open_file_cache`. Guardrails #2
and #4 apply.

**OS / kernel** (`360/02-kernel.sh`, `03-file-descriptors.sh`) — sysctl network and VM
tuning, `somaxconn`, TIME-WAIT handling, BBR where available, swappiness, file-descriptor
limits with matching systemd `LimitNOFILE` overrides. **Detect LXC** — many sysctls are
not settable in a container, and attempting them produces confusing failures.

**Redis / Valkey** (`360/06-redis.sh`) — `maxmemory` from the budget, and the critical
cache/queue split: `allkeys-lru` for cache, `noeviction` + AOF for queues. Guardrail #1
makes an eviction policy on a queue instance impossible.

---

## 14. User interface

Three surfaces under a new **Optimization** tab on the server page.

**Analysis.** The budget as a stacked bar (OS · DB · Redis · workers · OPcache · FPM
pool) with derived figures beside it. Below, proposals grouped by component, each a row:
`current → proposed`, severity chip, apply method, and an expandable *Why* drawn from the
ruleset. Signals surfaced prominently: OOM kills in the last 7 days, swap pressure,
`cpu_steal_percent`, reboot-required.

**Plan and apply** (Phase 2+). Select proposals → review a summary naming every file that
changes and every service that reloads or restarts → confirm via `dialog.confirm` stating
blast radius → progress streamed from the job → result.

**History and rollback** (Phase 2+). Past plans with status, what changed, and a per-plan
Revert. Drift warnings where a managed file was edited outside Vito.

Follows existing conventions: Inertia pages under `resources/js/pages/servers/`,
`useForm`, dialogs via the registry and `useDialog()`, Shadcn components with semantic
tokens, complete `useEffect` dependency arrays, types in `resources/js/types/*.d.ts`
kept in sync with the API Resources.

---

## 15. AI advisor (Phase 7)

Deferring AI does not weaken the architecture — it validates it. Layers 1-2 were always
meant to stand alone, and the YAML ruleset is what makes Layer 3 cheap to add later.

**The constraint, fixed from day one:**

> The AI **selects, adapts and explains** rules from the KB.
> It does not invent config values, and it never emits shell.

`pm.max_children` is arithmetic: `(RAM − reserves) / measured RSS`. An LLM doing that is
strictly worse than PHP doing it — non-deterministic, unauditable, untestable, and
occasionally confidently wrong on a production box.

### What it receives

1. The **server context** — DB facts + probe, rendered Markdown
2. The **ruleset** — the same YAML the engine uses
3. The **engine proposals** already computed

### What it may return (structured JSON only)

- **Select** which rules apply to this server
- **Adjust** a value *within the rule declared `bounds`*
- **Flag conflicts** the engine cannot see — *"high iowait; adding FPM children will make
  this worse"*
- **Prioritise** twelve possible changes down to the three that matter
- **Explain** in prose grounded in `kb_ref`
- **Propose a new rule** for an unknown component (MongoDB, Meilisearch, ClickHouse) —
  landing as a *suggestion for a human to add to the ruleset*, never a direct write

Everything then flows through the **same** `GuardrailValidator` → plan → approve → backup
→ validate → apply → verify → rollback pipeline. **The AI has no privileged path to the
server.** Delete Layer 3 and Layers 1-2 still work.

### Provider and privacy

`prism-php/prism` — Laravel-native, multi-provider (Anthropic / OpenAI / Ollama /
Gemini), first-class structured output. Vito is self-hosted, so provider choice belongs
to the user, including local Ollama for those who cannot send infrastructure detail to a
third party. Off by default.

**Context is built by allowlist, never a dump.** Never included: `Server.authentication`
(encrypted SSH credentials), `Service.secret`, DB passwords, `.env` contents, private
keys. Site env var *keys* only, never values — the pattern `Site.env_variables` already
establishes. A `ContextRedactionTest` asserts no known-secret field can appear in output,
and the exact payload is shown to the user before first send.

### Cost

One analysis ≈ 8-15k input / 1-2k output tokens ≈ **$0.05-0.15** on a frontier model.
Cache on the context hash; re-analyse only on change or explicit request.

---

## 16. Testing strategy

Pest 5, per project standards. `SSH::fake()` for all SSH; no real connections.

| Layer | Test |
| --- | --- |
| `ResourceBudget` | Dataset over fixture hardware asserting the full RAM pie. **The single most important test in the system** |
| Ruleset loading | Every YAML parses; every `formula` variable resolves; every `kb_ref` exists |
| Formula evaluation | Arithmetic correctness + rejection of anything outside the whitelist |
| Load-class split | Weighted division, the floor-of-2 rule, exclusion of non-FPM sites |
| Optimizers | Fixture facts → expected proposals, per component and per version |
| Guardrails | Each of the 11 rules has a test proving it **rejects** a violating proposal |
| `ChangeWriter` | Backup precedes write; validation failure restores and throws; drift aborts |
| Rollback | Manifest replay restores original content |
| Redaction | No secret field reaches rendered context |
| Policies | Read/write/owner access enforced; API keys respect project scope |
| Architecture | Existing `tests/Arch/` rules pass with **no new `EXCEPTIONS` entries** |

Two properties worth stating explicitly:

- **The budget engine has no I/O**, so its tests are fast, deterministic, and readable as
  a specification of the product.
- **Every guardrail must be proven to fail** — a guardrail with only a passing test is
  not evidence of anything.

---

## 17. Delivery phases

| Phase | Scope | Est. |
| --- | --- | --- |
| **0 · Foundations** | `ServerFacts`, probe blade (extending `resource-info`), `ResourceBudget` + dataset tests, ruleset loader + evaluator, `postgresql.yaml`, `SiteLoadClass` enum + migration. **No UI, no writes** | 1.5w |
| **1 · Insight (MVP)** | Context builder, `PostgresOptimizer` (read-only), Optimization tab with budget bar + proposals + Why, load-class selector, redaction test. **Writes nothing** | 1.5w |
| **2 · Apply + Rollback** | `ChangeWriter`, drop-in files, validators, `optimization_changes` manifest, apply/verify/rollback for **PostgreSQL + PgBouncer**, history UI, confirm dialogs | 1.5w |
| **3 · MySQL / MariaDB** | `mysql.yaml` + `mariadb` variant, `MysqlOptimizer`, same pipeline | 1w |
| **4 · PHP-FPM** | `php-fpm.yaml`, per-pool sizing by load class, OPcache, `ondemand` for low sites | 1w |
| **5 · nginx · OS · Redis** | Three rulesets + optimizers; LXC detection; Redis cache/queue split | 1.5w |
| **6 · Verify + Drift** | Full PASS/WARN/FAIL report, drift detection, scheduled re-check job | 1w |
| | *Built without the scheduled re-check — verification runs after an apply and on request, not on a timer. Neither result is shown in the UI yet.* | |
| **7 · AI advisor** | Prism integration, structured output, guardrail validation, provider settings | 2w |
| **8 · Polish** | Page cache (guest-only), API endpoints + OpenAPI, advisor chat | 1.5w |

**~10 weeks to Phase 6** (complete tuning, no AI). Each phase ships something usable.

### Why read-only first

A tab that says *"`shared_buffers` is 128MB; this 16GB box should use ~3.9GB — and you
have 4 OOM kills in the last 7 days"* is valuable **before** Vito can fix it, carries
zero risk, and validates the budget engine and rulesets against real hardware while they
are still just data.

### Why PostgreSQL first

Highest variance, lowest risk. `shared_buffers` at the packaged default on a large box is
the most common and most costly misconfiguration in this stack; the formulas are
well-established; changes are validated and reversible. It also forces the
manifest/rollback machinery to exist early, which everything later depends on.

---

## 18. Risks

| Risk | Mitigation |
| --- | --- |
| **A bad value takes a production DB down** | Read-only first release; validate before activate; restore-on-validation-failure; rollback manifest; `restart` always confirmed with blast radius |
| **Restart disruption feels routine in a UI** | `apply: restart` is data-driven and always raises a confirm naming what drops |
| **Config drift between plan and apply** | Hash compared before write; abort on mismatch |
| **Formulas wrong on hardware you do not have** | Datasets over varied fixtures; read-only phase validates against real servers before any write |
| **Mixed DB engines share one RAM reserve** | Detect; tune `is_default` only; warn about the other |
| **LXC cannot set many sysctls** | Detect virtualisation in the probe; skip and explain |
| **Scope creep into a monitoring product** | Metrics are *inputs* to tuning, not a new dashboard |
| **AI over-trust (Phase 7)** | Bounds, guardrails, confidence badges, nothing auto-applies |
| **Secrets leaving the box (Phase 7)** | Allowlist context, redaction test, payload preview, optional local model |

The largest genuine risk is the first one, and the entire phase ordering exists to
address it.

---

## 19. Delivery vehicle: core, not plugin

**Decision: build in core, on a long-lived fork of `4.x`, structured so it could be
upstreamed.**

This was the last open question. It is settled by a hard technical limit, not by
preference.

### The concern was right

The instinct that a plugin "may not provide all features as core" is correct, and the
advantage named — *being able to accept future Vito updates* — is real and valuable. If
plugins could carry this feature, that would be the better answer.

They cannot.

### What Vito plugins can register

`app/Plugins/` provides fourteen registration classes:

```
RegisterCommand            RegisterServerProvider      RegisterSiteType
RegisterDNSProvider        RegisterServiceType         RegisterSourceControl
RegisterNotificationChannel RegisterSiteFeature        RegisterStorageProvider
RegisterServerFeature      RegisterSiteFeatureAction   RegisterViews
RegisterServerFeatureAction                            RegisterWorkflowAction
```

A plugin can add a provider, a service type, a server/site feature, a command, a
workflow action, and **Blade** views (`RegisterViews` → `loadViewsFrom`).

### The blocker: plugins cannot ship Inertia pages

`resources/js/app.tsx` resolves pages with a **build-time glob**:

```ts
createInertiaApp({
  pages: './pages',
  …
});
```

Vite compiles `resources/js/pages/**` into the bundle at build time. `vite.config.ts`
has a single fixed input (`resources/js/app.tsx`) and no plugin directory. There is no
runtime page registry, and `RegisterViews` handles **Blade only** — it does nothing for
React.

A plugin therefore **cannot add a React page, a tab, or a component** without the user
rebuilding the frontend from modified sources — at which point it is a fork with extra
steps.

This feature is substantially a UI: the budget visualisation, the proposal list with
current→proposed and rationale, the apply flow with confirm dialogs, plan history and
rollback (§14). None of it can ship as a plugin.

### The other gaps

| Requirement | Plugin support |
| --- | --- |
| React pages / tabs (§14) | **None** — build-time glob |
| Migrations for 3 tables + `sites.load_class` (§9) | No hook; `install()` is free-form, so a plugin would run migrations by side effect |
| Models, Policies, API Resources | No registration path |
| Modifying `Site` (adding `load_class`) | Core model — cannot extend from a plugin |
| Controllers + named routes | No route registration class |
| YAML rulesets (§5) | Possible via file paths |
| SSH Blade templates (§8) | Possible via `RegisterViews` |
| Optimizers as service types | Awkward — they are not services |

Roughly a third of the feature could be expressed as a plugin. The rest — data model,
UI, routing — cannot.

### What this costs, stated honestly

Choosing core means **merge maintenance**: every upstream Vito release must be merged
into the fork, and conflicts resolved. That is the price of the decision, and it is a
real, recurring cost.

Three things keep it small:

1. **The subsystem is almost entirely additive.** New directories
   (`app/Optimizers/`, `app/Support/Optimization/`, `app/Actions/Optimization/`,
   `resources/optimization/`, `resources/js/pages/servers/optimization/`) that upstream
   never touches.
2. **Only three touch-points modify existing files:**
   - `sites` migration + `Site` model — one column, one cast, one `$fillable` entry
   - the server page tab list — one entry
   - route registration — one controller, self-contained via `RouteAttributes`
3. **Following project conventions is what makes merges cheap.** Code that looks like
   the surrounding code conflicts far less. This is the practical reason the plan insists
   on `AbstractModel`, `VitoEnum`, Actions-with-`Validator`, `HasRolePolicies` and the
   dialog registry rather than inventing local patterns.

### Recommended posture

Build it as a **feature branch off `4.x`, merged regularly from upstream**, and keep the
code upstreamable. Then either outcome works:

- **Upstreamed** — merge cost disappears entirely; the feature ships to all Vito users.
- **Kept private** — a well-isolated fork with a small, predictable merge surface.

Both are served by the same discipline: additive directories, minimal edits to existing
files, no new architecture-test exceptions.

### If a plugin is still wanted later

The path exists but is upstream work, not this project: Vito would need a runtime page
registry (a plugin `pages/` directory globbed at build time, or dynamic imports), plus
registration classes for migrations, models, policies and routes. Worth proposing to
upstream on its own merits — but it is a prerequisite, not a workaround, and this feature
cannot wait on it.

---

## 20. Remaining decisions

**All scope decisions are resolved. Phase 0 can start.**

| Decision | Outcome | § |
| --- | --- | --- |
| Component order | PostgreSQL → MySQL/MariaDB → PHP-FPM → nginx/OS/Redis | 17 |
| AI timing | Deferred to Phase 7; phases 0-6 contain none | 15 |
| Knowledge base | Versioned YAML rulesets, not code | 5 |
| Engine selection | Per `Service` row — name + version | 11 |
| Multi-site RAM split | Per-site load class, weights 1/3/6 | 7 |
| Concurrency class | Derived from load classes, overridable | 7 |
| `db_local` | Inferred from DB service presence, overridable | 6.1 |
| Engine variants | MySQL + MariaDB now; Percona entry inert until supported | 12 |
| Delivery vehicle | **Core, on an upstreamable fork** — plugins cannot ship React pages | 19 |
| First release | Read-only; apply lands in Phase 2 | 17 |

### Deliberately out of scope

- **Percona Server tuning** — blocked on Vito having a Percona service at all (§12). The
  ruleset carries the variant entry so it costs one line when that lands.
- **Adding Percona support to Vito** — a separate piece of work (install scripts,
  versions, config paths), not part of this project.

---

## 21. Appendix — 360 source map

| Vito component | 360 source |
| --- | --- |
| `ResourceBudget` | `lib/common.sh :: compute_budget()` L436-522 |
| Probe | `00-detect.sh` (+ Vito `OS::resourceInfo()`) |
| `KernelOptimizer` | `02-kernel.sh`, `03-file-descriptors.sh` |
| `NginxOptimizer` | `04-nginx.sh`, `OPTIMIZATION_V2/05-*` |
| `FpmOptimizer` | `05-php-fpm.sh`, `OPTIMIZATION_V2/03-*` |
| `RedisOptimizer` | `06-redis.sh`, `OPTIMIZATION_V2/04-*` |
| `PostgresOptimizer` | `07-database.sh` L195-250, `13-pgbouncer.sh`, `OPTIMIZATION_V2/14-*` |
| `MysqlOptimizer` | `07-database.sh` L57-160 |
| Verify report | `10-verify.sh` |
| `ChangeWriter` + rollback | `lib/common.sh :: backup_file`, `rollback.sh` |
| Guardrails | `CLAUDE.md` §2 |
| `why` / `kb_ref` prose | `OPTIMIZATION_V2/*.md` (24 documents) |
| Intent questions | `setup.sh` → `config/server.env` |
