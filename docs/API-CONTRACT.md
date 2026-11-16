# MR NEON API Contract — Effective Source / W5-P2 Continuation

**Status:** This document describes the effective reconstructed source, not a verified remote deployment. Wave 3 session/authentication routes remain active; W4-P2 persists temporary registration attempts and W4-P3 activates registration Challenge send/verify/resend. The customer project list and detail read actions (`getProjects`, `getProjectById`) are now active in the effective source with owner-scoped reads and independently paginated public timeline/messages. Actual Email/Telegram delivery and DB/Auth integration remain unverified in the owner's environment. Remote merge is not established by this document.

## Response envelope

### Success

```json
{
  "data": {},
  "meta": { "requestId": "server-generated-correlation-id" }
}
```

### Error (RFC 9457)

Content type: `application/problem+json; charset=utf-8`.

```json
{
  "type": "urn:mr-neon:problem:auth-required",
  "title": "Unauthorized",
  "status": 401,
  "detail": "برای ادامه وارد حساب شوید.",
  "code": "AUTH_REQUIRED",
  "requestId": "server-generated-correlation-id"
}
```

This follows the Master Handoff contract (`data` + `meta.requestId` and RFC 9457 errors). The lower-priority Wave 3 prompt's example envelope using `ok: true/false` was not adopted because it conflicts with the controlling Handoff contract. Request IDs are generated server-side; arbitrary client-supplied IDs are not trusted.

## Routes currently active

| Action | Method | Authentication | CSRF | Result |
|---|---|---|---|---|
| `getSession` | GET | Public session bootstrap; any existing principal is revalidated | No, read-only | DB-backed IP-limited (default 60/60s); returns authenticated state, allowlisted user/admin DTO, CSRF token and absolute expiry |
| `login` | POST | Public auth flow | Required | User password verification, active-account check, DB-backed rate limiting, session/CSRF rotation |
| `adminLogin` | POST | Public admin auth flow | Required | Separate configured admin identity, password hash + TOTP, replay protection, DB-backed rate limiting |
| `logout` | POST | Guest or authenticated session | Required | Revokes authenticated registry entry when applicable, destroys prior session and creates an anonymous CSRF session |
| `register` | POST | Public; anonymous session required for CSRF, no authenticated account assumed | Required | Validates canonical registration data and creates/reuses a pending `registration_attempts` record only; returns HTTP 201 for new attempt or 200 for idempotent reuse. Never creates a user or verification challenge and never claims OTP delivery |
| `sendVerificationCode` | POST | Public; `attemptId` is a high-entropy server-issued reference, not authentication | Required | Generates a six-digit OTP in memory; uses the configured transport contract; only `accepted` can create an active registration Challenge. Default transport returns `VERIFICATION_DELIVERY_NOT_CONFIGURED`. Response never contains the OTP |
| `resendVerificationCode` | POST | Same registration Attempt and selected server-owned channel/destination | Required | Reuses Attempt, enforces DB-backed limits/cooldown, and supersedes the prior Challenge only after the replacement delivery is accepted. Reject/unknown preserves the old Challenge; an unresolved `prepared` ledger blocks blind retry |
| `verifyRegisterCode` | POST | Public verification of a Challenge bound to the Attempt, Purpose, channel and canonical destination | Required | Atomically consumes a valid unexpired Challenge and Attempt while creating the User and selected verified User Channel; successful completion does not create an authenticated session |
| `getProjects` | GET | Authenticated customer session | No; read-only | Owner-scoped project list; accepts bounded `page`, `perPage` and allowlisted `status`; returns `projects`, `pagination` and owner-only `stats` |
| `getProjectById` | GET | Authenticated customer session | No; read-only | Resolves a 32-character public project reference for the authenticated owner; returns safe project detail, owner-scoped milestones, public-only timeline and owner-scoped messages. `timelinePage` and `messagesPage` independently page history, each at 50 items per page, with `page`, `perPage`, `total` and `hasMore` metadata |
| `saveNeo` | POST | Authenticated customer session | Required | Requires `Idempotency-Key`; accepts the existing NEO payload and returns HTTP `201` with canonical `data.projectId` (32-character public project ID, never the numeric DB ID), `trackingCode`, `title`, `status: "submitted"`, and UTC `createdAt`. Same key/payload replays the canonical response; ambiguous outcomes are not success. |
| `sendMessage` | POST | Authenticated customer session | Required | Requires `Idempotency-Key`; creates a customer message, audit event and replayable canonical 201 response in one transaction. Same key + same normalized payload replays; same key + different payload returns 409 |

