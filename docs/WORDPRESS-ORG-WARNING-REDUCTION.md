# WordPress.org Warning Reduction

Local follow-up on 2026-11-03, after the 449-warning review candidate. Version
remains 0.7.2; no commit, push, release, submission or existing-site installation
is authorized by this work. Earlier evidence is retained unchanged.

## Batch 1: Canonical HTTP Inputs

Installed-ZIP Plugin Check: **0 errors, 405 warnings**, down from 449 by 44.
No PHPCS suppressions, checker exclusions, custom checker functions, or rule
configuration were added. The actual request boundaries changed.

| Code | Before | After | Reduction |
| --- | ---: | ---: | ---: |
| `NonceVerification.Recommended` | 31 | 7 | 24 |
| `InputNotSanitized` | 23 | 7 | 16 |
| `MissingUnslash` | 4 | 0 | 4 |
| Other warning codes | 391 | 391 | 0 |
| Total | 449 | 405 | 44 |

Four HTTP boundaries now unslash and sanitize their scalar input explicitly:
private preview capability checks, the preview renderer, private asset download,
and private preview media. They compare each sanitized value, re-slashed with
WordPress's inverse operation, to the original request value. A changed value,
missing field, array or empty nonce is rejected before nonce verification.
This is validation, not permission to repair a malformed credential or identifier.

Those same boundaries directly call core `wp_verify_nonce` after canonical
validation. Existing owner, capability, exact version, publication state and
digest checks remain. The shared canonical verifier remains for programmatic
review, OAuth and connection callers; it was not weakened or removed.

The GET-only response-body and POST-only review checks now use direct, guarded
comparisons of `REQUEST_METHOD`. Their GET/HEAD/POST behavior is unchanged; the
checker can recognize the comparisons without mistaking a parenthesized
null-coalescing expression for unvalidated data.

## Regression Coverage

The final upgrade run passes 29 PHP suites (1,255 assertions, including 248
nonce-boundary checks), 130 HTTP tests, 52 Node checks and the existing PHP/runtime
guards. No HTTP tests are skipped.

- Malformed preview, asset and media query values: arrays, HTML wrappers, empty
  strings, leading whitespace, trailing newline, percent escapes, NUL and
  backslash suffixes fail. A subsequent unchanged URL still works.
- Instrumented real WordPress nonce verification: invalid preview input never
  reaches the pluggable verifier. Canonical GET/HEAD preview access still works;
  a valid preview nonce never grants deletion or POST mutation.
- The basic HTTP preview suite now creates its own disposable article rather
  than relying on whichever draft happens to sort first. Its inline-style
  selector follows the existing product-prefixed asset handle.
- Old submitted 0.7.2 ZIP upgrade, draft ownership/history, stored keys, legacy
  media and reservations are tested alongside the full PHP/HTTP suite.
- Fresh relocated-content/storage and actual two-site network checks are rerun.
  Browser checks exercise save, theme preview, trash and restore at desktop and
  mobile widths. The Browser plugin skill is unavailable; existing headless
  Chrome/Playwright test scripts are used on the disposable local site.
- PHP syntax checks pass for 87 files. Translation template line references are
  regenerated; no translated strings or locale identities change.

Actual engine: WordPress 7.1 / PHP 8.3.36. The PHP 8.2 and WordPress 6.9 engine
matrix, live provider reconnection, and independent infrastructure audit remain
outside this pass. No new assurance is inferred from a lower warning count.

## Remaining Warnings

The 405 remaining entries consist of 340 database/schema/caching warnings,
42 namespace/hook-prefix findings, 20 input/nonce entries, two downloadable guide
files, and one bundled translation-loading finding. None is silently removed.

The remaining input/nonce entries are confined to the legacy filesystem-root
boundary, review form/page handlers, and intentionally public publication media.
The standalone input/nonce sniffs report **13 errors and 7 warnings**, down from
33 errors and 31 warnings. These are the same 20 explained entries, not a claim
that all standalone PHPCS checks are clean.

Do not add stale cross-request caches to permission, revocation, quota or
publication checks merely to reduce DB warnings. Likewise, do not remove legacy
hooks or require visitor nonces for intentionally public media just to silence
the checker. Remaining improvements require their own scoped review and tests.

## Evidence and Candidate

Current evidence is in
[`wordpress-org/review-evidence/2026-11-03-warning-reduction/`](../wordpress-org/review-evidence/2026-11-03-warning-reduction/).
The verification JSON contains final artifact hashes, per-suite results and
disposable-environment cleanup. Candidate ZIPs are kept separately in
`.local/review-candidate-2026-11-03-warning-reduction/`; do not replace already
published 0.7.2 assets with them.

The 449-warning checkpoint is historical. Reviewer decisions about naming and
the content-only remote scope are still pending. The owner must approve any
upload or reply; this work sends neither.
