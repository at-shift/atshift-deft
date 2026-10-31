# 0.6.0 Local Verification

Date: 2026-10-31. WordPress 7.1, PHP 8.3.36, MySQL 8.4, Twenty Twenty-Five.
Disposable database only; no deployment or WordPress.org submission.

## Distribution Evidence

- GitHub ZIP: 421 files; SHA-256 `69c0f2170db339e89181c181d26e70ff303e4be6ba2bc10d4de1c7b20efabdb5`.
- WordPress.org ZIP: 420 files; SHA-256 `be3211f882b031e47dbbe6b70e463f125e11f0ba97e5427776ec088c25a14dac`.
- All shared package files and all translation files are byte-identical. Only the GitHub entry-point adapter and its implementation differ.
- The original published 0.5.2 ZIP was retained unchanged for compatibility checks.

## Tests

- 56 PHP appearance checks: schema limits, field-key scoping, duplicate reuse, author permissions, immutable article revisions, theme setting preservation, preview/publication and byte-compatible unstyled structures from 0.5.2.
- 69 updater checks: stable release discovery, invalid metadata, draft/prerelease rejection, caching, unrelated-plugin isolation, equal/older-version exclusion, wrong-size and same-size tampering, actual native plugin-screen upgrade and data/activation retention.
- 20 Node package, translation, connection-profile and channel tests; the 7 branding/i18n tests also pass against the separate WordPress.org ZIP.
- All 326 PHP files in the installed WordPress.org ZIP pass syntax checks; the GitHub adapter is separately linted. Gettext format/header validation passes.
- Switching the isolated installation to the same-version WordPress.org ZIP removes the updater file, class and GitHub hook while retaining stored snapshots and components. The Japanese MO loads and returns translated updater messages.
- Appearance browser QA in the preceding implementation verified desktop/mobile layout, visible keyboard focus, Q&A disclosure and no horizontal overflow. No new AI-provider account workflow or production compatibility claim is made here.

The updater test uses an installed older-version header fixture and simulated HTTP
responses, then invokes WordPress's native update check and plugin-screen upgrader.
It is not a claim that a 0.5.x public release already has the updater. Existing
0.5.x users must install the 0.6.0 GitHub ZIP once.

## Plugin Check

Installed WordPress.org package: **0 errors, 323 warnings**. No global suppression
was added. Warning groups:

- 146 direct database queries and 130 uncached reads: custom-table stores, locks and authoritative owner/version/policy checks. Cache reuse must not bypass state revalidation.
- 23 sanitization and 4 unslash notices: exact tokens are unslashed, then checked by regex, equality or nonce verification; method values use exact allowlists. Filesystem roots use realpath/permission validation instead of text sanitization.
- 14 nonce notices: review mutation verifies nonce/session/owner in `Review::decide`; review-display and route detection are read-only; published-media routes intentionally allow public GET/HEAD only after checking publication state and exact media references.
- 2 compatible `sf_*` hooks, 1 required table migration and 1 bundled-translation loading notice.
- 2 root Markdown files: the AI guide and Japanese user guide are required downloadable/user documentation, not build artifacts.

These notes explain retained behavior, not a promise that WordPress.org reviewers
will accept every warning. The declaration of minimum WordPress/PHP versions,
multisite and arbitrary third-party themes still needs a broader compatibility
matrix. Submission remains on hold at the user's request.
