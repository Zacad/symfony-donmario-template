# Subtask 2 — module and persistence boundaries

## Approval

**Later convention update (2026-09-11):** Subtask 3a supersedes the original
`Contract/Command`, `Contract/Query`, `Contract/Result` placement with public data
co-located beside handlers in Application use-case folders. See
[Subtask 3](03-cqrs-transactions.md) and the current architecture. The acceptance and
evidence below describe Subtask 2 as verified at the time.

The user accepted Subtask 1 and approved this subtask's design and implementation
on 2026-09-11. The initial implementation was verified and reviewed. Before
acceptance, the user requested domain-owned repository interfaces and approved
the correction below. **The user accepted Subtask 2, including the implemented,
reverified and independently approved correction, on 2026-09-11.**

## Approved design and acceptance

- Ordinary module-owned Symfony service imports, private/autowired services,
  explicit ORM mappings, and a filesystem inventory reconciled with registration.
- Doctrine ORM/Migrations, Symfony UID and development-only Deptrac through
  containerized Composer/Flex. One business EntityManager/default connection.
- Initial TaskTracking Task with UUID/title, repository and module migration.
- Setup starts PostgreSQL, validates/applies checked-in migrations, then confirms
  app readiness. Up remains start-only. Pending migrations sort globally by their
  timestamp, with duplicate/malformed versions rejected. Each migration is atomic;
  earlier successful migrations may remain committed if a later migration fails.
- Static contract/internal dependency boundaries; restricted readonly contract
  DTOs/enums; compiled service reference checks; ORM ownership and actual schema
  checks. Every category includes positive and diagnostic-specific negative cases.
- Real ORM persist/clear/reload, migration repeatability/failure rollback,
  database-container recreation and clean consumer setup with dev/test isolation.

## Security and performance review

### Approved repository-segregation correction

Domain owns `TaskRepository` with `add(Task): void` and `find(Uuid): ?Task`.
Infrastructure supplies `Persistence/DoctrineTaskRepository` using EntityManager
composition and an explicit Symfony interface alias. Task no longer references
the Doctrine implementation through its mapping. Application code depends on
domain ports; source/compiled service checks reject direct outward dependencies.
The existing attribute-mapping convention is retained. Repository methods do not
flush or commit; application transaction coordination is Subtask 3.

Acceptance: actual interface binding/autowiring; PostgreSQL add/flush/clear/find
and missing-ID behavior; domain/application negative dependency cases; migration,
durability and consumer setup regressions; fresh independent review. Security:
cross-module ownership/isolation remains enforced and tests use disposable data.
Performance: the adapter adds no query to add(), find() delegates to ORM lookup,
and layer validation stays offline/compile-time.

Migrations use the nonsuperuser app role. Test identity is verified before database
mutations. Failure fixtures use disposable test resources and immutable snapshot
source. Module checks are architectural validation, not database-role isolation or
a runtime sandbox: dynamic SQL, migration SQL ownership and computed runtime
service lookups require targeted tests/review. Dependency checks are offline; DI
checks run at compilation; metadata/schema checks run during verification. No
per-request schema inspection or database work during image construction.

Independent design review identified registration-inventory gaps, full-class-name
migration ordering, exact DI coverage/exceptions, and the need to bound contract
and schema guarantees. The approved implementation incorporates these findings.

## Required evidence

Resolved versions: Doctrine ORM **3.7.0**, Migrations **3.9.7**, MigrationsBundle
**3.7.0**, Symfony UID **8.1.5**, Deptrac **4.7.1**. Existing locked Symfony/DBAL
versions were retained. The MigrationsBundle recipe was reviewed: its global
migration placeholder was removed and configuration replaced with module paths,
explicit default EntityManager and per-migration transactions. The UID recipe
adds no application files. Deptrac's contrib recipe was declined by existing Flex
policy; explicit first-party configuration is checked in. The dev-only source
validator declares its nikic/php-parser dependency and dev autoload path.

Implementation discovery caught two integration issues: module resource paths
must be relative to Resources/config, and optional vendor service definitions
cannot be blindly autoloaded by the DI guard. Real framework compilation tests
also establish the inventory pass ordering before Symfony makes controllers public.
Standalone ORM test factories explicitly enable PHP native lazy objects.

Commands executed during dependency installation:

```sh
./bin/dev composer require 'doctrine/orm:^3.4' 'doctrine/doctrine-migrations-bundle:^3' 'symfony/uid:8.1.*' --no-interaction
./bin/dev composer require --dev 'deptrac/deptrac:^4.7' --no-interaction
./bin/dev composer require --dev 'nikic/php-parser:^5' --no-interaction
./bin/dev composer require 'ext-mbstring:*' --no-interaction
```

The parser installation initially failed its cache-clear auto-script during
integration; after correcting resource paths and optional-service reflection,
`./bin/dev composer install --no-interaction` completed with exit 0. Exact locks,
audit, platform checks and recipes are verified by `check`.

