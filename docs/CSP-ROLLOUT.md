# MR NEON CSP Report-Only rollout preparation

## Current first-party DOM sink review — W7-P33

The following is a source-level inventory of first-party JavaScript modules after W7-P33. Counts represent assignment statements, not vulnerability counts. Static UI templates, escaped plain-text interpolation, and safe DOM construction are not equivalent risk classes.

| Module | `innerHTML` assignments | Reviewed boundary |
|---|---:|---|
| `js/app.js` | 2 | The public Portfolio detail template escapes every text/attribute interpolation; image paths are restricted to local `assets/images/` paths with the existing local fallback, and Demo navigation is restricted to absolute HTTPS URLs on `demo.mrneon.dev`. The other assignment clears the modal with the fixed empty string. Toasts no longer build markup from strings. |
| `js/auth.js` | 14 | Customer/registration/notification/project output retains context-specific escaping. Customer response DTOs are validated before state replacement/render (W7-P31); customer messages remain plain text. |
| `js/admin.js` | 27 | Project/user list and activity-list DTOs are validated before rendering (W7-P29/P30); project/user detail DTOs and their nested history/message/session/note/milestone collections are now validated against the PHP controller/repository/schema contracts (W7-P34). Existing dynamic text and attribute output uses the module's HTML escaper. |
| `js/neo.js` | 5 | NEO step/catalog templates retain context escaping; catalog ingress is bounded/typed (W7-P32); SVG paint values remain strict `#RRGGBB`. |

W7-P33 removed the first-party Toast `innerHTML` construction entirely: icon, title, message, close control and SVG path are created as DOM nodes; message content is assigned with `textContent`, and Toast type is closed to `success`, `error`, `warning`, or `info`. Portfolio Demo URLs and image paths are validated before insertion into `href`/`src`; HTML escaping remains in place for markup context and is not treated as URL validation.