| `getAdminUserDetail` | GET | Authenticated admin session; role and rate limit enforced server-side | No; read-only | Resolves a 32-character public user reference. Returns a credential-free user projection, channel verification state, up to 50 minimal project cards, up to 30 recent project messages, up to 20 safe session-metadata records and up to 30 account-subject audit events. It never returns password hashes, raw session hashes/IDs, reset/verification tokens, provider subject IDs, IP hashes, user-agent hashes or audit metadata JSON. Internal notes are not part of the message list. |

## Canonical NEO project receipt (W6-P12)

- The existing `saveNeo` response field names remain unchanged. Its receipt mapping is `projectId` → public `projects.public_id` (32 lowercase hexadecimal characters), `trackingCode` → canonical tracking code (`MN-` plus 12 characters from the server allowlist), `title` → canonical persisted project title, `status` → server-owned state `submitted`, and `createdAt` → server UTC timestamp in `YYYY-MM-DDTHH:mm:ssZ` form. `projectId` is not an internal numeric database ID.
- The client may declare the submission successful and clear the applicable Draft only after HTTP `201` and validation of all five fields above. Any missing, malformed or unexpected status/timestamp leaves the outcome unresolved: preserve the exact pending idempotency key/payload and Draft, and do not open a success receipt.
- `window.lastSubmittedProject` is built from an explicit allowlist of those fields in the API response only. Never merge submitted form fields, Brief, labels derived in the browser, or profile data into the receipt. The receipt displays only the public project ID, tracking code, canonical title, canonical status label and canonical creation timestamp; no customer contact details, Brief fields, internal IDs, public tracking URL or QR/barcode.
- Receipt text is constructed with DOM nodes and `textContent`; untrusted values are never interpolated into receipt `innerHTML`. Copy reports success only after clipboard completion. Share uses canonical receipt fields and has a clipboard fallback; it does not invent a public receipt/tracking URL. Image download uses the local bundled renderer and reports failures without changing project state. Print is scoped to the active receipt only; no other page content should be printed.
- Receipt action lifecycle: Share, PNG download and Print prevent concurrent activation of the same action and restore each button's prior disabled/`aria-busy` state when the operation settles. Share confirmation reports that content was handed to the native share sheet, not that a recipient received it; cancellation remains non-error, and unsupported/failed native sharing falls back to copying the canonical receipt text. No absolute URL is appended while `MRNEON_PUBLIC_BASE_URL` is unset; the browser Origin is not a substitute.
- PNG export snapshots the canonical receipt and its rendered DOM at click time before loading the local renderer. The exported image excludes modal-only chrome (outer brand/title header, action footer and close control) and retains the receipt's own canonical header, matching the content boundary used by Print. The output filename is derived from the same click-time canonical tracking-code snapshot. The temporary export clone is removed on success and failure, and its object URL is revoked.
- Print cleanup is idempotent and runs on the browser's `afterprint`/print-media exit signal, a synchronous print failure, or explicit modal close. It removes the receipt-only body mode and its listeners and restores the Print button state. This is source-level behavior; real browser print/download/share capability remains subject to the deferred final QA phase.

## NEO custom-color contract (effective source)

