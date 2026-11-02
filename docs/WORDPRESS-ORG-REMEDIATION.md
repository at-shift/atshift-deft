# WordPress.org Review Remediation

Review received: 2026-11-02. Submitted version: 0.7.2.
Review ID: `AUTOPREREVIEW TRM-RMT atshift-deft/atshift/2Nov26/T2 2Nov26/4.4RC1 (P0TDX398372HGN)`.

Latest local follow-up: [Warning Reduction](WORDPRESS-ORG-WARNING-REDUCTION.md).
It supersedes the 449-warning candidate counts and artifacts below, reducing
Plugin Check warnings to 405 without adding checker suppressions. Earlier
checkpoint evidence is preserved; no public artifact or submission was replaced.

This is the working checklist for the existing application, not a new submission
or a claim that the review is resolved. Preserve the previously prepared icon
changes. Local implementation, compatibility migration and testing are authorized.
Do not publish, change the slug, replace an uploaded ZIP, or reply to the reviewer
without separate approval. Earlier checkpoint sections below are historical;
the inventory and latest linked verification report describe the current candidate.

## Complete Review Inventory

Each review heading remains separate, including overlapping nonce and storage
observations. Only mark an item complete after its evidence and tests are recorded.

| ID | Review item | State | Required closure evidence |
| --- | --- | --- | --- |
| R01 | Guideline 11: admin dashboard notices and onboarding | Local tests passed; reviewer confirmation pending | First-visit dialog is limited to Articles, dismissible and per-user/site. Other notices are actionable administrator-only runtime/conflict errors that disappear when resolved. See the R01 verification below. |
| R02 | Distinctive name, trademarks, display name and slug | Display name selected; specific reviewer concern pending | Owner decision on 2026-11-03: use atshift DeFt with the unchanged atshift-deft slug. This is not evidence that the naming concern is resolved; still ask which mark/project, owner, jurisdiction and goods/services are at issue. |
| R03 | Nonces and permissions before processing requests | Local source/runtime review passed; reviewer confirmation pending | Owner/session/capability/nonce boundaries and rejection behavior are checked. Remote bearer routes remain separately authenticated. Nested REST dispatch is now explicitly denied and regression-tested. |
| R04a | Nonce input sanitization, including repeated wp_verify_nonce examples | Runtime fix verified; diagnostic explanations prepared | All six superglobal nonce sources, Review::decide direct callers and the REST connection header are covered. Every remaining static entry has a file/line-specific boundary explanation in the final evidence; reviewer acceptance remains pending. |
| R04b | Other input handling, including raw OAuth QUERY_STRING | Fixed locally; final package gates below | Bounded exact-name decoding is retained. Validated opaque state is restored after the library parser, with a real approval/token-exchange regression for surrounding spaces. Empty-body connection requests also avoid a PHP deprecation. |
| R05 | Unique prefixes for declarations, globals, data and WordPress identifiers | Implemented locally; compatibility aliases disclosed | `Atshift\Deft` and isolated vendor, asset/browser prefixes, and exact `atshde_` data migration. Previous credentials/history/permissions/media are exercised using an actual old-ZIP upgrade. See PREFIX-MIGRATION.md. |
| R06 | Correct file/directory location resolution | Implemented locally; environment evidence below | New private storage has no filesystem-location dependency. Existing Media Library paths use WordPress APIs; legacy paths are read-only. Relocated content/uploads and site separation have dedicated tests. |
| R07 | Writes outside permitted locations | Replaced locally with database storage | New private and frozen files use bounded, hash-verified database chunks. No new persistent files are written outside uploads or in plugin/core directories. Old files remain read-only; see PRIVATE-STORAGE.md. |
| R08 | Escaping of title filter return values | Tests passed; clarification prepared | Plugin-owned titles are escaped exactly once. Unrelated filter input is intentionally returned unchanged; applying HTML escaping to every other plugin/theme title would be a regression. |
| R09 | Remote administration / independent infrastructure audit | Content-only boundary tested; clarification prepared | DeFt exposes content tools, not plugin/theme/user/code administration. Its credentials fail at unrelated REST endpoints, including nested dispatch. No independent infrastructure audit is claimed; ask the reviewer to confirm this item does not apply. |

## R01: Notice and Onboarding Verification (2026-11-03)

Verified the installed WordPress.org-format ZIP in a disposable WordPress 7.1
site on PHP 8.3.36, using headless installed Chrome through Playwright. No
production notice behavior needed changing. Added `tests/wordpress-notices.php`
and the `test:wordpress-notices` package script to preserve the checks.

