# Working on this template

## Start here

- On a fresh session, read `docs/handoff.md` for the latest handoff and approval status.
- Read `README.md`, `docs/architecture.md`, `docs/roadmap.md`, and the active task record.
- Inspect `composer.json`, `composer.lock`, and `symfony.lock` before assuming a feature is installed.
- Work only in this standalone repository. Inspect user changes before editing.
- Prefer idiomatic Symfony, KISS and YAGNI; make dependencies and module boundaries explicit.
- Use the Symfony documentation matching the installed **8.1** release.

## Mandatory task workflow

1. Discover the current code and requirements for one coherent subtask.
2. Present a design with acceptance criteria and explicit security/performance review.
3. Obtain user approval **before implementing** that subtask.
4. Implement and prove the agreed journey end to end using actual containers and PostgreSQL.
5. Request a **fresh independent subagent review** with only the task's relevant brief,
   changed files and evidence. Resolve findings and reverify affected behavior.
6. Report results and obtain user approval before starting the next subtask.

A blocked or unrun check means the task is incomplete. General design approval is
not permission to implement the whole roadmap. Git commits/pushes require an
explicit request; Symfony CLI scaffolding must use `--no-git`.

## Commands

Use `./bin/dev` for setup, Symfony console, Composer, checks and tests. Host PHP,
Composer and Symfony CLI are not prerequisites. All task evidence should include
the exact command, result and meaningful observable behavior.

- `./bin/dev check`: validation, audit, static analysis, lint and Symfony style.
- `./bin/dev test`: real HTTP/database E2E, including outage and recovery phases.
- `./bin/dev verify-setup`: fresh consumer checkout and development/test isolation.

Run appropriate checks once changes are ready; repeat for new changes or unresolved
failures. Tests must establish behavior, not merely repeat implementation details.
Never redirect destructive tests to development data or expose local secrets in
tool output, test artifacts, source control or image contexts.

## Code conventions

- Install applicable Symfony components through Composer/Flex and review recipes.
- Use attributes, autowiring, autoconfiguration, typed properties and constructor promotion.
- Keep controllers/adapters thin. Use Symfony primitives for security, validation,
  caching and messaging rather than custom frameworks.
- Use PHP CS Fixer's `@Symfony` rules and PHPStan at the configured level.
- Namespace business modules as `App\Module\<ResponsibilityEndingInIng>`.
- Follow the module contract/data-ownership rules in `docs/architecture.md`.
- Co-locate commands, queries, handlers and useful results in `Application/<UseCase>`.
  Public data uses descriptive `*Command`, `*Query`, `*Result` names at that exact
  depth; handlers/helpers remain module-internal. DTOs are data, not services.
  Other modules use this public data API through buses. Domain cannot depend on
  Application DTOs. Public events use `Contract/Event` and cannot carry Application
  data. Keep source, Deptrac and container classification aligned.
- Define repository interfaces in the owning module's Domain and Doctrine adapters
  in Infrastructure/Persistence. Inject Domain ports into application handlers;
  adapters compose EntityManager and do not flush/commit. Enforce inward dependencies.
- `Platform` contains narrowly scoped technical infrastructure, including the
  current health endpoints; it is not a shared business-model directory.

Future sessions must distinguish approved conventions from implemented checks.
Subtask 2 implements source/contract, compiled service, metadata, migration and
public-schema boundaries with the exact coverage/limitations in docs/architecture.md.
Subtask 3a updates public-data placement and service exclusions; its status and
evidence are in `docs/tasks/03-cqrs-transactions.md`. Messenger buses, validation
middleware, handler-count checks and transaction coordination are Subtask 3b and
require separate design/implementation approval.
Use module-owned migration namespaces/paths, unique UTC timestamps and reviewed
module-local SQL. Setup applies pending migrations; it never resets data.
Authentication and authorization arrive in their own subtasks.
