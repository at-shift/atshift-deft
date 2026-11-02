# Local Review Candidate Evidence

Not submitted or published. Candidate version: 0.7.2. Existing public artifacts
are unchanged; a later public update requires its own version and authorization.

- `verification.json`: two independent package builds, installed-ZIP regression
  results, final package hashes and disposable-database cleanup.
- `plugin-check.json`: all 449 warnings; zero Plugin Check errors.
- `warning-rationale.json`: the same entries with file/line-specific reasons.
- `input-sniffs.json`: standalone WordPress input/nonce checks; 33 errors and
  31 warnings, all explained by the six request boundaries in the main report.
- `prefix-sniffs.json`: explicit-prefix check; two retained legacy quota-hook
  aliases, no unprefixed namespace diagnostic.

The detailed interpretation and limitations are in
[`docs/WORDPRESS-ORG-REMEDIATION.md`](../../../docs/WORDPRESS-ORG-REMEDIATION.md).
The English reply is [`reviewer-reply-draft.md`](../../reviewer-reply-draft.md).

The final installed-package runs use WordPress 7.1 / PHP 8.3.36. The upgrade run
has 29 PHP suites (1,179 checks), 125 HTTP tests, 52 Node tests, 29 guard checks
and the legacy seed/verify assertions. The fresh run uses nonstandard content
and uploads paths with no legacy private-directory constants, exercises actual
browser save/preview/trash/restore, and then tests two real network sites.
The GitHub and WordPress.org packages are byte-identical across the two runs.
The separate GitHub updater run passes 69 assertions with the released updater
and 69 with the new namespace. It uses mocked HTTP responses but the real
WordPress upgrader and final candidate ZIP, followed by a fresh process that
checks migration and preserved content. All three disposable databases were
deleted; no existing installation or remote release was changed.

Additional build checks: `npm run build`, `node scripts/build-wordpress.mjs`
(no generated-byte changes), and `node --test tests/ai-kit.test.mjs` passed.
The AI-kit check contributes nine tests beyond the 52-test regression group.
The client-only prototype's Lucide module-directive warnings are recorded in
the main report. They are not installable-plugin runtime failures.

No configuration files, connection tokens, private fixture state, request logs
or database dumps are included. The local candidate ZIPs and screenshots are in
`.local/review-candidate-2026-11-03/`, excluded from Git.