- The introduction appears only on the first Articles visit for each permitted
  user/site. Dashboard, Posts, Plugins, Settings, Components and AI connections
  neither show it nor consume the first-visit state. Dedicated workspace scripts
  are not loaded on those other screens.
- Close, Escape, focus restoration, Tab/Shift+Tab containment, real Markdown
  download, reload, a fresh browser session and a different staff member passed.
  The guide remains available after dismissal. Subscriber access is denied.
- Screenshots at 1440 x 1000 and 390 x 844 confirmed that the dialog and controls
  fit the viewport. No uncaught browser errors remained after waiting for the
  WordPress profile page to finish loading before test navigation.
- The PHP requirement and legacy-plugin conflict notices are limited to users
  able to activate plugins/manage network plugins. These are actionable errors,
  not promotions; they self-dismiss when their cause is resolved. Real legacy
  activation/deactivation confirmed appearance/removal. Unsupported PHP values
  and network capabilities were tested through fixtures, not a PHP 8.1 engine
  or an actual multisite installation.
- PHP checks passed: onboarding 34, notices 33, runtime guard 16, legacy guard
  12. All browser interaction assertions passed. The runner's initial blanket
  "no debug log" assertion failed: separate inspection found exactly six core
  update/translation connection warnings caused by `WP_HTTP_BLOCK_EXTERNAL`.
  No other PHP diagnostics appeared; these environmental warnings were not
  hidden or mistaken for DeFt defects.

Temporary evidence: `/private/tmp/deft-notices-WBw881/`. The owned HTTP server
was stopped and the disposable database deleted. No existing site, account,
password, publication, version or application ZIP was changed. Reviewer
acceptance is still required; this is local verification only.

## Display-Name Decision (2026-11-03)

The owner approved `atshift DeFt` as the consistent display name, with the
unchanged `atshift-deft` slug. Update current UI labels, documentation, guides and
translation catalogs locally. Keep the repository, text domain, API/OAuth
identities, role keys/capabilities, saved data and update identity unchanged.
Refresh only the stored role display names through the existing branding
migration. Preserve historical release and application records as written.

This supersedes the display-name hold below, not the unresolved reviewer
question. Different capitalization does not establish that the naming concern
has been accepted or resolved. No reviewer reply or replacement ZIP is authorized
by this display-name decision.

Local verification completed on 2026-11-03 against freshly built packages:

- Browser build, AI-kit build, WordPress generated-file consistency, PHP syntax
  and ZIP integrity passed. Both distribution channels preserve their updater
  difference and carry identical translation catalogs.
- 45 Node tests passed for branding, distribution, i18n, connection profiles,
  MCP prerequisite guidance, destination guidance and the AI kit.
- 435 WordPress checks passed against the installed WordPress.org-format ZIP:
  branding 10, i18n 20, onboarding 34, publication guidance 25, post types 95,
  taxonomies 63, public structure 74, reservations 61 and appearance 53.
  Legacy-activation and PHP-runtime guards passed another 12 and 16 checks.
- A separate fresh WordPress load migrated stored legacy role labels from
  branding version 1 to 2 while preserving a custom capability. The dedicated
  migration test also preserved explicit allow/deny capabilities and verified
  repeat execution. The disposable database was removed and no debug log was
  produced.
- Corrected an existing guidance test that still required the literal version
  `0.7.0` in the current README; it now checks the package version.

Evidence is local in `.local/verification/2026-11-03-branding/`. Version remains
0.7.2; no commit, push, release, live installation or application replacement was
performed. Historical records retain their original display name.

## Naming Decision (2026-11-02, Historical)

Do not rename `atshift DEFT`, its slug, repository, APIs or saved identifiers at
this stage. The owner is open to a different name if a concrete conflict is
established; `D.E.F.T` was an acceptable spelling option, not an instruction to
rename or a claim that punctuation resolves a conflict.

Before a naming decision, prepare a question for the existing reviewer thread:
which specific trademark or project raises the concern, who owns it, and (for a
trademark) which registration/jurisdiction and goods/services are relevant to
`atshift DEFT`? Also distinguish a directory naming policy concern from a legal
infringement conclusion. No reviewer message has been sent.

Research found adjacent AI writing/content services at
<https://deftwriting.com/products> and <https://www.usedeft.app/>. Their existence
does not establish trademark ownership, priority or infringement, nor that they
are the entities identified by the review. Continue code fixes independently.

## Batch 1: Nonce Inputs

State: implementation and packaged-ZIP runtime verification complete on
2026-11-02. Static diagnostics and reviewer acceptance remain open.

Scope: `class-access.php`, `class-assets.php`, `class-media.php`,
`class-review.php`, `class-oauth-consent.php`, `class-connections.php`, and the
private preview in `plugin.php`.