The source scan found no first-party use of `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `new Function`, or `createContextualFragment` in the reviewed application modules. Admin detail response validation (W7-P34) now checks the project/user identity against the requested reference, canonical statuses and UTC timestamps, bounded text/UTF-8 payloads, pagination arithmetic, and all nested owner/brief/channel/project/message/session/audit/event/note/milestone structures before state changes or rendering. It is a response-integrity boundary; existing server authorization remains authoritative. Third-party `assets/js/html2canvas.min.js` remains vendor code and is not rewritten as application source. These findings do not constitute a vulnerability scan or runtime guarantee; dynamic response data, browser parsing, URL navigation, browser extensions, and dependency provenance still require their separate QA/security process.

The outgoing verification-email template's inline style remains a mail-client compatibility concern, not a website document CSP style attribute. The 14 documented runtime geometry writes in `js/app.js` (particles and viewport-measured Tour geometry) remain intentional dynamic CSSOM values, not fixed UI-state styling. No CSP Report-Only activation, collector, staging operation, `unsafe-inline`, Trusted Types policy, or enforcing CSP was added.

## Current implementation boundary

- The project emits baseline `X-Content-Type-Options`, `Referrer-Policy`, and a minimal `Permissions-Policy` on the HTML entry points.
- `Content-Security-Policy-Report-Only` is disabled by default. It is emitted only when `MRNEON_ENV=staging` and `MRNEON_CSP_REPORT_ONLY=1`. No `Content-Security-Policy` enforcing header is emitted by this helper.
- The report-only policy uses same-origin scripts, stylesheets, fonts and connections, permits local/data/blob images, and reports remaining inline styles. W7-P5 removed the inline current-year script and the seven static/generated image event-handler attributes; W7-P41 removed the Google Fonts runtime dependency. This remains an optional observation policy, not an approved enforcement policy.
- No report collector endpoint, database write, report file log, or external reporting account is bundled. If `MRNEON_CSP_REPORT_URI` is empty, the browser can surface violations in developer tools but there is no centralized collection.

## Source inventory after W7-P5 cleanup (W7-P4 baseline)

| Surface | Source evidence | CSP implication / next action |
|---|---|---|
| Inline executable script | The current-year script previously inline in `index.php` was moved to `js/app.js` in W7-P5. The Organization JSON-LD block remains data-only (`application/ld+json`). | No inline executable script remains in `index.php`; JSON-LD must be retained as structured data and observed in the separately authorized staging QA. |
| Structured data script | `index.php` contains an `application/ld+json` Organization block. | Non-executable structured data; retain JSON encoding flags and inspect browser report behavior in approved staging QA. |
| Inline event handlers | W7-P4 initially counted five static `onerror` attributes in `index.php`; W7-P5 source review additionally found two `onerror` attributes embedded in JavaScript-generated gallery HTML. All seven inline HTML handler attributes are now removed. | Static and generated gallery images use `data-image-fallback` policy markers and a document-level capture listener in `js/app.js`; existing replace-versus-hide behavior is preserved without inline event attributes. Re-observe in authorized staging QA before any enforcement. |
| Static style attributes in PHP/HTML page templates | W7-P4 inventory identified 18 attributes across `index.php` and UI component HTML templates. W7-P6 removed all 18 from those page/component templates: fixed presentation moved to scoped `css/components.css` classes; SVG `stop-color` uses native presentation attributes; initial hidden states use the native `hidden` attribute. | No static `style` attribute remains in the five inventoried page/component files. This is not a claim of zero inline styling elsewhere in the application. |
| JS-generated HTML style attributes | W7-P6 source inventory identified 30 literal/generated markup attributes: 23 in `js/auth.js` and 7 in `js/neo.js`, including runtime-computed color/progress values. | All 30 were removed in W7-P7. This source inventory is now zero for the two reviewed files; it does not describe third-party libraries or the separately styled email body. |
| Programmatic CSSOM styles | W7-P7 removed reviewed application-owned CSSOM state writes from `js/auth.js`/`js/neo.js`; W7-P8 migrated finite public UI states in `js/app.js`; W7-P9 reviewed `js/admin.js` and replaced both body overflow writes with the isolated `admin-scroll-locked` class. | The only reviewed application-owned `.style.*` writes remaining in these four modules are the runtime particle geometry/opacity and viewport-measured Tour highlight/tooltip coordinates in `js/app.js`. `js/admin.js` has no remaining direct CSSOM style writes. Preserve runtime geometry; do not replace dynamic values with fixed classes. Vendor/minified code and outgoing email-template styles remain separate. |
| Inline `<style>` blocks | None found in PHP/HTML templates in this inventory. | Re-inventory before enforcement. |
| External stylesheet/font origins | No third-party stylesheet or font origin remains in the active page entry point after W7-P41. Vazirmatn v33.003 remains self-hosted under `assets/fonts/Vazirmatn/webfonts/` (W7-P14); Latin text uses the `--font-latin` platform system stack. | `style-src`/`style-src-elem` are same-origin and `font-src` is `'self' data:` in the optional Report-Only policy. No Google Fonts allowlist is required. No third-party JavaScript origin was found in page script tags. |
| Browser network APIs | Application requests are same-origin; Telegram and demo destinations observed in JS are navigation/window-open links, not fetch endpoints. | `connect-src 'self'` is the initial observation policy; verify in staging before enforcement. |
| Local CSS data URL | `css/components.css` includes a data-URI SVG background image. | `img-src data:` is present in the report-only policy. |

Counts above are a source snapshot inventory, not a substitute for browser-generated violation reports. Dynamic DOM behavior and the exact runtime dependency set remain unverified. `app/integrations/Email/SmtpVerificationTransport.php` also contains an inline style in the outgoing HTML email body; it is intentionally tracked separately because website document CSP does not govern mail-client rendering, and email-client compatibility must be reviewed independently.

## Staging activation (operator action; not performed)

1. Use an isolated staging host and confirm its `MRNEON_ENV` is exactly `staging`. Do not enable this by changing the project default.
2. Set `MRNEON_CSP_REPORT_ONLY=1` in the private process/VirtualHost environment. The `.env.example` file is documentation only and is not loaded by PHP.
3. If centralized collection is desired, set `MRNEON_CSP_REPORT_URI` to an owner-approved absolute HTTPS collector endpoint without query strings or fragments. The code validates the URI and emits both `report-to` and legacy `report-uri` directives when configured. An invalid URI suppresses the CSP Report-Only header and emits only a generic server-side diagnostic.
4. Agree on retention, access controls, redaction, and data processing with the collector owner before enabling it. Reports can contain document paths, blocked URLs, user-agent context, and other request metadata; never include secrets or private data in public URLs.
5. Exercise the site manually only during the separately authorized QA stage, collect representative reports for homepage and admin shells, classify each violation as expected/required/unexpected, and record any necessary remediation in a new bounded part. **This project phase has not run those browser checks.**
6. Do not add `unsafe-inline`, broad wildcards, or an enforced CSP to silence reports. Do not enforce until handlers/styles are remediated, the report window is accepted, and a separate owner-approved implementation/QA gate permits enforcement.

## Non-goals in this part

- No CSP enforcement, HSTS, Nginx configuration, `.htaccess` policy, collector endpoint, database/logging changes, inline-style migration, CSS cascade refactor, browser QA, or dependency migration.
- No claim of CSP compliance, complete security-header posture, formal WCAG AAA conformance, or release readiness.

## W7-P5 remediation checkpoint — inline script and image handlers

- The inline current-year executable script is now part of the existing `js/app.js` module. The JSON-LD `application/ld+json` block is data-only and remains in `index.php`; it is not replaced with executable script.
- Five static project-cover `onerror` attributes and two `onerror` attributes in JavaScript-generated gallery markup were removed. The central `document` capture listener handles current/future gallery image errors using only two explicit data policies: `replace` with the fixed local `assets/images/fallback.svg`, or `hide` for an unavailable thumbnail. It marks an image before acting, preventing recursive fallback loops if the fallback asset itself fails.
- `recoverAlreadyFailedManagedImages()` handles managed images that completed with an error before the main module's listener was installed.
- Inline `style` attributes and JS-driven `element.style` geometry/state assignments remain intentionally out of scope for W7-P5. They must be classified and migrated in separately bounded parts; no mass CSS rewrite or CSP enforcement was performed.
- This is source-level remediation only. No browser-generated CSP report, runtime check, or software test was run; verify behavior only in the separately approved QA phase.


## W7-P6 remediation checkpoint — static PHP/HTML template styles

- All 18 static `style` attributes found in `index.php`, `components/admin-modal.html`, `components/auth-modals.html`, `components/customer-workspace.html`, and `components/neo-modal.html` were removed.
- Stable presentation values were moved into narrowly scoped classes at the end of `css/components.css`. SVG gradient stop colors use the SVG `stop-color` presentation attribute. Initially hidden page sections/title placeholders use the native `hidden` state rather than redundant `display:none` inline declarations.
- No JavaScript-generated HTML `style` attributes, `element.style` assignments, CSSOM mutations, email-template styling, or global CSS cascade/imports were changed. The current source inventory sees 30 `style` attributes in generated JS markup (23 in `js/auth.js`, 7 in `js/neo.js`); review them separately, especially values interpolated from runtime state.
- No browser-generated CSP report, runtime check, or software test was run. CSP Report-Only remains disabled by default and staging-only when explicitly configured. No enforcing policy was added.


## W7-P7 remediation checkpoint — generated NEO/auth markup and state styling

- All 30 JavaScript-generated HTML `style` attributes identified after W7-P6 were removed from `js/auth.js` and `js/neo.js`. Fixed registration/challenge/NEO welcome presentation moved to scoped classes. The customer project progress indicator now uses native `<progress>` with its exact clamped server value. NEO palette/custom-color previews now render as SVG using validated color values in presentation `fill` attributes; no CSS string is constructed from those values.
- Authentication stage visibility and shared modal scroll locking now use semantic `hidden` state and a body class. Password submit/loading, password-strength finite states and NEO validation shake use external stylesheet classes. SVG ring progress uses SVG presentation attributes (`stroke-dasharray`/`stroke-dashoffset`) instead of CSSOM style properties.
- Clipboard fallback and receipt image-export clone styling are class-based. The receipt export clone is class-based and preserves the prior 320px minimum plus 90vw / 600px maximum; no runtime width value is injected into a style attribute.
- Within `js/auth.js` and `js/neo.js`, application-owned `.style.*`, `.style.setProperty`, and related inline CSSOM assignments were removed from the reviewed paths. This does **not** mean all application JavaScript CSSOM use is gone: `js/app.js` and `js/admin.js` still contain independent dynamic geometry, transitions, tour positioning, notification state, and other presentation assignments that require later classification. Vendor libraries in `assets/js/` are third-party/minified implementation and were not rewritten.
- The outgoing verification-email HTML template remains separately styled for email-client compatibility. It is not a website document's CSP style attribute and was not changed.
- W7-P10 removed the duplicate `global.css` cascade inclusion at its source: every current HTML page shell already links `css/global.css` once before `css/components.css`, so the component stylesheet no longer imports the foundation again. This is a stylesheet-load-order correction, not a CSP policy change. The broader component-level cascade cleanup and runtime visual regression confirmation remain separate/open; CSP remains opt-in Report-Only for explicitly configured staging, with no enforced policy, `unsafe-inline`, collector, staging activation, or browser violation collection.
- Software tests remain `NOT RUN BY OWNER DIRECTIVE`. Source/diff/manual inventory and manifest/archive integrity controls only; no syntax/lint/static/browser/DB/security test or application execution was run.


## W7-P8 remediation checkpoint — public UI CSSOM state cleanup

- `js/app.js` finite-state CSSOM writes were migrated to external classes or native state: toast dismissal, portfolio filtering (`hidden` plus sequence-protected transition classes), project-modal close/open state, mobile-menu open/closing state, gallery switching, copy feedback, Clipboard fallback textarea, lazy-image loading, Tour visibility/previous-button state, public scroll lock, and hero-feature reveal. The transitions use explicit classes and respect the existing reduced-motion configuration.
- Opening/closing timers for the project modal and mobile menu are cancellable on rapid reopen. Tour positioning timeouts carry a sequence guard and cannot reactivate a stale highlight after Next/End.
- Remaining `.style.*` assignments in `js/app.js` are limited to actual runtime-dependent dimensions/coordinates/opacity for background particles and viewport-derived geometry for Tour highlight/tooltip. They are not finite presentation states and remain documented rather than being replaced with fixed classes.
- `js/admin.js` CSSOM, third-party/vendor code, outgoing verification-email HTML styling, and `AUD-047` global CSS cascade/import audit remain separate. No mass stylesheet rewrite or CSP enforcement was performed.
- CSP Report-Only remains off by default and staging-only with explicit private environment opt-in; no staging activation or browser report collection took place.
- Tests remain `NOT RUN BY OWNER DIRECTIVE`. No syntax/lint/static/browser/E2E/DB/security test or application execution was run. No DB/provider operation, staging activation, deployment, commit/push, remote mutation or `main` change occurred.


## W7-P9 remediation checkpoint — Admin CSSOM scroll lock

- The complete `js/admin.js` source inventory found two direct CSSOM assignments, both branches of `AdminPanel.syncBodyScrollLock()` writing `document.body.style.overflow`. No generated HTML `style` attributes or other element-level CSSOM writes were found in that module.
- Both branches now share one explicit Admin-owned state: `body.admin-scroll-locked`. The class is enabled only while an Admin blocking dialog is active (`.admin-login-modal.active`, `.admin-project-detail-modal:not([hidden])`, or `.admin-user-status-modal:not([hidden])`). The body class is removed when no such Admin dialog remains.
- The Admin-owned class is intentionally distinct from `modal-scroll-locked` and `app-public-scroll-locked`. On public pages, auth/NEO modules continue to own their shared public-modal lock; Admin no longer clears an inline `overflow` value that could override those other owners. The standalone Admin workspace keeps scroll lock for its blocking dialogs without locking the main workspace itself.
- No Admin dialog lifecycle, focus handling, route, permission, API, or business-data behavior was intentionally changed. `adminDataModal` remains a workspace surface, not an additional blocking dialog in this method.
- `js/app.js` runtime particle/Tour geometry remains the only reviewed first-party CSSOM exception in the public/admin modules; it is data/viewport-derived, not a finite presentation state. Vendor behavior and email-client inline styles remain outside website CSP migration.
- `AUD-047`, CSP Report-Only activation/enforcement, staging observation, and all QA remain separate. Software tests remain `NOT RUN BY OWNER DIRECTIVE`; no app execution, DB/provider operation, deployment, commit/push, remote mutation or `main` change occurred.


## W7-P14 remediation checkpoint — restore self-hosted Vazirmatn assets

- The ten WOFF2 assets referenced by the existing `@font-face` declarations and page preload links are restored byte-for-byte from the historical project archives. Every restored binary matched between the owner-renamed original project archive and the historical `MR-NEON-CODE-SOURCE-COMPLETE.zip`; internal name-table metadata reports version `33.003`.
- The complete SIL Open Font License 1.1 (`assets/fonts/Vazirmatn/OFL.txt`) and upstream author notice (`AUTHORS.txt`) accompany the files. Hashes and provenance are recorded in `docs/ASSET-LICENSES.md`.
- The active CSS references and HTML preloads were already correct, so no CSS/HTML path was rewritten. The Poppins stylesheet still uses Google Fonts and remains an independent W7 dependency; CSP allowlist origins therefore remain until that decision is resolved.
- This is a source asset restoration and binary provenance inspection only. No browser/font rendering test, syntax/lint/static analysis, application execution or software test was run.


## W7-P41 remediation checkpoint — remove Google Fonts runtime dependency

- The public `index.php` entry point no longer preconnects to `fonts.googleapis.com`/`fonts.gstatic.com` or loads the remote Poppins stylesheet.
- `css/global.css` declares the Latin platform-native stack as `--font-latin`; all former `--font-poppins` consumers in `global.css` and `components.css` now use that token. Existing font-weight declarations remain unchanged.
- The optional CSP Report-Only source policy no longer allows Google Fonts origins: `style-src` and `style-src-elem` are `'self'`; `font-src` remains `'self' data:`. Report-Only activation and enforcement status are unchanged.
- The local Vazirmatn @font-face declarations, ten existing WOFF2 assets, regular-weight preload links, license and provenance are unchanged.
- Poppins remains the requested design reference in historical Change Map records, but its binary font files were absent from the exact W7-P40 project package. The public upstream Poppins OFL 1.1 declaration was reviewed; P41 chooses the system stack permitted by Master Handoff §16.2 rather than inventing or introducing an unverified binary. A future exact Poppins self-hosting change requires a pinned, provenance-backed asset plus license.
- This is source-level inventory/remediation only. No browser network capture, rendering comparison, syntax/lint, runtime or QA test was run (`NOT_RUN`).
