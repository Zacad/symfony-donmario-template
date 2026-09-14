# Subtask 7 — Authenticating JWT

## Approval and status

**User approved implementation on 2026-09-13** with “i accept, proceed”, after
the discovery/design and the correction requiring `/api/me` to use the Application
query layer. **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-14.**
Full checks, actual HTTP/PostgreSQL E2E, fresh-consumer verification and two fresh
independent reviews passed on 2026-09-13. Subtask 6 remains
accepted, including registration-owned password-policy validation and hashing.
No commit/push is authorized.

**User acceptance — 2026-09-14 (exact message):**

> i accept, we will work on next subtask in fresh session

Next fresh session: **Subtask 8 — Authorizing: model/management DISCOVERY/DESIGN
ONLY**. Propose a bounded design, acceptance criteria and explicit security/performance
review, then obtain user approval before implementation. Subtask 8 has not started.

## Approved design

- Native Symfony JSON login at POST `/api/login`, accepting `{email, password}`.
  Separate stateless login and bearer API firewalls precede the web firewall.
  A fully authenticated thin controller issues through Lexik only after native
  authentication and all password-migration listeners finish; no early success
  handler issues tokens. Response: `{access_token, token_type: "Bearer", expires_in: 900}`.
- GET `/api/me` is an API Platform identity resource. Its provider takes the UUID
  from the authenticated principal and dispatches `GetAccountIdentityQuery` through
  QueryBus. The Application handler uses the Domain repository and returns only
  `GetAccountIdentityResult(id, email)`. The API resource is separate non-service
  UI data with a narrow inventory/DI classification. There is no public credential
  query/result. This supersedes the earlier proposed direct-principal projection.
- Reuse the email provider, native password hasher, internal AccountPrincipal and
  CAS hash-upgrade command. Add a UUID-oriented bearer provider using existing
  module-local Domain credentials after JWT validation. Domain/Application remain
  Security-independent. No LoginCommand or manual password authenticator.
- Installed LexikJWTAuthenticationBundle **3.2.0**, Lcobucci JWT **5.6.0** and API
  Platform Symfony **4.3.19** implement the approved integrations. Locked dependencies,
  adapted Flex recipes and container compatibility are verified. Native library cryptography only.
- RS256 with locally generated 3072-bit RSA keys, 900-second lifetime and zero clock
  skew. Claims: UUID sub, application/environment-specific fixed iss/aud, iat, nbf,
  exp. Issue nbf=iat and exp=iat+900; validate required normalized claims, UUID,
  issuer/audience and exact `nbf=iat`, `exp-iat=900` temporal relationships before
  account lookup. Emit typ JWT. Payload contains identity/lifecycle metadata, not email, password-derived
  values or business permissions. Library-normalized NumericDate representation is
  accepted; no stronger original-wire integer-type guarantee is claimed.
- Authorization Bearer header only, bounded to an 8 KiB token plus the 7-byte
  `Bearer ` prefix before parsing. Explicitly
  disable cookie/query/body extraction, token cookies and blocklisting.
- Live UUID account lookup on every valid bearer request; deletion rejects the next
  request. `/api/me` adds a second indexed read via its query. No disabled-account
  state currently exists. Password changes/rehashes do not invalidate existing JWTs.
- Access tokens expire after 15 minutes; renewal requires credentials again. No
  refresh token. Client logout discards the token; web logout is independent.
  Copied tokens remain replayable until expiry or signing-key removal.
- Same-origin SPA contract with tokens held in memory, no persistent browser token
  storage; page reload requires login. HTTPS outside loopback development.
- Dedicated project-specific persistent key volume, read-only to the app. Setup
  generates once and validates/retains on reruns. Separate initialization evidence
  beside local setup metadata distinguishes lost initialized keys. Partial/corrupt/
  mismatched keys fail safely. Development, test and consumer keys are independent.
  Owner-only local unencrypted PEM; no keys during image builds/cache compilation,
  normal requests or worker startup. Worker does not need signing material.
