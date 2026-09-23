# Disposable typed-collection fixture

`CollectionsFixture` copies the current source/configuration into an owner-only
temporary project and installs `App\Module\CollectionChecking` there. Its DTOs,
native YAML validation, module service exclusions and handlers pass the production
source, DI, CQRS and dependency checks. No fixture feature is installed in the app.

Each fixture command/query declares `#[Authorize(public: true)]`. The production compiler
routes only those exact disposable operations through its private Platform public voter;
the fixture has no module voter. The runtime script enters the production `tasks`
operator scope through the trusted
`App\Tests\Fixtures\Collections\TaskExecution` adapter for each collection command
that nests production TaskTracking creation. TaskTracking's voter still reauthorizes
those nested calls; the scope is released on both success and failure. The adapter
uses `ExecutionContext` outside module code and does not expose an execution facade
through a public alias.

`tests/Architecture/CqrsTest.php` automatically exercises the compiled buses offline:
14 malformed input collections, nested field validation, Input dispatch rejection,
non-service data, scalar/value/enum outputs, typed list envelopes, an invalid query
output and same-process query recovery. It also runs the fixture through Deptrac.

`tests/E2E/CollectionsTest.php` runs once with the ordinary isolated `app_test`
environment after the test database migrations. Use `EVENT_TRANSPORT_DSN=sync://`;
the fixture sets this mode explicitly for every child process. No preparation shell
script or additional HTTP server is needed. The test applies and removes only the
fixture's module migration and deletes only its uniquely prefixed TaskTracking rows.

The owning Domain observation port uses immediate SQL on the canonical connection,
without flushing or committing. The fixture handler also uses the public
`CreateTaskCommand` to schedule TaskTracking entities. Native DBAL logging records
only driver begin/commit/rollback calls. Independent SQL, local INSERT evidence and
an external postFlush probe establish that invalid output rolls back actual SQL,
caught nested command/query output failures poison the root, valid work flushes
once and becomes visible only after root commit, and held buses/ORM recover in the
same process. A void command is exercised through that same real transaction path.

Transaction evidence deliberately excludes SQL/parameters/connection context.
Result errors have a fixed internal message and no previous validator exception;
input validation retains the standard native `ValidationFailedException` behavior.
