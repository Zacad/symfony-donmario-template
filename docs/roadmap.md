# Approval-gated delivery

General design and Subtasks **1, 2, 3a, 3b, 4 and 5b** have user acceptance. Historical
[Subtask 4](tasks/04-synchronous-events.md) and [Subtask 5](tasks/05-durable-events.md)
delivery designs are **superseded by approved [Subtask 5b](tasks/05b-native-event-bus.md)**.
Their records retain historical evidence; current behavior is documented in
[architecture](architecture.md#native-application-events-5b).

**5b is implemented, verified and independently reviewed**, with all findings
resolved. **The user accepted 5b on 2026-09-13.** Next: separate Subtask 6
web-authentication discovery/design in a fresh session. Its implementation requires
design approval. Reconcile the dated
[session handoff](handoff.md) with the active task record and subsequent instructions.
Later subtasks require their own discovery, design and approval before edits.
Split a subtask further if discovery reveals that its scope is too broad.

| # | Subtask | Main acceptance journey |
| --- | --- | --- |
| 1 | Repository/runtime foundation | Fresh Docker setup, HTTP, PostgreSQL, checks and isolation |
| 2 | Module/persistence boundaries | Boot and migrations plus positive/negative architecture checks |
| 3a | Application public-data boundary alignment | Co-located DTOs/handlers, public-data/private-service rules and regressions |
| 3b | CQRS/transactions | Shared HTTP/CLI use cases, validation and rollback |
| 4 | Layered events (historical; delivery superseded by 5b) | Retained event categories, public-data placement and optional Domain recording |
| 5 | Durable events (historical; superseded by 5b) | See historical task record |
| 5b | Native EventBus (accepted) | Immediate sync producer-transaction commit/rollback; global Doctrine switch; one-row atomic enqueue; current handlers, native retries/partial success, idempotency, worker crash/outage recovery and consumer isolation |
| 6 | Authenticating: web | Provisioning, login/logout, throttling and CSRF |
| 7 | Authenticating: JWT | Issuance, protected API identity, lifecycle and negative cases |
| 8 | Authorizing: model/management | Roles, direct scoped grants and permission decisions |
| 9 | Authorization enforcement | Entry-point enforcement, revocation and restricted administration |
| 10 | TaskTracking use cases/CLI | Create/list/complete, ownership and invariants |
| 11 | TaskTracking events | Completion activity through CQRS in both delivery modes |
| 12 | TaskTracking Twig | Real browser workflows with allowed/denied users |
| 13 | API Platform business adapters | JWT API workflows, validation, isolation and pagination |
| 14 | HTMX enhancement | Browser interaction and ordinary form fallback |
| 15 | Filesystem/Valkey cache | Provider switch and transaction-aware invalidation |
| 16 | Reusable initializer | New identity, rerun and interruption recovery |
| 17 | Distribution verification | Independent generated apps and demo-free initialization |

Task records include the approved design, security/performance review, exact
verification commands/results and independent review findings. Documentation and
checks evolve alongside implementation. Forge-independent commands are the current
CI interface; production deployment is a separate future scope.

Subtask 5b uses the same Application-only EventBus and ordinary listener attributes
in both modes. `EVENT_TRANSPORT_DSN` chooses `sync://` by default or
`doctrine://default` globally. Sync listeners share the producer transaction before
final flush; async listener commands own their usual roots. Required invariants use
explicit nested commands. Async consumers require module-owned idempotency; native
partial-success stamps do not provide exactly-once effects or global ordering.
See architecture for serializer/trust limits, queue compatibility and soft worker
limits, and [README](../README.md#switch-event-delivery-and-run-the-worker) for supported
shell exports and worker operations.