- `saveNeo` keeps the existing payload shape `colors: { palettes: string[], customColors: string[] }`. The server requires at least one curated palette or custom color. `customColors` may contain at most four values.
- Each custom color must be exactly six hexadecimal digits with a leading hash (`#RRGGBB`). The DTO trims surrounding whitespace, canonicalizes accepted colors to uppercase, and rejects duplicate colors case-insensitively after canonicalization. Color names, `#RGB`, alpha `#RRGGBBAA`, `url()`, CSS/HTML fragments and other free-form values are rejected. Alpha is not enabled because the current form has no defined transparency use case.
- The browser uses a native `input[type=color]` as the primary picker and mirrors its value to a strict HEX field. Client validation is for usable feedback only; the DTO remains authoritative and returns `422 INVALID_PROJECT_COLOR` or `422 DUPLICATE_PROJECT_FIELD` as applicable. The payload keys and database schema are unchanged.

## NEO contact-step validation (effective source)

- The contact-step browser preflight mirrors the active `ProjectCreationDTO` bounds: `fullName` is required and limited to 160 Unicode code points; `phone` is required and accepts Iranian mobile formats `09xxxxxxxxx`, `+989xxxxxxxxx`, or `00989xxxxxxxxx` after the same Persian/Arabic digit and common-separator normalization; `email` is optional, limited to 254 Unicode code points and checked with the native email input validity; `messengerId` is optional and limited to 120; `userNote` is optional and limited to 2,000.
- The UI also rejects the same disallowed control-character ranges as the DTO before moving beyond the contact step. Help text is associated with its control through `aria-describedby`; errors focus the offending control and set `aria-invalid` until the user edits a field.
- These checks are an early usability guard only. `ProjectCreationDTO` remains the sole authority for payload type, canonicalization, length and validity; the browser never mutates or trusts contact values as canonical and the API contract/payload shape is unchanged.

## NEO draft persistence and session-expiry contract (effective source)

- The wizard is memory-first. Draft data is not written to Web Storage unless the user explicitly enables **“ذخیرهٔ موقت برای ادامه پس از تازه‌سازی”** in the NEO step UI. The preference is visible again on resumed drafts and can be revoked at any step; revoking it attempts to remove the same-tab saved copy while the current page's in-memory form remains intact. If the browser refuses removal, the UI states that deletion could not be confirmed instead of claiming success.
- The optional persistent copy lives only in same-tab `sessionStorage`; it expires after 24 hours without a draft update and is also subject to the browser's same-tab lifetime. The stored record has a schema version, user scope, save time and absolute expiry. Expired, malformed, oversize or cross-user records are removed and not resumed. Legacy records from versions that saved without explicit consent may be restored in memory for the current visit only after their persistent copy can be removed; if storage removal fails, the record is not resumed.
- The storage layer copies only allowlisted NEO form keys, bounded values, step index, and the generated idempotency key/creation time/exact pending submission payload where needed for an ambiguous submission. It does not serialize arbitrary object properties, session cookies, authentication tokens, passwords, OTPs or provider secrets. Contact details and the user note are still personal data: the UI discloses that they are included when persistence is enabled.
- On opening NEO and immediately before submitting, the browser revalidates the server-side session through `getSession`. If the client session revision changes while this request is in flight (for example, another login/logout or restore completes), the late response cannot overwrite the newer principal; the operation fails closed and requires an explicit retry. The `getActiveProjectsCount` endpoint and `saveNeo` controller remain server-authoritative. A missing/expired session stops submission, preserves the in-memory draft (and the opted-in persistent copy, including its pending idempotency record), clears stale client session state and directs the user to login. A 401 from `saveNeo` follows the same path and must not discard the pending idempotency key/payload or downgrade the request to an anonymous/guest project. If session verification is unavailable, no submit is sent. A different active account cannot submit a draft started under another user scope; when a principal switch is detected, the prior form is closed and its in-memory personal data cleared from the visible wizard, while any opted-in saved copy stays scoped to the original user's storage key.
- Draft cleanup is attempted only after an HTTP `201` response and the expected canonical project receipt fields have both been returned. Validation rejection may clear the pending submission attempt's idempotency tuple so the corrected request can be attempted, while preserving the current form data. Network/timeout/5xx/409 ambiguity preserves the exact key and payload for safe retry. If the browser refuses to remove a known opted-in saved copy after confirmed success, the UI warns and does not claim deletion. These are client workflow safeguards; they do not replace server authorization/session checks.

