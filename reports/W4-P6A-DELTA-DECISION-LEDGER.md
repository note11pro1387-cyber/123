# W4-P6A Delta Decision Ledger

| ID | Decision / change | Reason | Impact | Status |
|---|---|---|---|---|
| P6A-D001 | Keep the existing action names and server contracts where source inspection found them aligned; do not rename actions just to normalize spelling | Active JS consumers and router already agree for the scoped registration and Recovery actions | Avoids needless compatibility risk; detailed action map added | Accepted for P6A |
| P6A-D002 | Keep `verifyPasswordResetCode` as a fail-closed `410 RECOVERY_FLOW_UPDATED` route for now | The server deliberately closes the old combined OTP/password flow; no first-party `auth.js` consumer was found | Prevents old callers from regaining a weaker Recovery path; external legacy callers remain a release audit concern | Retain until E2E/release regression confirms no caller |
| P6A-D003 | Preserve actual HTTP success status and request ID in `apiFetch` compatibility results | Previous adapter synthesized `200` and null request ID despite server `201`/`202` and `meta.requestId` | Better HTTP semantics and error/request correlation without changing consumer envelope | Implemented and tested synthetically |
| P6A-D004 | Do not change Telegram webhook response to the browser API envelope | It is a server-to-server endpoint authenticated by Telegram's dedicated secret header | Keeps provider contract separate from browser CSRF/API consumer behavior | Retained |
| P6A-D005 | Defer direct `apiRequest` migration of all domain consumers to P6B/P6C | P6A stabilizes compatibility behavior first; broad consumer changes would mix UI and contract work | Smaller regression surface | Pending later explicit P6 parts |
| P6A-D006 | Keep DB/Auth integration marked unverified under owner-accepted deferment | `pdo_mysql` is absent in this environment | No DB test can be called PASS; exit 77 is recorded | Open release gate |
