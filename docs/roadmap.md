# Approval-gated delivery

General design and Subtasks 1, 2, 3a, 3b and 4 have user acceptance. Subtask 2, including the
domain repository segregation correction, was accepted on **2026-09-11** after
implementation, container/PostgreSQL verification and independent review.

On **2026-09-11**, the user chose public command/query/result data beside handlers
in Application use-case folders, and approved the bounded alignment plan.
Subtask **3a** was accepted on **2026-09-11** after implementation, container/PostgreSQL
verification and independent review. Evidence is in [Subtask 3](tasks/03-cqrs-transactions.md).
Subtask **3b** was accepted on **2026-09-11** after implementation,
container/PostgreSQL/consumer verification and independent review, including
correction/reverification of two boundary findings.
**[Subtask 4](tasks/04-synchronous-events.md) — revised layered events, category-only
primitives and the opt-in recording refactor — is implemented, fully verified,
independently reviewed and accepted by the user.**
Next: separate Subtask 5 discovery/design. Its implementation is not yet approved.
See [the session handoff](handoff.md) for continuation context.
Later subtasks require their own discovery, design and approval before edits.
Split a subtask further if discovery reveals that its scope is too broad.

| # | Subtask | Main acceptance journey |
| --- | --- | --- |
| 1 | Repository/runtime foundation | Fresh Docker setup, HTTP, PostgreSQL, checks and isolation |
| 2 | Module/persistence boundaries | Boot and migrations plus positive/negative architecture checks |
| 3a | Application public-data boundary alignment | Co-located DTOs/handlers, public-data/private-service rules and regressions |
| 3b | CQRS/transactions | Shared HTTP/CLI use cases, validation and rollback |
| 4 | Layered synchronous events | Postcommit best-effort subscribers, independent listener transactions, retained producer success, FIFO/bounds and recovery; required nested commands remain atomic |
| 5 | Durable/optional async events | Separately designed outbox/atomic enqueue, delivery identity, worker, retry, crash and duplicate handling |
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

Subtask 4 supersedes the historical `Contract/Event` and precommit/full-subscriber-
rollback direction. Public events now live in `Application/<UseCase>`; Domain and
Infrastructure events stay internal. Primitives carry no metadata and current
delivery has no outbox. `EventObserving` is a disposable verification module, not
an additional production business module. See architecture for exact guarantees.
