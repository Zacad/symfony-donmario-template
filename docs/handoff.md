# Session handoff — Symfony application template

## Status and next action

Date: **2026-09-11**

Repository: `/var/home/adam/Projects/symfony-donmario-template`

The user has accepted:

- **Subtask 1:** repository and Docker runtime foundation.
- **Subtask 2:** module/persistence boundaries, including the correction introducing
  Domain repository interfaces and Infrastructure Doctrine implementations.
- **Subtask 3a:** Application public-data co-location, dependency boundaries and
  service exclusions; accepted on **2026-09-11**.

Implementation, actual container/PostgreSQL verification and independent reviews
are complete for all three accepted subtasks. All review findings are resolved.

The user subsequently chose to co-locate public commands, queries and results
beside their handlers in `Application/<UseCase>`, and approved the bounded
documentation/architecture-check alignment plan on **2026-09-11**.

The user accepted the completed Subtask 3a and requested continuation.
**Current: review the proposed Subtask 3b CQRS/transaction design and obtain approval.**
Subtask 3b implementation has **not** been approved.

This is a dated handoff. Reconcile it with subsequent user instructions, current
task records and the working tree when resuming; refresh it at the next handoff.

## Read first

Paths below are relative to the repository root:

1. `AGENTS.md`
2. `README.md`
3. `docs/architecture.md`
4. `docs/roadmap.md`
5. `docs/tasks/03-cqrs-transactions.md` — active decisions, scope and evidence
6. `docs/tasks/02-module-persistence.md` — accepted persistence foundation
7. `composer.json`, `composer.lock` and `symfony.lock`

Inspect current Git status and history before editing. The repository branch is
`main`; the user requested an initial local commit of the work to date. No remote
is configured, and the user explicitly deferred pushing. Treat existing files as
project work. Further commits/pushes require an explicit user request.

## Implemented foundation

- Symfony **8.1**, PHP **8.5**, FrankenPHP, Twig/AssetMapper and PostgreSQL **18**.
- Docker-first tooling through `./bin/dev`.
- Liveness/readiness endpoints, a dedicated readiness connection, isolated
  development/test resources and protected locally generated credentials.
- Doctrine ORM **3.7.0**, Migrations **3.9.7**, MigrationsBundle **3.7.0**,
  Symfony UID **8.1.5** and Deptrac **4.7.1**. Exact versions are locked.
- One default business EntityManager/connection.
- Setup applies checked-in migrations without resetting data; `up` starts services.
- Module migrations have unique UTC timestamps and pending migrations execute
  chronologically across namespaces, with per-migration transactions.

The last development setup reported the application ready at
`http://127.0.0.1:8080`; check current runtime state if needed after resuming.

## Repository convention — explicit user requirement

TaskTracking currently contains:

```text
src/Module/TaskTracking/
  Domain/
    Task.php
    TaskRepository.php
  Infrastructure/
    Persistence/
      DoctrineTaskRepository.php
  Resources/
    config/services.yaml
    migrations/Version20260911000100.php
```

Domain owns the repository interface:

- `add(Task): void`
- `find(Uuid): ?Task`

The Doctrine adapter composes EntityManager and implements that interface.
Symfony binds the interface to the private implementation. Application handlers
must inject Domain ports.

**Repositories do not flush or commit.** Transaction coordination belongs to the
application transaction boundary designed in Subtask 3.

Task retains approved Doctrine mapping attributes but has no Infrastructure
import or `repositoryClass` reference.

Repository interfaces are module-internal ports. Public command/query/result data
uses `Application/<UseCase>/<Name>{Command,Query,Result}.php`; neighboring handlers
and helpers stay internal. Public events use `Contract/Event`. Domain has no
Application DTO dependency, and public event payloads cannot carry Application data.

## Architectural checks and constraints

Implemented checks cover:

- Source namespaces, paths, public contracts and inward dependencies.
- Module service registration/defaults and resolved DI edges.
- Entity/migration inventories.
- Doctrine metadata ownership and actual PostgreSQL public-schema boundaries.

Subtask 3a separates public Application data and event data from Application
implementation in Deptrac, shares their classification with source/container checks,
and excludes DTOs from required service discovery while requiring handlers.
Early/late DI checks reject explicit/inline data-service registrations. Subtask 3a
is verified, independently reviewed and accepted; evidence is in the active task record.

Domain cannot depend on Application, Infrastructure, UI or migration code.
Application cannot depend directly on concrete Infrastructure/UI implementations.
Domain/Application cannot use runtime Doctrine/PDO APIs.

Mapping exceptions are narrow: exact Doctrine mapping declaration types
(including the singular override declaration values), the normal ORM namespace
import and the Symfony Doctrine UUID mapping type.

Compiled DI permits an Infrastructure implementation through a declared,
same-module Domain interface. It handles direct constructor/property/method
injection, named arguments and inline service layer context. Port types are not
inferred from arrays, generic locators, factory arguments or inline adapters.

