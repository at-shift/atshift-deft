# 0.7.3 Verification Evidence

These files describe verified local release candidates before publication.
`published: false` and `submitted: false` record that point in time; later actions
are recorded in `docs/WORDPRESS-ORG-REVIEW-0.7.3.md` and the release publication record.

- `verification.json`: actual ZIP hashes, isolated upgrade/fresh/multisite tests and native updater checks.
- `plugin-check.json`: 0 errors, 404 warnings from the installed English-only WordPress.org ZIP.
- `warning-rationale.json`: each diagnostic with its specific retained-boundary explanation.
- `input-sniffs.json` / `prefix-sniffs.json`: targeted diagnostics for the unchanged production boundaries, retained from the immediately preceding warning-reduction audit. Their nonzero results are disclosed, not hidden.

No WordPress configuration, database export, connection credential, private
fixture file or request log is included. Local browser captures and runners remain
in the ignored release folder.