Required invariant: every plugin-owned call to `wp_verify_nonce` receives a
string processed by `sanitize_text_field`; request slashes are removed exactly
once at the superglobal boundary. A nonce changed by sanitization must be
rejected, not converted to an otherwise valid token. Existing owner/session/
capability checks and exact nonce actions remain unchanged.

This repairs the reported input-handling gap. It does not claim an existing CSRF
bypass was reproduced, and does not close the wider R03/R04b audits.

The seven plugin-owned verification sinks now use `Access::verify_nonce`.
`Review::decide` remains protected for direct callers, not only form submissions.
The REST header is already unslashed and must not be unslashed again. The AI-guide
download still delegates its separate nonce check to core `check_admin_referer`.

Tests added: `tests/wordpress-nonces.php` (real WordPress nonce verification with
an instrumented pluggable verifier), and an HTTP OAuth consent regression for
markup, whitespace, percent escapes and array-shaped nonces. Rejection must not
create a grant or consume the pending consent flow.

During regression testing, two security assertions and the OAuth revocation/CAS
tests still expected no destination post ID after cancelling/blocking a
reservation. Since 0.7.1, a reservation has a destination placeholder. The
assertions now require the matching placeholder to be inactive, nonpublic and
empty, while retaining the cancellation/blocked-state checks. The CAS fixture
also applies the normal reservation hold when simulating cancellation. Both the
released implementation and this nonce patch pass the revised PHP security
assertions. The complete HTTP suite passes with this patch. No production
publication behavior was changed in this batch.

Verification used a disposable WordPress installation, a separate database and
the actual WordPress.org-format ZIP, without the GitHub updater. No existing
site, account, password or published package was changed. The temporary database
was deleted and its HTTP server stopped after the run.

| Gate | Result |
| --- | --- |
| PHP syntax for 35 production include files and the new nonce test; Node syntax for the changed OAuth test | Passed |
| `php tests/wordpress-nonces.php` | 172 checks passed: malformed values never reach the pluggable verifier; valid values retain the exact action/token; wrong owners and expired tokens fail. |
| `php tests/wordpress-connections.php` | 27 checks passed. |
| `php tests/wordpress-security.php` | 20 checks passed, both with the baseline production files and with this patch. |
| HTTP/assets/media/workflow/users/OAuth/MCP/publication Node suites, run sequentially | 107 tests passed, none skipped. Valid consent, preview, draft, review and publication flows remain functional. |
| `wp plugin check atshift-deft --mode=new --slug=atshift-deft --format=strict-json` on installed ZIP | 0 errors, 373 warnings. Baseline on the same environment: 0 errors, 347 warnings. |
| Standalone WordPress input/nonce PHPCS sniffs | Nonzero exit; unresolved diagnostics remain. This was a diagnostic inventory, not a clean-check claim. |

The 26 additional Plugin Check warnings are exclusively
`WordPress.Security.NonceVerification.Recommended` (8 to 33) and
`WordPress.Security.NonceVerification.Missing` (6 to 7). Its built-in function
recognition does not follow `Access::verify_nonce`. The runtime wrapper calls
the real `wp_verify_nonce` after strict canonical-input checks, but this is not
a reason to suppress all warnings or mark the review complete. Review each
diagnostic and select a checker-readable implementation or a narrowly evidenced
explanation before submission. Other warning category counts are unchanged.

Retained local evidence is in the Codex Security standalone artifact collection
for this repository, under `artifacts/2026-11-02-nonce-inputs/`: the run summary,
nonce/connection/security results, full HTTP TAP results, before/after Plugin
Check JSON and input-sniff diagnostics. No credentials or site configuration are
included in that retained evidence.

## Batch 2: OAuth Input Parsing (R04b)

State: OAuth parser correction and packaged-ZIP verification complete on
2026-11-02. The wider R04b input inventory is not complete.

The raw-name duplicate checks need comparison with PHP's parsed request keys.
For example, PHP parses `client.id=discard&client_id=kept` as one `client_id`
value, and `state[]=discard&state=kept` as one scalar `state`. These are parsing
discrepancies, not demonstrated authorization bypasses. Four new HTTP regression
tests were run against the released OAuth files: authorization, consent POST,
token exchange and revocation each accepted an ambiguous input. Those same
tests pass with the corrected implementation.