- Document/test explicit local stop/switch/start rotation. At most one old public
  key, with operator-managed overlap at most one token lifetime; no automatic
  retirement guarantee. Emergency rotation removes old trust when app restarts.
  Configuration and key material change consistently.
- Extend framed 16 KiB Caddy login protection, preserve front-controller alias
  rejection. Normalize JSON email before native throttling, sharing web/API limiter
  service/storage. Fixed API errors, no-store, no session creation/invalidation.
  Database/signing/late migration failures deliver no JWT. Extend secrecy canaries.

## Security/performance review

Bearer theft permits replay for the remaining lifetime; in-memory browser storage
does not protect against active XSS. JWT payloads are readable, not encrypted.
The existing native single-host limiter retains I/O/concurrency limitations.
No alternate token source or web-session fallback authenticates API requests.

Login costs password verification, possible rehash/CAS and one signature. Valid
bearer requests cost signature verification and one indexed UUID read; `/api/me`
costs a second indexed identity read through QueryBus. No password hashing/session
locking on bearer requests or account caching. At most two verification keys.
Actual HTTP observations are recorded below; they are not an SLA.

Discovery/design review confirmed native no-success-handler controller ordering.
It identified normalized-claim versus wire-type distinctions, JSON normalization
before limiter priority 2080, API-specific late-failure cleanup, independent key
initialization evidence, and operator-enforced rotation retirement. These are
incorporated above. Design review is not implementation approval/evidence.

## Acceptance and required evidence

1. Actual CLI provisioning → JSON login → bearer API Platform `/api/me`, Application
   query dispatch and documented OpenAPI contract.
2. Web/API firewall isolation, conflicting cookie/token identities, no API sessions.
3. JWT malformed/tampered/expired, algorithm/key/issuer/audience/claim/UUID/time negatives.
4. Expiry/re-login, deletion/email change, chosen password/logout semantics, rotation,
   cross-project/environment rejection.
5. Native rehash/CAS success, conflict/rollback and failures without JWT delivery.
6. Actual Caddy/input bounds, methods/content types, normalized combined throttling,
   unsupported token sources and persistence of limiter state.
7. Database/key outage/recovery and actual password/token/key secrecy canaries.
8. Key setup persistence/isolation/interruption, exact architecture classifications
   and existing regressions.

Required commands: `./bin/dev setup`, `./bin/dev check`, `./bin/dev test`,
`TMPDIR=/tmp/opencode ./bin/dev verify-setup`. Actual containers/PostgreSQL are
required, followed by fresh independent implementation review and user acceptance.

## Implementation and dependency notes

- Installed locks: `lexik/jwt-authentication-bundle` **3.2.0**, `lcobucci/jwt`
  **5.6.0**, `api-platform/symfony` **4.3.19**. Main reported 16 newly installed
  packages and runtime ext-openssl **8.5.9**. Composer explicitly requires ext-openssl.
  Flex recipe records are present for Lexik 2.5 and API Platform 4.0; application
  configuration adapts them to module-owned DTO resources and local-volume keys.
- Exact identity path: `UI/Api/AccountIdentityProvider` → QueryBus →
  `Application/GetAccountIdentity/GetAccountIdentityQuery` → co-located handler →
  Domain `AccountRepository::findIdentityById` / safe `AccountIdentity` →
  `GetAccountIdentityResult` → `UI/Api/AccountIdentityResource`. The resource alone
  has a narrow non-service UI classification; there is no query-projection exception.
  Account deletion between authentication and this second read returns 404.
- Public OpenAPI is exactly GET `/api/docs.json`, with a `security: false` firewall;
  even an invalid bearer header does not require authentication on this exact route.
  Browser documentation UIs, API entrypoint and Doctrine resources are disabled.
