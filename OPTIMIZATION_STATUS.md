# Server Optimization — Build Status

**Branch:** `feature/server-optimization` (pushed to `origin`, your fork)
**Plan:** [OPTIMIZATION_PLAN.md](OPTIMIZATION_PLAN.md)
**Updated:** 2026-08-30

---

## Where it stands

| Phase | Scope | Status |
| --- | --- | --- |
| **0** | Budget engine, rule format, probe | **Done** |
| **1** | Read-only analysis, UI, per-site FPM sizing | **Done** (untested in a browser) |
| **2** | Apply + rollback (PostgreSQL) | **Done** (never run against a real server) |
| **3** | MySQL / MariaDB | Not started |
| **4** | PHP-FPM apply | **Done** (never run against a real server) |
| **5** | nginx · OS · Redis | **Done** (never run against a real server) |
| **6** | Verify + drift detection | Not started |
| **7** | AI advisor | Not started |

**Analysis still modifies nothing** — a test asserts it issues no `ALTER SYSTEM` and no
`systemctl restart`. Applying a plan does write, through a single path that records the
original first and restores it if the service rejects the result.

---

## What is built

### The engine

| Piece | File | What it does |
| --- | --- | --- |
| Budget | `app/Support/Optimization/ResourceBudget.php` | Divides RAM into reserves; derives the worker ceiling |
| Rules | `app/Support/Optimization/RulesetLoader.php`, `Ruleset.php` | Loads and validates YAML rulesets |
| Formulas | `app/Support/Optimization/FormulaEvaluator.php` | Sandboxed arithmetic — no `eval` |
| Versions | `app/Support/Optimization/ServiceVersion.php` | Prefix matching and comparison across engines |
| Pool split | `app/Support/Optimization/PoolAllocator.php` | Divides the FPM pool by per-site load class |

### Reading a server

| Piece | File |
| --- | --- |
| Probe (one SSH round trip) | `app/Actions/Optimization/Probe.php`, `resources/views/ssh/optimization/probe.blade.php` |
| Facts / budget DTOs | `app/DTOs/ServerFacts.php`, `Budget.php` |

### Proposing changes

| Piece | File |
| --- | --- |
| Shared logic | `app/Optimizers/AbstractOptimizer.php` |
| PostgreSQL | `app/Optimizers/Database/PostgresOptimizer.php` |
| PHP-FPM + OPcache | `app/Optimizers/PHP/FpmOptimizer.php` |
| nginx | `app/Optimizers/Webserver/NginxOptimizer.php` |
| Kernel / sysctl | `app/Optimizers/OS/KernelOptimizer.php` |
| Redis | `app/Optimizers/Redis/RedisOptimizer.php` |
| Rulesets | `resources/optimization/rules/*.yaml` — postgresql, php-fpm, nginx, kernel, redis |

### Writing changes

| Piece | File | Target |
| --- | --- | --- |
| Single write path | `app/Support/Optimization/ChangeWriter.php` | — |
| PostgreSQL | `app/Optimizers/Database/PostgresApplier.php` | `conf.d/zz-vito-tuning.conf` |
| PHP-FPM | `app/Optimizers/PHP/FpmApplier.php` | `fpm/conf.d/zz-vito-tuning.ini` + pool files |
| nginx http | `app/Optimizers/Webserver/NginxApplier.php` | `conf.d/zz-vito-tuning.conf` |
| nginx workers | `app/Optimizers/Webserver/NginxContextApplier.php` | `nginx.conf`, edited in place |
| Kernel | `app/Optimizers/OS/KernelApplier.php` | `/etc/sysctl.d/60-vito-tuning.conf` |
| Redis | `app/Optimizers/Redis/RedisApplier.php` | `redis.conf` + live `CONFIG SET` |

### Storing and showing

| Piece | File |
| --- | --- |
| Orchestration | `app/Actions/Optimization/GeneratePlan.php` |
| Models | `app/Models/OptimizationPlan.php`, `OptimizationProposal.php`, `OptimizationChange.php` |
| Schema | `database/migrations/2026_08_30_130000_create_optimization_tables.php` |
| Policy | `app/Policies/OptimizationPlanPolicy.php` |
| Controller | `app/Http/Controllers/OptimizationController.php` (3 named routes) |
| Resources | `app/Http/Resources/OptimizationPlanResource.php`, `OptimizationProposalResource.php` |
| UI | `resources/js/pages/optimization/` |
| Load class | `app/Enums/SiteLoadClass.php` + `sites.load_class` |

---

## Tests

**~95 tests passing.** All SSH is faked; no real connections.

