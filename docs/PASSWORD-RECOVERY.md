# Password Recovery — W4-P5

## API contract

All operations are same-origin `POST` requests, use the existing API envelope and session/CSRF middleware, and are `Cache-Control: no-store` through `ApiResponse`.

### 1. Begin

`POST api.php?action=forgotPassword`

```json
{"identifier":"person@example.com","method":"email"}
```

For `method="email"`, the identifier is canonicalized to lowercase and must match an already verified Email bound to an active user. For `method="telegram"`, the identifier is the MR NEON account username (optional one leading `@` for display compatibility), not a Telegram username or Chat ID; the transport destination is the verified Chat ID from the user-channel row. Recovery rejects inconsistent private-chat records unless the numeric Chat ID matches the stored Telegram provider subject ID established by the secure `/start` linking flow. `sms` is unsupported.

For syntactically valid identifiers, the success envelope is generic and does not include a `found` flag, account ID, destination, or channel list:

```json
{"data":{"challengeRef":"<32 lowercase hex chars>","message":"<generic recovery message>","ttlSeconds":120},"meta":{"requestId":"<request id>"}}
```

`challengeRef` is a stable keyed pseudonymous reference for rate limiting and linking stages. It is not a DB challenge ID and grants no authorization by itself. A configured Transport must actually accept delivery before an active Challenge is persisted. `rejected`, `unknown`, and `not_configured` do not create a usable OTP Challenge; `unknown` is fail-closed and blocks blind retry for that account pending reconciliation.

### 2. Verify OTP

`POST api.php?action=verifyResetCode`

```json
{"identifier":"person@example.com","method":"email","challengeRef":"<reference from start>","code":"012345"}
```

The backend independently resolves the target and verified channel, checks the reference, current active `password_reset` Challenge, destination binding, state, expiry and attempt ceiling, then compares the stored context-bound OTP HMAC. Registration OTPs are not accepted for Recovery.

Success returns a 256-bit reset-only capability exactly once. It is not a login or session token:

```json
{"data":{"resetAuthorizationToken":"<64 lowercase hex chars>","expiresInSeconds":600,"passwordResetAllowed":true},"meta":{"requestId":"<request id>"}}
```

### 3. Reset password

`POST api.php?action=resetPassword`

Header: `X-Reset-Authorization: <reset-only token>`  
Body:

```json
{"newPassword":"<new password>"}
```

The body must not contain a user ID, account selector or token. The token is never accepted from URL/query parameters. The central PasswordPolicy validates the new password; reset-token consumption, password hash update and session registry revocation run within the same database transaction. Success means password reset only:

```json
{"data":{"passwordReset":true,"authenticated":false,"sessionEstablished":false,"message":"<login again message>"},"meta":{"requestId":"<request id>"}}
```

If the current browser session belongs to the reset account, the controller destroys that local session after the transaction commits and returns a fresh CSRF token. The Recovery flow does not sign the user in.

## Account-enumeration controls

The valid-input start path returns the same HTTP status and generic message for known/unknown accounts and never includes a `found` field. Rate limits are applied by IP and a keyed normalized-identity bucket. Responses have a one-second minimum time floor; this is only partial mitigation, not a constant-time guarantee. Since the existing Transport sends synchronously, an account that triggers a real provider call may take longer than an unknown account. Eliminate that residual timing channel only with a reviewed durable/asynchronous delivery design that does not persist plaintext OTPs; that redesign was not silently introduced here.

## Rate limits and contention

The flow uses the existing DB-backed `RateLimitMiddleware` with the existing rate-limit HMAC key. Start and user operations use bounded MySQL named locks. Lock contention returns a controlled conflict or a generic start response; it never opens a parallel active Recovery Challenge. A delivery with an `unknown` result does not get an automatic resend. Operators need to reconcile such a record before allowing another delivery.

## Legacy path

`verifyPasswordResetCode` is retained only as a fail-closed compatibility action and returns `RECOVERY_FLOW_UPDATED` (HTTP 410). Legacy JSON-backed handlers also return the same closed-flow error. The UI now uses three stages: request, OTP verification, then password reset.