`OAuth::decode_form` uses the already bundled League URI ordered-pair parser in
RFC1738 form mode, followed by an exact endpoint-specific key allowlist and
decoded-name duplicate rejection. The resulting map is the map used downstream;
there is no later merge with PHP-normalized GET/POST/REST parameters. It bounds
input to 32768 bytes and 32 pairs before invoking the parser, and rejects malformed
percent escapes. Opaque state, redirect URIs, resources and credentials are not
text-sanitized or decoded twice. Existing endpoint-specific semantic validators
and owner/session/nonce checks remain in place.

Authorization GET, consent POST, token and revoke all use this boundary.
`action=sf_oauth_authorize` is checked explicitly in admin-post requests. Consent
POST now accepts only URL-encoded forms (the format emitted by the plugin), with
an optional charset parameter. Multipart/JSON submissions are explicitly
unsupported and tested to leave the pending flow usable. Empty revocation
requests retain their idempotent response; an omitted scope retains its default,
while a bare/empty scope remains invalid.

| Gate | Result |
| --- | --- |
| PHP/JavaScript syntax and diff whitespace checks | Passed. |
| `php tests/wordpress-oauth-inputs.php` against the installed package | 67 checks passed, covering aliases, array forms, duplicates, exact values and bounds. |
| Four focused HTTP regressions against released OAuth source | Four expected failures reproduced ambiguous-input acceptance; no authentication bypass claim. |
| Full sequential WordPress HTTP suites on the corrected package | 113 tests passed, none skipped. Rejected inputs do not create/consume flows, issue/rotate tokens or revoke grants; subsequent valid operations succeed. |
| Existing nonce/connection/security PHP suites | 172 + 27 + 20 checks passed. |
| Both distribution ZIPs, translation parity, exact dependency manifests, WordPress.org ZIP integrity | Four distribution tests and `unzip -tq` passed; no artifacts published. |
| Plugin Check on installed WordPress.org ZIP | 0 errors, 371 warnings (previous batch: 373). The two removed warnings came from OAuth's previous GET/POST reads. |
| Independent read-only candidate review | No concrete bypass or regression found. The reviewer also exercised 1187 in-memory cases; this does not replace the parent's stateful HTTP suite. |

The disposable database was deleted, its HTTP server stopped, and no WordPress
debug log was produced. Retained evidence is in the repository's Codex Security
artifact collection under `artifacts/2026-11-02-oauth-inputs/`. It contains test
results and diagnostics, not credentials, wp-config.php or request logs.

### Nonce Warning Follow-up

The 26 warnings introduced by the shared nonce helper in Batch 1 are now 24;
OAuth's two superglobal-read warnings were removed by the parser correction.
The remaining additional diagnostics were individually grouped by their exact
read/authorization boundary:

| Boundary | Additional diagnostics | Guard before protected output/access |
| --- | --- | --- |
| `Access::preview_caps`, input lines 97-100 | 8 | Exact read-only preview, owner/version match, shared nonce check; otherwise `do_not_allow`. |
| `Assets::serve`, input lines 256-257 | 4 | Authenticated private-file permission, article ownership and asset/digest-bound nonce before reading bytes. |
| `Media::serve`, input lines 173-176 | 8 | Authenticated permission, article ownership and version/key-bound nonce before resolving and reading media. |
| Private preview in `plugin.php`, input lines 225-226 | 4 | Article ownership, preview/version match and digest-bound nonce before rendering. |

These guards and their rejection/valid-input paths passed the nonce and HTTP
suites. Prepare this narrow explanation for the reviewer; no blanket suppression
or weakening of runtime checks was applied. The other 14 existing nonce warnings
and the rest of the 371 diagnostics remain part of R03/R04's broader audit, not
automatically classified as false positives. Standalone input-sniff diagnostics
still exit nonzero, and the application is not ready for resubmission yet.

## Batch 3: Remaining Nonce Boundaries (R03)

Scope: the 14 pre-existing nonce diagnostics identified after Batch 2. The
broader request/permission inventory and reviewer acceptance remain open.

| Boundary | Diagnostics | Classification and enforcement |
| --- | --- | --- |
| `Review::submit`, form input reads | 6 missing | Protected mutation. POST-only, then `Review::decide` independently verifies the authenticated cookie identity, secure transport, reviewer capability, active draft ownership, allowed decision and exact review/manifest nonce before updating. Basic/Bearer headers do not substitute for the session. |
| `Review::authorize_page`, review ID read | 2 recommended | Private read-only navigation. Canonical ID, reviewer capability and owner checks precede manifest output. The form generates its own decision nonce; opening its link does not require one. |
| `PublicationMedia::serve`, route/ID/key reads | 5 recommended | Intentionally public GET/HEAD. The published job must match the destination post, author, type and publication binding. Nonpublic/password-protected destinations fail closed; the file key, size and digest are checked before output. A visitor nonce would break legitimate public media. |
| `plugin.php`, review-page privacy-header detection | 1 recommended, removed | Replaced the query-string check with the resolved review screen's `load-...` hook. It grants no authority and changes no review/publication state; `Review::authorize_page` still protects the screen. |