| File | Covers |
| --- | --- |
| `tests/Unit/ResourceBudgetTest.php` | The RAM pie on varied hardware |
| `tests/Unit/FormulaEvaluatorTest.php` | Arithmetic, and rejection of anything else |
| `tests/Unit/RulesetLoaderTest.php` | Ruleset validation, version narrowing |
| `tests/Unit/ServiceVersionTest.php` | Version matching across engines |
| `tests/Unit/PostgresOptimizerTest.php` | Proposed values, bounds, unit comparison |
| `tests/Feature/Optimization/ProbeTest.php` | Fact parsing, engine-specific queries |
| `tests/Feature/Optimization/GeneratePlanTest.php` | Persistence, read-only guarantee |
| `tests/Feature/Optimization/PoolAllocatorTest.php` | Weighted split, floors, exclusions |
| `tests/Feature/Optimization/OptimizationControllerTest.php` | Routes, authorization, no credentials in payload |
| `tests/Feature/Optimization/UpdateLoadClassTest.php` | Load class endpoint, validation, authorization |
| `tests/Feature/Optimization/ApplyPlanTest.php` | Backup before write, restore on rejection, drift, rollback |
| `tests/Feature/Optimization/AppliersTest.php` | Where each component writes, and what it leaves alone |
| `tests/Unit/RedisOptimizerTest.php` | Memory ceiling, and the queue eviction guardrail |
| `tests/Unit/ServerOptimizersTest.php` | nginx worker sizing, kernel values, container skip |

### Running them

```bash
php vendor/bin/pest tests/Unit/ResourceBudgetTest.php \
    tests/Unit/FormulaEvaluatorTest.php \
    tests/Unit/RulesetLoaderTest.php \
    tests/Unit/ServiceVersionTest.php \
    tests/Unit/PostgresOptimizerTest.php \
    tests/Feature/Optimization
```

**If tests hang or report "database is locked":** a killed run has left an orphaned
`php.exe` holding `storage/database-test.sqlite`. Kill them and rerun.

---

## What needs checking by hand

These cannot be verified from tests alone.

### 1. The UI has never been rendered

The Optimization tab was written without running the frontend — I cannot run
`npm run build`. **This is the largest unverified area.**

```bash
npm run dev
# then open a server → Optimization → Analyze server
```

Worth looking at: the budget bar segments and their colours in both light and dark
themes; whether the proposal rows read clearly; whether the expanded rationale is
legible; layout on a narrow window.

### 2. The probe against a real server

Every fact has been tested against canned output, never against a live machine. The
shell in `probe.blade.php` is the least-tested code in the project — particularly:

- the root device lookup through `findmnt`/`lsblk` on varied disk layouts
- `fpm_avg_rss_mb` when several pools run at once
- `sudo -u postgres psql` where the socket or peer auth differs

### 3. The proposed values, and applying them

The formulas are ported from the 360 toolkit and unit-tested, but no proposal has
been applied to a real database. Run an analysis against a staging server and check
the numbers look right for that hardware before trusting them.

**Apply and rollback have never touched a real machine.** The write path is covered by
tests against a fake, which proves the ordering — back up, write, validate, restore on
failure — but not that `postgres -C` behaves as expected on a real install, nor that
the drop-in path is right for every packaging. Try it on a staging database first, and
roll it back, before using it anywhere that matters.

### 4. The load class selector has not been clicked

Site settings → **Expected load** opens a dialog through the registry. The endpoint
and validation are tested, but the dialog itself has never been rendered.

---

## What is left

### Phase 1 — done

Remaining verification is by hand, above: render the Optimization tab, run the probe
against a real server, and sanity-check the proposed values on staging.

### Phase 2 — done, but unproven on a real machine

- [x] `ChangeWriter`: read → hash → back up → write → validate → restore on failure
- [x] Drop-in config file (`conf.d/zz-vito-tuning.conf`) rather than editing the packaged file
- [x] `postgres -C` validation before the service is asked to use the config
- [x] Restart refused without explicit confirmation; the dialog names what is dropped
- [x] Rollback over the `optimization_changes` manifest, replayed in reverse
- [x] Drift detection — a file edited since the plan was drawn is not overwritten
- [x] Queued jobs on the `ssh` queue, locked per server, so two applies on one
      machine queue behind each other rather than interleaving writes
- [ ] Verify step after apply (re-probe and confirm the value took effect)

### Later phases

MySQL/MariaDB · nginx · kernel/sysctl · Redis · verify and drift detection · AI
advisor. See the plan.

---

## Known gaps and decisions

**The `.env` warnings in test output** are pre-existing: this checkout has no `.env`,
and existing tests warn identically. Not introduced here.

**One pre-existing architecture test fails** — `conventions.writes-without-bootstrap-bust`
flags `Actions/Plugins/Github/CheckForUpdates.php` and `InstallGithubPlugin.php` for
missing `forgetVersion()`. Confirmed by stashing this work and re-running. Untouched,
since it is unrelated.

**`symfony/expression-language` was not added.** The project forbids adding
dependencies without approval, so formula evaluation is a small recursive-descent
parser over `+ - * / ( ) min max` and named variables. A test asserts `phpinfo()` is
rejected. Swapping in the Symfony component later is straightforward if preferred.

**Pool floors compress the weighting on small machines.** Every site is funded a
2-worker floor before the remainder is weighted, so on a 4GB pool with four sites the
high/low ratio comes out nearer 10:4 than the 6:1 the weights imply. That is
deliberate — a starved site is worse than an imprecise ratio — but it means load
classes matter more on larger machines.

**PHP-FPM proposals are keyed by site.** Two sites legitimately hold different values
for `pm.max_children`, so the stored key is `domain · pm.max_children`.