## Core rules

- `api.php` is same-origin. It emits no reflective CORS headers.
- Mutations use `X-CSRF-Token`; the token is bound to the PHP session and is rotated on authentication changes.
- The API client uses `credentials: same-origin`, JSON requests, a bounded timeout, no automatic retry, and validates both HTTP status and envelope.
- Customer project reads are not authorization by obscurity: the session role is checked by the controller and the owner ID is included in each project, milestone, timeline and message query. Timeline queries expose only allowlisted lifecycle events and never return event metadata, audit logs, outbox payloads or internal notes.
- Project detail responses bound timeline and message page sizes to 50. Their pagination metadata is required by the customer UI; transport/API errors remain separate from successful empty arrays. The totals reflect only records visible to that owner and, for the timeline, only the allowlisted public event types. Each timeline/message item includes a stable SHA-256 `itemKey` derived from the project public reference, collection label and row identity for UI deduplication; it is not an authorization credential, and the numeric internal row ID is not returned.
- `sendMessage` requires a client-generated `Idempotency-Key` header (16–128 ASCII letters/digits/period/underscore/colon/hyphen). The server scopes it to authenticated user + target project + endpoint and fingerprints the normalized project reference and trimmed message text. Message insertion, its audit event, and the canonical 201 response record are committed in one database transaction. Repeating the same key and identical payload replays the original canonical response with `Idempotency-Replayed: true`; the same key with a different payload returns `409 IDEMPOTENCY_KEY_REUSED`. A stored `processing`/unreadable result is not treated as success. The browser retains the key in memory while the result is ambiguous and blocks editing that pending text until the same payload is retried. This prevents duplicate rows for retries within the retained idempotency-record lifetime; it does not assert exactly-once external delivery or cover a lost key after page unload. The project/message reservation row has a nominal 24-hour `expires_at`; Telegram update idempotency uses its longer update-specific retention. `find()` returns unexpired rows and keeps expired `processing` rows visible so they fail closed instead of appearing absent. `reserve()` is permitted only inside the transaction that contains the business mutation. On reservation, only an expired `completed` or `failed` row for that exact scoped key may be reclaimed; an expired `processing` row is never automatically removed and fails closed for operator review. The customer message UI and NEO Studio stop blind retries after a 22-hour safety window and require explicit confirmation after the user checks project/message history before rotating to a new key. This is a user-facing reconciliation safeguard, not an authorization control and not a guarantee against clock skew. `bin/cleanup-idempotency-keys.php` is dry-run by default and can delete a single bounded batch of expired `completed`/`failed` rows only when the explicit cleanup gate, QA/owner-approval acknowledgement and exact database confirmation are supplied (with a separate production-only gate). It is not scheduled or run by this change; owner-host scheduling and database/QA approval remain blocked.
- `401` means missing/invalid authentication; `403` means policy denial; `404` means unknown/unimplemented action; `405` includes `Allow`; `413`, `415`, `422`, `429` and `503` are used where appropriate.
- Unexpected exceptions become a safe `503` on auth services and are logged with request ID plus exception type only. No SQL, DSN, stack trace or credentials are returned.
- `register` only persists a bounded-lifetime pending attempt and returns `verificationStatus: not_started`. W4-P3 makes Challenge endpoints active but does not provide a production sender: the default transport fails with `VERIFICATION_DELIVERY_NOT_CONFIGURED`. An active Challenge is created only after an injected transport returns a typed `accepted` result. The OTP lifetime starts at this acceptance timestamp and is exactly 120 seconds. The legacy JSON-backed OTP send/verify helpers and direct legacy send/resend handlers are disabled; legacy 600-second OTP records cannot verify through those helpers.
- The old JSON rate-limit file is not mutated by disabled legacy actions. Active user/admin login rate limits use Wave 2's DB-backed bucket repository and fail closed when that dependency is unavailable.