- Native Lexik configuration keeps `additional_public_keys: []`; the native
  `lexik_jwt_authentication.key_loader.raw` service instead receives its additional
  array lazily via Symfony env processors reading `/app/var/jwt/verification.json`.
  This avoids the configuration-tree dynamic-list incompatibility and compile-time
  keys. Issuer/audience use APP_INSTANCE_ID (Compose PROJECT_ID) and kernel environment.
- `./bin/dev jwt-keys initialize|validate|rotate|rotate-emergency|retire` wraps the
  native OpenSSL generation helper. `jwt_keys` is app/console read-only, isolated TEST
  runner read-only and absent from worker. `var/docker/jwt-initialized` independently
  matches volume `.identity`. Generation/symlink publication, retained-key validation,
  stopped-user switching and operator-managed retirement are documented in README,
  including interrupted first-init recovery and separate UID/GID ownership repair.

### Integration fixes and observable behavior

- Malformed NumericDate values (`null`, array and boolean) can raise a native
  Lcobucci 5.6 `TypeError` that Lexik 3.2's `Exception`-only catch does not handle.
  Narrow handling of errors originating in `Parser::convertDate` now returns fixed
  **401**, rather than 503, without adding a raw JWT parser. Actual HTTP negative
  cases verify the correction.
- Actual HTTP/PostgreSQL identity instrumentation verifies **exactly two account
  reads**, including an email change before the second read. `/api/me` returns the
  query's updated email rather than projecting principal email. The deletion race
  between authentication and the identity query has **unit coverage for 404**.
- Actual HTTP emergency rotation verifies that the prior token is **still unexpired**
  when rejected after old-key trust removal, then verifies new issuance works.
- Exact public documentation firewall behavior is verified with an invalid bearer
  header: `/api/docs.json` remains accessible without authentication.

### Completed verification (2026-09-13)

Main completed these final runs against the reviewed implementation/configuration:

| Command | Final result / evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained RSA3072 keys, dependencies unchanged, migration current, app/database healthy. |
| `./bin/dev check` | **PASS**: architecture **532 tests / 3289 assertions** + unit **163 tests / 1096 assertions** = **695 tests / 4385 assertions**. Deptrac **1246 allowed / 0 violations / 0 uncovered**. Audit, static analysis, style, lint, shell/key contracts and offline event fixtures passed. `var/test-runs/run-VU1J5W0M/`. |
| `./bin/dev test` | **PASS**: **201 tests / 4606 assertions**, all **38 PHPUnit phases**. `var/test-runs/run-Dk8kGEH0/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-tr7xoQb4/`; embedded **201 tests / 4607 assertions**, all **38 PHPUnit phases**, at `application/var/test-runs/run-KkpCT8uO/`. Two-instance cross-JWT isolation, hidden input, account/session/key persistence, development/test isolation and cleanup passed. |

These completed results supersede the earlier partial check that encountered a
subsequently fixed static-analysis error. Subtask 6's accepted evidence remains its
historical checkpoint, separate from these Subtask 7 runs.

Actual HTTP timing observations used three samples per operation locally: median
issuance **527.04 ms**, `/api/me` **21.27 ms**; the consumer observed **525.65 ms** /
**21.56 ms**, respectively. These are local observations, not an SLA or general
performance guarantee. Secrecy canaries establish the exercised paths only.

### Fresh independent reviews and user acceptance

- Authentication reviewer **`ses_f63a1e74affeszKsYM4RJDMnZS` — APPROVED**, with no
  concrete findings after inspecting code and evidence.
- Runtime reviewer **`ses_f63a1e72bffePxCpnh11QTnL9Q` — APPROVED**, with no concrete
  blocking findings or materially missing acceptance coverage after inspecting code
  and evidence.

Both reviews were fresh and independent; the reviewers inspected the completed
evidence and did **not** rerun the suites. No further code/configuration changes
followed these reviews. **User acceptance was recorded on 2026-09-14**, with the
exact message above. Verification and review dates remain 2026-09-13. Subtask 8
discovery/design is reserved for the next fresh session and requires its own
implementation approval. No commit/push is authorized.