```sh
./bin/dev setup
./bin/dev check
./bin/dev test
TMPDIR=/tmp/opencode ./bin/dev verify-setup
```

### Executed evidence before independent implementation review

| Command | Result | Observable behavior / evidence |
| --- | --- | --- |
| `./bin/dev setup` | Exit 0 | Existing checkout migrated to `TaskTracking/Version20260911000100`; app/database healthy at `http://127.0.0.1:8080` |
| `./bin/dev check` | Exit 0 | **154 tests, 632 assertions**; Deptrac 0 violations/uncovered; offline source/DI/metadata/migration checks, audit/platform, PHPStan max, Symfony style and shell contracts; `var/test-runs/run-knfgTAMu/checks.log` |
| `./bin/dev test` | Exit 0 | **28 tests, 288 assertions** across HTTP, migrations, schema boundaries, ORM persistence, outage and recovery; `var/test-runs/run-fOsm8wKB/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Exit 0 | Clean consumer migrates on setup; same Task UUID/title and complete migration history after repeat setup, down/up and isolated E2E; credentials/refusal/canary checks retained; `/tmp/opencode/donmario-setup-dMJrHJWM/` |

Architecture fixtures exercise actual Deptrac subprocesses, Symfony container
compilation and attribute metadata. E2E migration fixtures execute chronologically
across deliberately reverse-alphabetical namespaces: a committed predecessor is
visible to the failing migration; its deliberate PostgreSQL exception rolls back
DDL/data and its history entry. A fresh corrected pending migration recovers and
reruns without changing history. Wrong actual database and CLI override cases fail
before writes; all these fixtures target only the disposable PostgreSQL server.

Public-schema negatives include asset-filter hiding, unknown/prefix-only tables,
cross-module/cross-schema FKs, missing tables/columns, drift and unsupported views.
Transactional cleanup is verified before following test phases. Consumer helper
assertions use the real ORM repository and complete migration-history comparison.

Observed checks took approximately 12 seconds (architecture PHPUnit about four
seconds); E2E outage HTTP was approximately two seconds. These are observations,
not portable timing guarantees. Source images, secrets, caches and database roles
retain the Subtask 1 isolation model. Evidence directories can contain private
settings on failed runs; only redacted logs are suitable for sharing.

## Independent implementation review and resolutions

Fresh read-only reviewers:

- Static/container boundaries: `ses_f70474fffffe7wVuHWPLxFe32O`.
- Persistence/migrations/setup: `ses_f70474fe8ffexj8eltZ2tAP1jh`.

Findings and verified resolutions:

1. **Platform could relay a module's private service through named DI aliases.**
   Give Platform its own checked context and prohibit direct Platform-to-module
   and module-to-Platform edges. Alias/locator regressions now reject the relay;
   normal technical wiring passes. A narrowly identified standard ContainerBag
   service remains available for controller parameters, with a test proving it
   cannot expose services.
2. **Classless module PHP configuration escaped class-level source analysis.**
   Enforce the YAML configuration baseline. SourceRules now rejects PHP config,
   including a literal foreign-internal dependency outside any class. Actual
   YAML module imports and local customization remain covered by compiled tests.
3. **Explicit Domain services missed registration-default checks.** Apply defaults
   validation to all registered concrete module definitions independently of the
   filesystem service layers. Valid Domain services resolve; each invalid default
   yields the expected diagnostic without registering Domain data as services.
4. **Noncanonical service class strings produced false missing-registration errors.**
   Resolve compile-time parameters and canonicalize module-only reflection names.
   Leading separators, class-name case and parameters now pass inventory and
   instantiate the expected configured service. Symfony's attribute behavior on
   raw noncanonical class strings is not normalized by this validation pass.
5. **Inherited private many-to-many associations were reflected on the child.**
   Use Doctrine's association declaring class. A real private mapped-superclass
   association passes the full expected ownership map; foreign inheritance and
   invalid join-table negatives still fail.

Both reviewers rechecked their findings against the corrected source, regressions
and final safe evidence, and approved their respective scopes with no outstanding
blockers. Documented non-transitive framework wiring, runtime lookup/SQL and
public-schema audit limitations remain explicit in `docs/architecture.md`.

### Final reverified evidence

| Command | Result | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | Exit 0 after review fixes; migrations already current, app/database healthy | `http://127.0.0.1:8080` |
| `./bin/dev check` | Exit 0; **168 tests, 664 assertions**, Deptrac **260 allowed / 0 violations / 0 uncovered**, audit/lint/PHPStan max/style/shell contracts pass | `var/test-runs/run-guszSObD/checks.log` |
| `./bin/dev test` | Exit 0; **28 tests, 288 assertions**, all migration/schema/HTTP/ORM/outage/recreation/recovery phases pass | `var/test-runs/run-7K26ZVXm/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Exit 0; fresh migrated consumer, credential/data/history preservation and isolated E2E pass | `/tmp/opencode/donmario-setup-ifeRQItd/` |

The final check rerun corrected an overbroad test expectation about Symfony's
attribute tags for noncanonical definition names; that test now establishes the
intended inventory acceptance and actual service resolution. No production code
changed after the successful final E2E and consumer runs. Final checks took about
13 seconds, with architecture PHPUnit about 4.4 seconds on this host.

## Repository-segregation correction evidence

The domain now owns `TaskRepository`; its explicit module-local alias selects
`Infrastructure/Persistence/DoctrineTaskRepository`. The adapter composes the
EntityManager and has no flush/commit method. Task has no infrastructure import
or `repositoryClass` mapping. No package or database migration changes were needed.

Deptrac separates each module's Domain and Application from its remaining adapter
layers, with explicit mapping-only exceptions and a separate runtime-persistence
layer. Source regressions reject outward references, Doctrine query APIs in a
domain repository interface and repositoryClass attribute coupling. Compiled DI
uses the actual constructor/property/method's Domain interface type, then checks
the resolved implementation's ownership and interface. Real alias/autowiring,
explicit setter/property wiring, concrete alias bypass and direct EntityManager
injection are tested.

Repository E2E and consumer verification access the actual private interface alias
through a verification-only Kernel with separate cache/build/share directories.
Ordinary application services stay private. Tests prove missing-ID null, add()
without implicit flush, caller-controlled flush, clear/reload through the interface
and persisted UUID/title after database-container recreation. The clean consumer
journey also uses the interface and verifies migration history/data preservation.

| Command | Result | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | Exit 0; existing migration already current and HTTP/database ready | `http://127.0.0.1:8080` |
| `./bin/dev check` | Exit 0; **185 tests, 753 assertions**; **278 allowed / 0 violations / 0 uncovered**; audit, lint, PHPStan max, style and shell checks pass | `var/test-runs/run-biZuCk8t/checks.log` |
| `./bin/dev test` | Exit 0; **28 tests, 296 assertions**, real PostgreSQL/HTTP/migration/schema/outage/durability phases | `var/test-runs/run-RxCNx7jK/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Exit 0; interface-backed consumer persistence, repeatability and isolated E2E | `/tmp/opencode/donmario-setup-c5fqhFGY/` |

The first check caught missing type information on Symfony's method-call array;
its installed API shape is now annotated, and the full check passes. Runtime code
was unchanged by that annotation; the subsequent consumer run includes the entire
current E2E suite. Checks took about 16 seconds with architecture PHPUnit about
7.2 seconds on this host. The subsequent independent review and final evidence
follow below.

### Independent correction review

Fresh reviewer: `ses_f6fe0f0f0ffeftBiGimEB3zjeO`. The repository interface, adapter,
private alias and actual persistence journey were approved. Guard findings were
resolved and regression-tested:

1. **Inline dependency-layer context:** track the actual injection-site class
   separately from the diagnostic root. Each module inline definition owns its
   layer; vendor wiring/locators retain consumer context. Memoization includes
   the site and nested processing restores it. Infrastructure/vendor/locator
   roots cannot hide inline Application outward injection; inline Domain services
   can consume their declared port.
2. **Overbroad mapping namespace exception:** enumerate exact mapping declaration
   types, including the singular AttributeOverride/AssociationOverride values,
   while permitting the normal ORM namespace import. Real declaration syntax
   passes; ClassMetadataFactory, ClassMetadata, AttributeDriver and QueryBuilder
   remain forbidden in Domain repository interfaces.
3. **Named arguments after complex defaults:** associate reflection types with
   both numeric positions and parameter names. Actual Symfony autowiring tests
   preserve array defaults and inject the port for constructors/configured methods.

The reviewer rechecked all findings and the final safe evidence and approved the
correction with no outstanding blockers. The documented bounded traversal and
port-inference limitations remain explicit; neither this correction nor its checks
introduce a runtime security sandbox or per-request schema/graph inspection.

### Final correction evidence

| Command | Result | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | Exit 0 after review fixes; migration current and application/database healthy | `http://127.0.0.1:8080` |
| `./bin/dev check` | Exit 0; **195 tests, 794 assertions**; **279 allowed / 0 violations / 0 uncovered**; audit/lint/PHPStan max/style/shell contracts pass | `var/test-runs/run-PepVb2KJ/checks.log` |
| `./bin/dev test` | Exit 0; **28 tests, 296 assertions**, all PostgreSQL/HTTP/migration/schema/port-persistence/outage/recovery phases | `var/test-runs/run-tI2nptAZ/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Exit 0; fresh consumer uses private interface alias, preserves credentials/Task/history through repeated setup and isolated E2E | `/tmp/opencode/donmario-setup-VfbtpG1d/` |

Final checks took about 17 seconds (architecture PHPUnit 8.1 seconds). The last
change added two exact dev-checker declaration types and their positive fixture;
production runtime code did not change after the final PostgreSQL/consumer runs.
All previous evidence above is retained as historical execution/review context.