No missing-nonce bypass was reproduced in these paths. Do not suppress all nonce
diagnostics or require anonymous visitors to obtain private WordPress nonces.
Keep these per-boundary explanations for the reviewer.

A separate, concrete defect was reproduced by the new HTTP test: the review's
`Referrer-Policy: no-referrer` header was overwritten by core
`wp_admin_headers`, registered later at the same `admin_init` priority 10.
An initial priority-20 correction passed the canonical URL test, but independent
candidate review identified aliases such as `page=structured-fields-review.php`:
core removes `.php` while resolving the page hook, so the raw query comparison
missed the same screen. A focused HTTP run reproduced this on the intermediate
candidate (15/16 workflow tests passed, with the alias header assertion failing).
The final fix places privacy headers on the already registered review-screen
`load-...` hook, after core admin headers and before owner authorization/output.
This covers core-resolved aliases without interpreting the route a second time.
It is the only production behavior change in this batch; nonce checks,
permissions and response semantics are unchanged. It restores the intended
privacy policy, not a claim that an authorization bypass or actual external
disclosure was demonstrated.

Regression coverage was added to `tests/wordpress-workflow.test.mjs`,
`tests/wordpress-publications.test.mjs` and `tests/wordpress-users.test.mjs`,
with fixture-only state changes in
`tests/wordpress-users-fixture.php`. Coverage includes final privacy headers,
ordinary admin policy preservation, denied unauthenticated/malformed review
reads, non-POST decisions, credential headers, JSON rejection, multipart form
compatibility from a fresh pending review (including approver identity and
idempotent retry), POST/query separation, anonymous media GET/HEAD, unsupported
methods, unknown keys and 13 publication/post-state mismatches. Each denied
media state is followed by restoration and an exact content-hash check.

The initial test expected all admin-post OPTIONS requests to reach the plugin's
405 check. Core `send_origin_headers` exits first: 403 for an absent/disallowed
origin, or an empty 200 preflight for an allowed origin. Its default origin list
also omits the local test port. Tests now assert those core behaviors and that
the review record is unchanged; no production CORS policy was relaxed.

Packaged-ZIP verification completed on 2026-11-03:

| Gate | Result |
| --- | --- |
| PHP/JavaScript syntax and `git diff --check` | Passed. |
| Sequential WordPress HTTP suites | 118 tests passed, none skipped. Canonical and three core-resolved review aliases use `no-referrer`; other owners/admins remain denied. The ordinary admin response retains core's default policy. Valid approvals, rejection/withdrawal, private owner-only access and public image/PDF delivery remain functional. |
| Existing nonce/OAuth-input/connection/security/publication PHP suites | 172 + 67 + 27 + 20 + 18 checks passed (304 total). |
| Actual WordPress.org ZIP installation, installed source comparison and `unzip -tq` | Passed. No GitHub updater in this test package. |
| Plugin Check on the installed ZIP | 0 errors, 370 warnings (previously 371). Removing raw route detection removed one diagnostic; the other 13 targeted diagnostics remain visible with the explanations above. No suppressions added. |
| Standalone input/nonce sniffs | Still nonzero; wider R03/R04 input work is not complete. |

The first run reproduced the header-order defect. A second run proved the
header fix but exposed the test's incorrect assumption about core's portless
CORS origin list. The final run passes the corrected core preflight expectations,
the alias regressions and the plugin's unchanged mutation safeguards.

The temporary databases were deleted, the local test servers stopped, and the
final run produced no WordPress debug log. Evidence is retained in the repository's
Codex Security artifact collection at `artifacts/2026-11-03-nonce-boundaries/`.
This work has not changed the version, published packages or application ZIP.

One independent read-only candidate review was completed. Its two findings
(route-alias coverage and multipart testing only as an idempotent retry) were
confirmed and addressed, then the full packaged-ZIP suite was rerun. No second
review cycle was performed; broader findings remain on the inventory above.

## R03/R04: Request and Input Verification (2026-11-03)

Reinstalled the current WordPress.org-format ZIP in a new disposable WordPress
7.1 site on PHP 8.3.36. This verification does not alter production behavior or
suppress static warnings. The current input/nonce inventory is fully classified
below; reviewer acceptance and the other inventory items remain separate.