## Registered action inventory

See `WAVE-3-ACTION-MATRIX.csv` in the Wave 3 report bundle for every Action registered in `MethodPolicy`, expected HTTP method, permission and CSRF requirement, consumer, current status and verification coverage.

## Stable error code families

| Code | Meaning |
|---|---|
| `AUTH_REQUIRED` | No valid server-side session |
| `FORBIDDEN` | Authenticated principal lacks required role |
| `CSRF_VALIDATION_FAILED` | Missing or invalid session-bound CSRF token |
| `CROSS_SITE_REQUEST_REJECTED` / `ORIGIN_REJECTED` | Cross-site request signals rejected |
| `INVALID_PROJECT_COLOR` | A NEO custom color is not exactly `#RRGGBB`, or the custom-color list exceeds four entries |
| `DUPLICATE_PROJECT_FIELD` | A repeated value appears in a list where duplicates are forbidden, including canonicalized NEO custom colors |
| `INVALID_CREDENTIALS` | Generic customer-login rejection; does not disclose account existence |
| `INVALID_ADMIN_CREDENTIALS` | Generic admin-login rejection |
| `RATE_LIMITED` | DB-backed login/registration policy exceeded; `Retry-After` is provided |
| `REGISTRATION_CONFLICT` | Generic identity or active-attempt conflict; does not identify which account/field caused the conflict |
| `VERIFICATION_CHANNEL_INVALID` / `EMAIL_REQUIRED_FOR_CHANNEL` | Registration channel is not Telegram/Email, or Email was selected without a valid Email address |
| `PASSWORD_POLICY_VIOLATION` | New-registration password does not satisfy centralized 15-character policy |
| `TERMS_POLICY_UNAVAILABLE` | A server-owned approved Terms/Privacy version is not configured; registration fails closed |
| `REGISTRATION_UNAVAILABLE` | Attempt persistence or a required dependency failed; no success is asserted |
| `OTP_SECURITY_NOT_CONFIGURED` | Dedicated server-side OTP HMAC key is absent/too short; generation/verification fails closed |
| `VERIFICATION_DELIVERY_NOT_CONFIGURED` / `VERIFICATION_DELIVERY_REJECTED` / `VERIFICATION_DELIVERY_UNKNOWN` | External delivery is not configured, rejected or ambiguous; no active challenge or successful delivery is asserted |
| `VERIFICATION_DELIVERY_RECONCILIATION_REQUIRED` | A prepared/ambiguous delivery cannot safely be retried automatically |
| `LEGACY_OTP_DISABLED` | Deprecated JSON-backed OTP send path is fail-closed; use the database Challenge API |
| `VERIFICATION_CODE_EXPIRED` / `INVALID_VERIFICATION_CODE` / `VERIFICATION_TOO_MANY_ATTEMPTS` | Challenge is expired, code did not match, or attempt cap was reached |
| `VERIFICATION_RESEND_COOLDOWN` / `VERIFICATION_RESEND_LIMIT` | Central resend cooldown or delivery cap was exceeded |
| `AUTH_SERVICE_UNAVAILABLE` / `ADMIN_AUTH_UNAVAILABLE` | Authentication dependency is unavailable; no success is asserted |
| `IDEMPOTENCY_KEY_REQUIRED` | A mutating request that supports replay safety omitted or malformed its idempotency key |
| `IDEMPOTENCY_KEY_REUSED` | A previously reserved key is being reused for a different canonical payload |
| `IDEMPOTENCY_IN_PROGRESS` / `IDEMPOTENCY_RESULT_UNAVAILABLE` | A previous keyed mutation has no safely replayable canonical result yet |
| `ACTION_NOT_IMPLEMENTED` and action-specific `*_UPGRADE_REQUIRED` | Registered legacy route remains intentionally closed pending its owning wave |

