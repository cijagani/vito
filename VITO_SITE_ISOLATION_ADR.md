# ADR: Per-Site Runtime Isolation Foundation

- Status: Accepted for staged implementation
- Date: 2026-09-06
- Target branch: `feat/site-isolation-foundation`
- Related analysis: `VITO_SITE_ISOLATION_REVIEW.md`
- Delivery plan: `VITO_TRUE_SITE_ISOLATION_IMPLEMENTATION_PLAN.md`

## Context

Vito currently separates many sites by Linux user, but Nginx, PHP-FPM, PHP CLI, workers, cron, ports, logs, and resource limits do not share one authoritative per-site runtime identity. A Linux user may also be shared by multiple sites. That is useful operational separation, but it is not a complete isolation boundary.

The system needs a stable control-plane model before any live service configuration changes. Foundation deployment must remain compatible with existing sites and must not rewrite remote Nginx, PHP, worker, cron, or operating-system state.

## Decision

Every persisted site receives two revisioned desired-state records:

- `site_runtime_profiles` owns PHP, process-manager, filesystem, and resource-isolation intent.
- `site_web_profiles` owns Nginx-facing intent.

Existing site settings remain operational during the staged rollout. Foundation code mirrors supported legacy PHP mutations into the profiles and increments the relevant desired revision. `applied_revision` remains nullable until a later stage validates and applies the generated remote artifact.

Runtime artifacts use the immutable database site ID, never a mutable domain or Linux username. The canonical key is `vito-site-{site_id}`. Each artifact type has one expected privileged target path; runtime-operation creation rejects any other path.

Hostname ownership is unique by `(server_id, hostname)`. Port ownership is unique by `(server_id, protocol, port)`. Reservation rows use a composite site/server foreign key so a site cannot reserve a resource on another server. Replacement actions lock the parent site row before changing the set, while database uniqueness resolves races between different sites.

Existing hosted domains and site proxy ports are backfilled deterministically in creation order. If legacy data already contains a duplicate on one server, the oldest matching row owns the reservation and later duplicates remain operational but must be identified and remediated before reservation enforcement is wired into live site flows.

Reservation tables remain dormant control-plane data during Foundation. Stage 2 must resynchronize domains and ports from current site state, run the duplicate preflight, and dual-write every create/update/delete flow before treating reservations as authoritative.

Runtime operations are append-only audit records with immutable numeric server/site identity snapshots. They intentionally do not cascade-delete when a site or server is removed. Actor references may become null. Status changes lock the operation row and only allow a declared transition from the current persisted status.

## Isolation Profiles

- `legacy_unisolated`: site runs as the server SSH user.
- `shared`: the Linux user is shared by more than one site.
- `isolated`: one site currently owns the Linux user, without the later hardened controls.
- `hardened`: future target requiring the complete Nginx, PHP-FPM/CLI, filesystem, worker, cron, and resource boundary.

The labels describe known control-plane state. They do not claim container, virtual-machine, kernel, database, or network isolation.

## Apply Protocol

Later stages must follow this sequence under the site runtime lock:

1. Read one desired profile revision.
2. Render only canonical site-ID-based artifacts.
3. Validate syntax before activation.
4. Create a pending operation with the desired checksum and previous checksum.
5. Apply atomically, reload the service, and run a health check.
6. Mark the revision applied only after health succeeds.
7. Roll back to the previous validated artifact when apply or health fails.

Foundation defines the contracts and audit state for this protocol. It does not execute it.

## Consequences

- Foundation adds database writes when a site is created or existing PHP settings change, but it makes no new SSH calls and changes no remote service behavior.
- A later Nginx stage can enforce reservations without inventing a second ownership model.
- A later PHP stage can separate FPM pool identity from Linux-user identity and align CLI execution with the same site profile.
- Existing shared-user sites remain classified as `shared`; migration does not overstate their security.
- Audit rows outlive site/server deletion, so their relationships may resolve to null after the referenced resource is gone.
- Duplicate legacy hostname or port state requires an explicit preflight/remediation report before strict enforcement is enabled.

## Rejected Alternatives

- Domain-based artifact names were rejected because domains can change and can contain unsafe path characters.
- Linux-username-based FPM pools were rejected as the long-term identity because one user may own multiple sites.
- Immediate remote rewrites in the migration were rejected because migrations must be deterministic database transforms and cannot safely validate or roll back server state.
- Treating a distinct Linux user as complete isolation was rejected because shared daemons, kernel, network, and privileged control-plane access remain common boundaries.
