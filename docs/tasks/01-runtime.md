# Subtask 1 — repository and runtime foundation

## Approval and discovery

The user approved the general design and this implementation subtask separately.
MIT attribution: **DonMario**. Initial directory was empty and inherited a parent
Git repository. A standalone repository was initialized on `main` without commits.

Discovery: Linux x86_64/Aurora, SELinux enforcing, local Docker 29.7.2 and Compose
5.5.0. Symfony CLI requires an empty scaffold target and creates an initial Git
commit by default; `--no-git` suppresses that. PostgreSQL 18 uses a volume at
`/var/lib/postgresql`, not the older `/var/lib/postgresql/data` mount point.

## Implemented design

- Digest-pinned FrankenPHP/PHP 8.5 development tooling, classic request mode.
- Composer 2.10.3 and checksum-verified Symfony CLI 5.20.0.
- Actual scaffolding command in the tooling container:

  ```sh
  symfony new symfony-task1 --version="8.1.*" --no-git --php=8.5
  ```

  The empty staging directory was `/tmp/opencode/symfony-task1` on the host.
  Generated framework/config/lock files were imported and reviewed. Generated
  sample credentials were excluded. Twig, AssetMapper, DoctrineBundle/DBAL,
  Monolog, PHPUnit, PHPStan and PHP CS Fixer were installed with Composer/Flex.
- Symfony **8.1.6**, PHP **8.5.9**, FrankenPHP **1.12.7**, Caddy **2.11.4**,
  PostgreSQL **18.6**, DBAL **4.4.4**, PHPUnit **13.3.3** at initial resolution.
- `bin/dev` local setup with missing-state safeguards and owner-only credentials.
- Non-root application, loopback HTTP, internal PostgreSQL, separate app/admin roles.
- Twig/CSS and liveness/readiness endpoints through real HTTP.
- Snapshot-based E2E/check stacks, unique resources, redacted evidence and cleanup.
- Clean consumer-checkout verification with development-data persistence/isolation.

The minimal DBAL task removes the recipe's ORM configuration; entities and module
migrations arrive in Subtask 2. The scaffold's unused private `getAllowedEnvs()`
was removed after source inspection and PHPStan detection. Runtime commands still
select explicit development/test environments.

## Security/performance review

The fresh design review led to explicit separation of construction/setup, test
image snapshots instead of shared source/vendor, protected bootstrap credentials,
fail-closed missing-settings recovery, correct Compose replacement semantics,
and database **container recreation** as the durability proof.

Readiness uses a dedicated short-lived connection and generic output. A stopped
database must yield an actual 503 within five seconds in the E2E scenario; this
does not establish a universal DNS/network deadline. PostgreSQL cancels a targeted
long-running statement at the configured one-second timeout. Default application
persistence is unaffected by readiness statement limits.

Only two default long-running services are used. Source changes are visible in
development, snapshots have compiled assets, and dependency/image layers are reused.
Host SELinux enforcement was verified; Docker's actual confinement is a daemon
setting and is not inferred from host enforcement alone.

Clean-checkout testing exposed host-permission assumptions: PostgreSQL's init hook
is now copied into its image with an explicit executable mode, and application
configuration copied into the runtime image has explicit readable modes.

The fresh-checkout outage test additionally caught Docker DNS removal of a stopped
service: PDO connection timeout alone does not bound name resolution. The app now
uses one-second DNS retries with one attempt. The reverified outage phase returned
the expected 503 in approximately two seconds, below the five-second requirement.

## Verification status

**Accepted by the user on 2026-09-11.** The
runtime/checks and clean consumer journey pass. The independent reviewer rechecked
all five resolutions and cleared the implementation-review blockers for Subtask 1.

Required commands:

```sh
./bin/dev setup
./bin/dev check
./bin/dev test
./bin/dev verify-setup
```

The E2E suite tests HTTP/CSS, safe health output, webroot confinement, read-only
source/secret isolation, nonsuperuser writes, statement cancellation, outage,
database recreation and persisted data/recovery. The consumer journey additionally
verifies first use without vendor/local settings, repeat setup, stop/start,
missing-settings refusal and tests alongside development data.

### Executed evidence

| Command | Result | Observable behavior / evidence |
| --- | --- | --- |
| `./bin/dev setup` | Exit 0 | Real app/database healthy; Twig at `http://127.0.0.1:8080` |
| `./bin/dev check` | Exit 0 | Validation/audit/platform, lint, PHPStan max, Symfony style and shell fault-injection contracts; `var/test-runs/run-ccyxwyRJ/checks.log` |
| `./bin/dev test` | Exit 0 | **7 tests, 51 assertions** across HTTP/database/outage/recreation/recovery phases after review fixes; `var/test-runs/run-ao6q0oEM/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Exit 0 | Clean checkout, persistent marker, unchanged credentials, missing/incomplete refusal, nested-key canaries absent from test image, isolated E2E alongside dev; `/tmp/opencode/donmario-setup-iKUssf1F/` |

The final consumer verification includes the current E2E suite after review fixes.
Successful run logs are redacted; temporary local configurations remain private.
Observed phase timings: checks about five seconds, healthy HTTP tests about 0.1s,
statement-limit verification about one second, outage tests about two seconds,
and initial container readiness about nine seconds on this host with cached images.
These are observations, not portable throughput/startup guarantees.

## Independent implementation review

Fresh reviewer session: `ses_f71194576fferzdYN3EVEtruQi`. Review was read-only and
used only this subtask's brief, source and safe evidence. Findings/resolutions:

1. **Nested private-key context exclusions:** add recursive `.pem`/`.key` patterns
   and a minimal database-image context allowlist. Consumer verification creates
   harmless root/nested canaries; E2E asserts their absence from the built image.
2. **Password in psql arguments:** use psql `\getenv` from the existing environment,
   preserving SQL-literal quoting. A sentinel-based stub check rejects password
   argv/stdin exposure; real PostgreSQL initialization and app-role writes pass.
3. **Docker context precedence:** honor `DOCKER_CONTEXT` before `DOCKER_HOST`, then
   pin all subprocesses to the validated Unix endpoint and default local builder.
   Conflicting context/host combinations are fault-tested without remote operations.
4. **Weak missing-settings assertion:** verify absence before restoration, require
   the expected refusal, and restore on failure/interruption. Add incomplete-file
   preservation and controlled replacement/changed-settings regressions.
5. **Cleanup result propagation:** failed log collection or removal of known-built
   unique images makes verification fail; fault injection confirms no false PASS.

Reviewer recheck approved all five resolutions with no remaining blocker in scope.
The final root E2E run and development refresh also exited 0 after that recheck.
Cleanup fault injection directly covers the test wrapper; the corresponding
consumer-verifier cleanup was code-reviewed and exercised on the successful path.

The PostgreSQL cancellation test establishes real driver/server behavior. It sets
the statement limit itself, so it is not an independent proof of the probe's SQL
setup; the actual HTTP outage/readiness behavior is tested separately.