## Remaining contract work

W4-P2 changes only the `register` action. The central browser `apiFetch` adapter wraps the standard `data/meta` response into its legacy `{success, data}` shape for the existing registration consumer. Registration does not create a user, user channel, challenge, OTP, or external delivery. W4-P3 creates Challenges only after a typed accepted Transport result; no real sender is bundled. Email starts work immediately once a W4-P4 provider is configured. Telegram still requires the Attempt to have a securely linked Telegram user/chat identity; a username alone is insufficient. No database Integration is claimed by Unit/Static results, and the test runtime currently lacks `pdo_mysql`. The registration and password-recovery interfaces now communicate the server-owned minimum of 15 Unicode characters (W4-P6). The registration interface also exposes the 1,024-byte UTF-8 ceiling and the well-formed-Unicode/control-character constraints. Browser-side validation is advisory presentation only: registration and password reset are both enforced by the centralized server-side `PasswordPolicy`; login compatibility for existing accounts is unchanged. Terms/Privacy is not approved by the client: `MRNEON_TERMS_VERSION` must be supplied by server configuration, otherwise registration returns `TERMS_POLICY_UNAVAILABLE`.

## W5-P2-P4 — Admin notification inbox (effective-source contract)

- `GET api.php?action=getAdminNotifications&page=1&perPage=25` requires an authenticated admin session for authorization. Separately, the server scopes every inbox query to the exact stable recipient ID configured as `MRNEON_ADMIN_INBOX_ID`; this opaque recipient identifier is independent of the mutable login username/password/TOTP. Request parameters cannot select another recipient. Response contains `notifications`, `pagination` (`page`, `perPage`, `total`, `pages`, `hasMore`) and `unreadCount`.
- `POST api.php?action=markAdminNotificationRead` requires an authenticated admin session, same-origin CSRF validation, and a 32-character lowercase hexadecimal public notification ID. The update is scoped to both the notification public ID and the authenticated admin principal. A notification owned by another recipient is reported as not found.
- Project-created and customer-message in-app notices are recorded in the same transaction as their underlying project/message mutation when a valid stable `MRNEON_ADMIN_INBOX_ID` is configured. If that server setting is absent or invalid, no synthetic recipient is invented; the in-app admin notice is omitted and this configuration gap remains an environment/deployment item.
- These endpoints only read/write the in-app database inbox. No Email/Telegram delivery is performed by these requests. Outbox delivery remains separately queued and requires a separately authorized worker/environment.
- Tests remain `NOT RUN BY OWNER DIRECTIVE` until Implementation Freeze. This documentation describes the intended source contract; it is not runtime/DB integration evidence.

## Wave 5 lifecycle and administrative CSV (effective source contract)

- `adminUpdate` status transitions to `needs_information` require a 1–500 character customer-visible message. That message is stored only on the corresponding `project.status_changed` project event and is exposed to the customer timeline only when the target status is `needs_information`; audit metadata and internal notes remain private.
- Completing a project completes any remaining project milestones within the same transaction and stores progress as 100, avoiding a canonical `completed` state with a non-complete progress value.
- `exportProjectsCsv` is a GET action guarded by the existing admin session and rate-limit layers. Filters (`q`, `status`, `projectType`, exact owner `user`, and exact creation `date`) are applied server-side and match the visible project list filters. Export is capped at 5,000 rows and rejects overflow rather than silently truncating; CSV cells are formula-neutralized before CSV quoting. Empty filtered results return a problem response. Export preserves the selected allowlisted sort/direction from the visible project list. Export generation is audited without logging search text or personal data.
- CSV export is an administrator-only report and contains the customer brief note and latest internal note; it does not expose credentials, session data, or raw persistence IDs.