Read `docs/architecture.md` for exact guarantees and limitations. These checks
are not a runtime sandbox or universal dynamic-SQL analysis.

Module configuration uses YAML. Keep framework/Platform exceptions explicit and
narrow when designing CQRS integration.

## Accepted Subtask 3a evidence

Completed on **2026-09-11**, both with exit **0**:

- `./bin/dev check`: **233 tests, 1004 assertions**, Deptrac **280 allowed / 0
  violations / 0 uncovered**, audit/lint/PHPStan/style/shell checks passed.
  Evidence: `var/test-runs/run-tsoSpXYc/checks.log`.
- `./bin/dev test`: **28 tests, 296 assertions** with actual HTTP/PostgreSQL,
  migrations/schema boundaries, ORM persistence, outage/recreation/recovery.
  Evidence: `var/test-runs/run-32r5L0jL/`.

These runs establish the boundary-alignment integration and regressions. Messenger,
validation middleware and command transaction coordination are still the 3b proposal.
See the active task record for exact behavior and review status.

Independent 3a reviewer: `ses_f6f346d5bffem59acqW8EJwnqp`; approved with no actionable
findings or blockers. Only acceptance/design documentation changed after verification.

## Accepted Subtask 2 evidence

These are Subtask 2's historical results, preceding the 3a public-data placement
change. They do not establish verification of the current 3a changes.
All commands exited **0**:

| Command | Result |
| --- | --- |
| `./bin/dev setup` | Migration current; application/database healthy |
| `./bin/dev check` | **195 tests, 794 assertions**; Deptrac **279 allowed, 0 violations, 0 uncovered**; audit/lint/PHPStan max/style passed |
| `./bin/dev test` | **28 tests, 296 assertions** using real HTTP/PostgreSQL |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Fresh consumer setup, interface-backed persistence and development/test isolation passed |

Evidence:

- `var/test-runs/run-PepVb2KJ/checks.log`
- `var/test-runs/run-tI2nptAZ/`
- `/tmp/opencode/donmario-setup-VfbtpG1d/`

Repository tests establish missing-ID null, `add()` without implicit flush,
caller-controlled flush, clear/reload, and persistent UUID/title across
database-container recreation. Migration failure/rollback/recovery and negative
architecture/schema cases also pass.

Independent correction reviewer: `ses_f6fe0f0f0ffeftBiGimEB3zjeO`.
All findings were resolved and rechecked. Detailed evidence and review history are
in `docs/tasks/02-module-persistence.md`.

Local execution artifacts may be temporary. Do not expose private settings or
publish whole evidence directories. Preserve `var/docker/local.env` and
development data.

## Subtask 3 continuation

Subtask 3a is accepted. The detailed 3b proposal is ready for user review and
design/implementation approval.
Read `docs/tasks/03-cqrs-transactions.md` for the design and prior execution evidence.
The repository still has no business messages/handlers or Messenger/Validator
installation; the new layout is established through verification fixtures.

The 3b proposal covers:

- Separate command/query buses and handler registration.
- Domain repository injection.
- Validation and query-result propagation.
- Transaction ownership, middleware ordering, flush and rollback.
- Shared use cases through thin HTTP and CLI adapters.
- Real PostgreSQL success/failure journeys.
- Necessary narrowly scoped architecture-check integration.

Its main decisions are separate synchronous buses, YAML validation before physical
transaction startup, a logical outer invocation scope that tracks nested failures,
root-owned Doctrine transactions and manager cleanup, exact helper/handler wiring,
and dev/test-only JSON HTTP plus CLI adapters. A caught nested validation/handler
failure still prevents outer commit. See the active task record for precise
ordering, error contracts, security/performance review and acceptance journeys.

These remain proposed runtime decisions. Only acceptance/design documentation has
changed since the 3a verification; no Messenger/Validator installation or 3b checks
have run. Do not treat the prior 3a execution evidence as 3b evidence.

Use the approved Application co-location and public-data conventions in that
design. Proposed results are `CreateTaskCommand -> Uuid` and
`GetTaskQuery -> GetTaskResult|null`; result DTOs are optional, transport-neutral data.

Follow `AGENTS.md`:

discovery → design with acceptance criteria and security/performance review →
user approval → implementation → actual container/PostgreSQL verification →
fresh independent review → user acceptance.

Use `./bin/dev` for Composer, console commands, checks and tests. Do not rerun
unchanged passing suites merely to resume a session. Run appropriate checks for
new changes or unresolved concerns.

## Fresh-session prompt

> Read `docs/handoff.md`, `docs/tasks/03-cqrs-transactions.md` and the referenced
> project instructions. Subtasks 1, 2 and 3a are accepted. Public commands,
> queries and results are co-located with handlers in Application use-case folders.
> Subtask 3b CQRS/transactions requires its own design and implementation approval.
