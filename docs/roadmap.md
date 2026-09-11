# Approval-gated delivery

General design and Subtasks 1, 2 and 3a have user acceptance. Subtask 2, including the
domain repository segregation correction, was accepted on **2026-09-11** after
implementation, container/PostgreSQL verification and independent review.

On **2026-09-11**, the user chose public command/query/result data beside handlers
in Application use-case folders, and approved the bounded alignment plan.
Subtask **3a** was accepted on **2026-09-11** after implementation, container/PostgreSQL
verification and independent review. Evidence is in [Subtask 3](tasks/03-cqrs-transactions.md).
**Next: Subtask 3b CQRS/transaction design and implementation approval.**
See [the session handoff](handoff.md) for continuation context.
Later subtasks require their own discovery, design and approval before edits.
Split a subtask further if discovery reveals that its scope is too broad.

| # | Subtask | Main acceptance journey |
| --- | --- | --- |
| 1 | Repository/runtime foundation | Fresh Docker setup, HTTP, PostgreSQL, checks and isolation |
| 2 | Module/persistence boundaries | Boot and migrations plus positive/negative architecture checks |
| 3a | Application public-data boundary alignment | Co-located DTOs/handlers, public-data/private-service rules and regressions |
| 3b | CQRS/transactions | Shared HTTP/CLI use cases, validation and rollback |
| 4 | Synchronous events | Subscriber commands and full rollback on failure |
| 5 | Optional async events | Atomic enqueue, worker, retry, crash and duplicate handling |
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
checks evolve alongside the implementation. Forge-independent commands are the
current CI interface; production deployment is a separate future scope.