### Admin settings summary (W6-P6)

- `GET api.php?action=getAdminSettingsSummary` is admin-session protected and read-only at the business-data layer. It returns only boolean configuration presence/shape flags, a whitelisted environment label, and a UTC generation timestamp. It never returns Secret values, host names, database names, credentials, webhook URLs, or provider responses.
- The API path still uses the existing DB-backed admin authorization and rate-limit infrastructure; no external provider request or settings mutation is performed.
- `emailConfigurationComplete`, `telegramBotTokenFormatValid`, `telegramUserLinkPrerequisitesPresent`, and `termsVersionValid` are static configuration-shape checks only; `termsVersionValid` does not mean legal text/version approval. They do not prove live connectivity, provider acceptance, webhook registration, or delivery.
- SMS remains disabled under the approved product contract. Recovery Codes or equivalent admin MFA readiness remain a separate release-gate obligation.

### Administrative list filtering and sorting (W6-P2)

- `getAll` keeps bounded `page`, `perPage`, free-text `q`, and allowlisted `status`/`projectType`; adds exact `user` matching against the owner's username or public ID and exact `date=YYYY-MM-DD` matching against project creation time stored in UTC (the selected calendar day is interpreted as UTC).
- `getAllUsers` keeps bounded `page`, `perPage`, free-text `q`, and allowlisted account `status`; adds exact `date=YYYY-MM-DD` filtering by account creation day in UTC.
- `getAll` accepts `sort` from the fixed allowlist `created_at`, `updated_at`, `tracking_code`, `status`, `owner_name`, `progress`; `getAllUsers` accepts `created_at`, `last_activity` (last login, falling back to account creation), `name`, `username`, `account_status`, `project_count`. Both accept `direction=asc|desc`.
- Dates must be exact valid Gregorian calendar dates; invalid dates return `400 INVALID_DATE_FILTER`. A blank filter means no date/owner constraint.
- Repository filters use prepared statement values. Sorting maps semantic keys to fixed server-side SQL expressions; client-supplied SQL identifiers are never concatenated. Ordering includes a stable ID tie-breaker, and sorting/filtering occurs before `LIMIT/OFFSET`, so it covers the filtered server dataset rather than only the browser's current page.
- Existing admin-session role authorization and request rate limiting remain required. This list API exposes no password hashes, reset tokens, session IDs or authentication secrets.
- `adminUpdateUser` requires `id`, `status` (`active|suspended`) and a 1–500 character `reason`; server-side validation is mandatory even when the UI is bypassed. Account state change, session revocation on suspension and audit record are performed transactionally. Reason is stored only in the protected audit record, not broadcast to the customer or in-app notification inbox.
- Tests remain intentionally not run until Implementation Freeze.


### Admin user dossier (W6-P3)

- `GET api.php?action=getAdminUserDetail&id=<32-character-lowercase-public-id>` is dispatched through `AdminProjectController`, requires `AdminAuthMiddleware`, and uses a dedicated DB-backed rate-limit scope. The public reference is validated before lookup; numeric internal user IDs are not accepted as the route reference.
- The response is an explicit allowlist projection: account fields, channel type/value/verification state, bounded project cards, bounded recent project conversation messages, safe session lifecycle metadata and bounded account-subject audit action summaries. Repository queries do not select password hashes, session hashes, reset/verification secrets, provider subject IDs, audit IP/UA hashes or audit metadata.
- Project cards use a dedicated minimal repository query and do not decode or return the complete project Brief. Project messages come only from `project_messages`; `internal_notes` remain a separate private domain and are not joined. Message bodies are bounded to 1,200 characters in the dossier response.
- Session history reports timestamps, revoked state and a bounded revocation reason only. It never returns session identifiers, hashes, cookie values, user-agent data or raw credentials. Audit history reports actor type, action and timestamp only.
- `admin-user.php?id=<public-id>` is the query-mapped dedicated dossier route for hosts without configured pretty URLs. It is noindex and renders no user data server-side; all business data remains behind the authenticated API. Missing/invalid IDs redirect to the admin workspace without data lookup.
- Tests remain `NOT RUN BY OWNER DIRECTIVE`; this contract and source implementation are not runtime authorization or DB integration evidence.