| Gate | Result |
| --- | --- |
| Syntax for all 35 production include files | Passed. |
| Nonce, OAuth-input, connection, security and publication PHP tests | 172 + 67 + 27 + 20 + 18 = 304 checks passed. |
| Sequential HTTP/assets/media/workflow/users/OAuth/MCP/publication suites | 118 tests passed, none skipped. Rejected requests preserve protected state; valid owner flows still work. |
| Plugin Check on the installed ZIP | 0 errors, 370 warnings, unchanged from Batch 3. |
| Standalone input/nonce PHPCS diagnostics | 33 errors and 31 warnings, exit 1. These are 64 diagnostic entries, not 64 demonstrated vulnerabilities. Plugin Check classifies these differently; neither result is represented as warning-free. |
| ZIP integrity and cleanup | Passed; owned server stopped and disposable database deleted. |

The standalone diagnostics comprise 37 nonce and 27 input-handling entries
(23 `InputNotSanitized`, four `MissingUnslash`). Every remaining entry maps to
one of these six files. Exact line numbers and checker codes are retained in
`artifacts/2026-11-03-input-permissions/input-sniffs.json` in the security scan.

| Boundary | Nonce entries | Input entries | Existing enforcement / reviewer explanation |
| --- | --- | --- | --- |
| `class-access.php:97-100` | 8 | 4 | Preview post/version/flag are strictly matched; owner and action-bound canonical nonce checks precede permission. Nonmatching inputs yield `do_not_allow`. |
| `class-assets.php:79,256-257,278` | 4 | 5 | Private-file reads require permission, owner, exact asset ID and canonical digest-bound nonce. The method is allowlisted before serving. `DOCUMENT_ROOT` is server configuration, type-checked and unslashed before canonical path containment checks; text sanitization is not path validation. Storage portability remains R06/R07. |
| `class-media.php:173-176,204` | 8 | 6 | Exact article/version/key, owner and version/key-bound nonce precede media lookup and byte integrity checks; unsupported methods fail before serving. |
| `plugin.php:217-218` | 4 | 2 | Private preview checks article ownership, exact version and digest-bound nonce before rendering. |
| `class-review.php:137-140,148` | 8 | 6 | Mutations are POST-only and delegate to a direct-caller-safe decision function checking cookie identity, secure transport, capability, owner and exact nonce. Read-only review navigation independently checks the canonical ID and owner. |
| `class-publication-media.php:112,117-118,124` | 5 | 4 | Public GET/HEAD intentionally has no visitor nonce. Exact publication/key values must resolve to a matching published, public, non-password-protected post and hash-verified bytes. |

The four method diagnostics are exact comparisons against supported HTTP
methods, not values passed into markup, SQL or paths. Nonce reads remove slashes
once, then the shared verifier rejects any value changed by sanitization.
Identifiers and opaque protocol values are validated without normalizing an
invalid value into a valid one. No blanket suppression was added.

The run produced four core update/translation connection warnings because
`WP_HTTP_BLOCK_EXTERNAL` intentionally blocks outbound update checks. Separate
inspection found no other PHP diagnostics. Evidence contains summaries, PHP
results, HTTP TAP, Plugin Check JSON and input diagnostics, not credentials,
`wp-config.php` or request logs. Existing sites, accounts and passwords were not
changed. This is not a full-plugin, live-provider or multisite audit.

Independent reviews covered all 35 production include files plus a focused
OAuth/credential review. No new reportable security vulnerability was established
in this scope. The parent verified the following separate compatibility issue:

- `OAuthConsent::validate` accepts printable ASCII state including surrounding
  spaces, but the bundled OAuth grant parser trims those spaces. An in-memory
  call against the actual dependency confirmed `" x "` becomes `"x"`.
- The exact state comparison in `class-oauth-consent.php:85` then rejects approval
  and rolls back. This fails closed; no unauthorized issuance or disclosure was
  established. Decoder-only preservation tests do not cover the approval
  round-trip, so the passing HTTP suite does not establish this edge case works.
- Before closing R04b, add an approval round-trip test for leading/trailing spaces
  and preserve the already validated opaque state across the library boundary.
  This audit did not change the production code for this observation.

The source review also found no attacker-reachable nested REST dispatch bypass,
but restoration of the current user through `rest_post_dispatch` was not tested
against arbitrary third-party nested/batch dispatch implementations. That is a
runtime limitation, not an established vulnerability. Other prefix, storage,
title and remote-infrastructure review items remain open.

## Code Inventory Checkpoint (2026-11-03)

