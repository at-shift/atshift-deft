# Warning Reduction Evidence

Local-only candidate, not submitted or published. See
[`WORDPRESS-ORG-WARNING-REDUCTION.md`](../../../docs/WORDPRESS-ORG-WARNING-REDUCTION.md).

- `warning-delta.json`: 449 to 405, 44 fewer warnings, no added suppressions.
- `plugin-check.json` and `warning-rationale.json`: all current entries and explanations.
- `input-sniffs.json`: 13 errors / 7 warnings in standalone input/nonce analysis.
- `prefix-sniffs.json`: unchanged explicit-prefix results from the preceding batch.
- `verification.json`: upgrade and fresh installed-ZIP runs, artifact hashes and cleanup.

Final tests: 1,255 PHP assertions across 29 suites, 130 HTTP tests, 52 Node tests,
16 runtime and 13 legacy guard assertions. Fresh relocated storage, private
assets, browser save/preview/trash/restore, and two-site isolation also passed.
Only known blocked-outbound core warnings remain in the isolated environments.

Both builds produce identical ZIPs. The WordPress.org candidate excludes the
GitHub updater; translations match. No actual-provider reconnection or native
GitHub update execution was repeated in this batch. Existing updater code is
unchanged; its earlier test record remains separate.

No credentials, private fixture data or site configuration are included.