### Admin message inbox (W6-P4)

- `GET api.php?action=getAdminMessages&page=1&perPage=25&q=&sender=all&read=all` requires the existing authenticated admin session and server-side admin role. The endpoint is dispatched only through `AdminProjectController`; `MethodPolicy` fixes the HTTP method to GET and `RateLimitMiddleware` applies a dedicated `admin_messages_read` scope. It does not use browser API keys or introduce a second authorization path.
- `page` is bounded to a positive integer; `perPage` is bounded to 1–50; `q` is limited to 100 Unicode characters; `sender` is one of `all|user|admin|system`; `read` is one of `all|unread|read`. Unknown enum values and malformed paging/search values fail closed. Search terms are bound SQL parameters, and `%`/`_` are treated literally. Sort expressions are not accepted from the client; the inbox has a fixed newest-first order with a stable message-ID tie-breaker.
- The response contains a safe `messages` DTO, `pagination` (`page`, `perPage`, `total`, `pages`, `hasMore`) and a global `unreadCustomerCount`. Message DTOs include project references needed by the authenticated admin UI, customer display name/username, sender type, bounded 1,200-character body preview with `bodyTruncated`, timestamp, status/archive indicators and read metadata. Message sender IDs, credentials, session material and raw database row objects are not returned.
- The repository query reads only `project_messages`, `projects` and `users`; it does not join `internal_notes`, audit metadata, outbox payloads or notifications. Internal notes are deliberately absent from both this inbox and the message preview. Opening a conversation reuses `getAdminProjectDetail` and `markAdminMessagesRead`; the latter is an authorized POST mutation protected by the existing CSRF/auth/rate-limit layers. UI read-state changes occur only after the server confirms the receipt.
- The direct route is `admin-messages.php`, noindex and query-mapped in the current PHP stack. The route itself renders no business rows server-side; the session is restored and message data is requested through the protected API. Pretty URL behavior remains host-dependent and is not asserted here.
- Tests remain `NOT RUN BY OWNER DIRECTIVE`. No database connection, migration, write, provider call, deployment, commit or remote branch mutation was performed for W6-P4. Source contract alignment does not establish live schema/session/browser behavior or release readiness.


### Admin audit timeline (W6-P5)

- `GET api.php?action=getAdminAuditLog&page=1&perPage=25&q=&actor=all&subject=all` requires the existing authenticated admin session and server-side admin role. It is dispatched through `AdminProjectController`, constrained to GET by `MethodPolicy`, and has its own `admin_audit_read` rate-limit scope.
- `page` is a positive integer, `perPage` is bounded to 1–50, `q` is limited to 80 Unicode characters, `actor` is `all|user|admin|system|anonymous`, and `subject` is `all|user|project|registration_attempt|other|none`. Unknown enum values and malformed query values fail closed. Search is applied only to the `action` field with bound parameters and literal wildcard escaping.
- The response is an allowlisted `events` DTO (`actorType`, `action`, `subjectType`, `createdAt`) and standard `pagination`. Queries do not select `actor_id`, `subject_id`, `request_id`, `ip_hash`, `user_agent_hash` or `metadata_json`; the UI cannot display or mutate them. The page is read-only and no audit mutation route is added.
- `admin-audit.php` is a direct-load noindex route. The route shell embeds no audit records; after server-session restoration the authenticated API is the only source of event data. Actual host routing, database schema compatibility and browser behavior remain unverified until authorized QA.
- Tests remain `NOT RUN BY OWNER DIRECTIVE`. Source implementation and package integrity do not establish runtime authorization, DB integration, or release readiness.