See [CODE-INVENTORY.md](CODE-INVENTORY.md) for runtime, development-only and
compatibility classifications before the remaining larger changes. Removed four
unused prototype icon imports and added a distribution regression. No runtime
PHP file, endpoint, migration or supported schema was removed; both distribution
ZIPs remain byte-identical to the pre-cleanup working-tree packages. Node tests
71 and PHP guard checks 28 passed. This cleanup does not close R04b, R05-R09 or
the previously interrupted security-report finalization. No version/publication
change was made.

## Final Candidate Verification (2026-11-03)

The local implementation and verification work is complete. This is a candidate
for the existing review, not a claim of directory approval. R02 needs the
reviewer's specific naming concern; R09 needs confirmation of the content-only
scope. The draft reply explicitly asks both questions and makes no independent
infrastructure-audit claim.

The final candidate uses `Atshift\Deft` (bundled dependencies under
`Atshift\Deft\Vendor`) and exact `atshde_` persisted identifiers. The private-file
backend is now the database. Earlier checkpoint counts and open implementation
notes above are superseded by this section, including the opaque OAuth state
round-trip and nested REST dispatch observations.

### Installed-Package Gates

Both runs build both distribution ZIPs independently, install the actual
WordPress.org ZIP and compare the final artifacts byte-for-byte. The engine is
WordPress 7.1 with PHP 8.3.36 and a disposable local MySQL database.

| Gate | Final result |
| --- | --- |
| Old submitted ZIP to current candidate | 12 seed and 23 upgrade checks passed; existing drafts/history, explicit capability denials, connection key, encrypted OAuth signing material, legacy staged/frozen image/PDF bytes and reservation references retained. |
| GitHub native updater compatibility | 69 checks passed with the released 0.7.1 updater, then 69 with the new namespace under a disposable older-version header fixture. Real WordPress upgrader/ZIP installation, tamper rejection and a fresh post-update bootstrap passed; GitHub HTTP responses were mocked locally. |
| Stateful PHP regression suites | 29 suites, 1,179 checks passed. Includes migration interruption/conflict recovery, nonce/input rejection, content-only authorization, quotas, media, publication destinations/taxonomies, reservations, styles, titles and structured output. |
| HTTP MCP/OAuth/content suites | 125 tests passed, none skipped; includes real consent/token exchange with opaque state whitespace. Fresh relocated installation passed another 9 asset HTTP tests. |
| Node checks | 52 source/distribution/branding/i18n/schema/transport tests passed. Rebuilt AI kit passed its additional 9 tests. |
| Runtime and legacy activation guards | 16 and 13 fixture checks passed. |
| Fresh storage and moved directories | 11 portability checks and 23 binary-storage checks passed without legacy storage constants; nonstandard content/uploads locations, database-only image/PDF preview and publication, and existing Media Library reuse exercised. |
| Actual two-site network | Main-site/subsite bootstraps, independent schema/roles/private bytes and rejection of a main-site key at the second site passed. |
| Browser lifecycle | Articles, Components and AI connections loaded without uncaught errors. Sample save, theme preview, trash and restore passed; desktop 1440 x 1000 and mobile 390 x 844 screenshots inspected and horizontal overflow rejected. |
| PHP and generated assets | Syntax checks passed for 87 plugin/test PHP files. Browser and AI-kit builds passed; rebuilding WordPress schemas/samples left their bytes unchanged. |
| Dependency bundle | 14 locked runtime packages / 361 files rebuilt under the product vendor namespace. Composer audits returned no advisories; classmap, OAuth/PSR-7/crypto/PKCE and both dependency loading orders passed. |
| Distribution contents | ZIP integrity, dependency manifests and identical translation catalogs passed. WordPress.org ZIP omits the GitHub updater; the separate GitHub ZIP includes it. |
| Cleanup / diagnostics | Both owned HTTP servers stopped and disposable databases deleted. Only the exact core update/translation warnings caused by blocked outbound HTTP remained (6 upgrade, 4 fresh); no other PHP diagnostics. |

The browser prototype build emits two third-party Lucide `use client` directive
warnings. This is the client-only development prototype, not a failure in the
installable WordPress package; the build completes. No dependency churn was
introduced to silence them.

The old WordPress.org input ZIP matches the recorded submitted 0.7.2 SHA-256:
`348a26ce62c483b9cac8cf4e7a66197c558e1d8c9922bba6431aba93d2e2d301`.
The upgrade fixture allows only the separately specified branding version 1-to-2
and three default role-label changes, while still comparing all table rows,
other options, ownership and capabilities. The updater fixture resolves both the
released `AtshiftDeft` updater and the new `Atshift\Deft` updater; no such PHP
alias is added to the distributed plugin.

### Remaining Static Diagnostics

Plugin Check on the installed ZIP: **0 errors, 449 warnings**, identical in the
upgrade and relocated-fresh runs. Every diagnostic is retained by file, line,
code and reason in the [warning rationale](../wordpress-org/review-evidence/2026-11-03/warning-rationale.json).

| Group | Entries | Reason retained |
| --- | ---: | --- |
| Direct database access / caching / schema | 340 | Plugin-owned versioned data, current grants/quotas/publication state, locking and exact transactional migration. Queries use structured placeholders; stale cross-request results are inappropriate at these boundaries. |
| Nonce / input analysis | 64 | Strict shared nonce verification, exact semantic input validation and intentionally public hash-checked media delivery. Six request boundaries and their valid/rejected paths have runtime coverage. |
| Prefix inference | 42 | 38 explicit `Atshift\Deft` namespace declarations, two existing `atshift_deft_*` hooks and two exact legacy quota-hook aliases. The auto-inferred prefix differs from the declared product namespace. |
| Downloadable Markdown guides | 2 | Required onboarding/user-facing files, not unused development documents. |
| Bundled translation loading | 1 | Required for the GitHub distribution's local language catalogs as well as directory distribution. |

Separate input/nonce PHPCS still exits nonzero with 33 errors and 31 warnings;
those are the same 64 explained entries, not an additional clean-check claim.
An explicit-prefix PHPCS run accepting `Atshift\Deft`, `atshde_` and
`atshift_deft` reports only two errors: the deliberately retained old
`sf_shared_component_limit_per_user` and `sf_private_media_limit_per_user` filter
aliases. No runtime checks were weakened or blanket warning suppressions added.
The new database wrapper and plain-text exception messages have narrowly scoped
comments explaining their prepared-query and output-escaping boundaries.

### Candidate Artifacts

Local directory: `.local/review-candidate-2026-11-03/`. These 0.7.2 files are
**unreleased review candidates**, not replacements for already published assets.
Do not upload the updater build to WordPress.org or publish the generated update
metadata under the existing release. A future public update needs a separately
approved version bump and release verification.

| File | SHA-256 |
| --- | --- |
| `atshift-deft-0.7.2-wordpress-org.zip` | `49cd701ffb3220979de28d8e33c1a31169cdf73bd9fab4d11a43dc6ec0b388fb` |
| `atshift-deft-0.7.2.zip` | `c25ae9dd4340a87f80022aa20c21ce430588dbca68ce5768e0c69ed1afd67c8e` |

The [verification record](../wordpress-org/review-evidence/2026-11-03/verification.json)
contains safe test summaries, artifact sizes/hashes and cleanup results. It does
not contain site configuration, connection keys, private fixture files or
request logs. The [English reviewer reply](../wordpress-org/reviewer-reply-draft.md)
is prepared but has not been sent.

### Limits and Operational Notes

- Actual PHP 8.2 execution was unavailable; PHP requirement/legacy guard fixtures
  passed, but they do not replace a PHP 8.2 engine matrix. The declared WordPress
  6.9 minimum was not re-run in this pass; actual WordPress runtime tests used 7.1.
- The legacy upgrade was tested on a single site. Fresh two-site network isolation
  passed; large-network bulk migration and mixed-version rolling workers were
  not tested. Do not run old and new plugin workers concurrently during migration.
- Back up database and legacy private directories before a real upgrade. After
  table/identifier migration, copying old plugin files back is not a downgrade;
  restore a matching backup if rollback is necessary. Existing private directory
  constants must remain while old files are referenced.
- No live ChatGPT/Claude provider reconnection, production deployment, independent
  security certification or complete host/DB configuration matrix is claimed.
- An earlier multisite test harness loaded wp-config twice; the corrected guard
  prevents duplicate bootstrap. Plugin Check itself runs before network conversion
  because its temporary runtime installer failed after conversion. Actual network
  runtime tests run afterward; this is not a clean multisite Plugin Check claim.

## Final Submission Gate

- [x] Implement fixes and prepare a response for every review heading.
- [x] Test affected workflows and upgrade/migration from the released version.
- [x] Build and install the actual WordPress.org ZIP without the GitHub updater.
- [x] Re-run Plugin Check; retain a file/line-specific reason for every diagnostic.
- [x] Verify PHP syntax, translations, package contents and dependency manifest.
- [x] Prepare a concise English reply with necessary boundary explanations.
- [ ] Obtain reviewer agreement on the naming concern and content-only audit scope.
- [ ] Obtain owner approval before uploading the replacement and replying to the existing thread.

No commit, push, release, live deployment, submission replacement or reviewer
message was performed. Published 0.7.2 artifacts and previous submission records
remain unchanged.
