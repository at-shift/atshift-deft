# WordPress.org Review Candidate 0.7.3

Prepared on 2026-11-03 for the existing `atshift-deft` application. Publication and
upload outcomes are recorded separately after they happen; this verification
record alone is not evidence of a completed upload or review approval.

## Artifacts

| Channel | File | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| GitHub | `atshift-deft-0.7.3.zip` | 798273 | `52676f567bf01b84423465f57d7101a990705cd2fddd92d1d009eba74bfec92a` |
| WordPress.org | `atshift-deft-0.7.3-wordpress-org.zip` | 756216 | `f4bbef35082c9529eea38e685a302a6bbdc12b429d8e82c5a7865623fc8e30fc` |

GitHub includes its updater, partial Japanese catalogs and Japanese guide.
WordPress.org is English-only: no updater, Update URI, locale catalogs or Japanese
guide. The shared core matches exactly; the POT and WordPress language-pack support
remain in both. Both contain the prepared icon. Earlier releases and submitted ZIPs
remain immutable. Local files are retained in `.local/v073-publish/`.

## Actual ZIP Verification

- WordPress 7.1, PHP 8.3.36, Plugin Check 2.1.0.
- Actual previously submitted 0.7.2 ZIP: 12 seed and 23 post-upgrade checks.
- 29 PHP suites, 1,256 assertions; 130 HTTP tests with none skipped; 52 static/package tests.
- Runtime/legacy guards: 16 and 13 checks; AI construction kit: 9 checks; browser prototype build passes (two existing Lucide client-directive build warnings).
- Fresh English-only install, relocated content/uploads, database-only private media, and actual two-site network isolation pass.
- Three admin pages have no uncaught browser errors; sample save, theme preview, trash and restore pass at desktop/mobile sizes. Screenshots were visually inspected. External avatars are intentionally blocked in isolated browser tests.
- Both packages pass MCP prerequisite guidance with the adapter active and inactive (27 checks each), plus localized/fallback onboarding and publication guidance. Added external locale loading remains supported.
- GitHub's released 0.7.2 updater and new-namespace fixture each pass 69 checks, including actual native ZIP installation, integrity rejection and preserved stored data. GitHub HTTP responses were controlled fixtures; this does not claim a live provider reconnection.
- All 333 submitted PHP files pass syntax lint. ZIP integrity and exact dependency manifests pass; generated WordPress schemas/sample files match a clean rebuild.
- All owned test databases and servers were removed/stopped. No existing user site was changed. The isolated runs suppress outbound core update requests; their only PHP log entries are the expected blocked-core-update warnings.

Minimum PHP 8.2 and WordPress 6.9 engines were not rerun in this release pass.
There is no claim of an independent infrastructure security audit.

## Remaining Warnings

Plugin Check: **0 errors, 404 warnings**, identical for upgrade and fresh installs.
The previous local candidate had 405; excluding the Japanese user guide removes
one `unexpected_markdown_file` warning, not a runtime security issue.

| Category | Count | Context |
| --- | ---: | --- |
| DirectQuery / NoCaching / SchemaChange | 181 / 158 / 1 | Prepared plugin-owned data, locks, migration, immutable versions and current permission/quota/publication state. Not every query is inherently uncacheable. |
| Namespace / hook prefixes | 38 / 4 | Explicit `Atshift\Deft`, existing product filters and two intentional legacy quota aliases. |
| Input / nonce recommended / nonce missing | 7 / 7 / 6 | Caller-validated inputs, shared verification, owner-only review and intentionally public validated media. |
| Text-domain registration | 1 | Shared language loading supports independent GitHub distribution and added language packs. |
| Markdown | 1 | `AI-GUIDE.md` is runtime input for the downloaded AI guide. |

The separate targeted input PHPCS run still reports 13 errors and 7 warnings;
explicit-prefix PHPCS reports 2 legacy-hook errors. These are different checks,
not a clean-all-tools claim. No global exclusions or blanket suppression were added.
See [file/line-specific rationales](../wordpress-org/review-evidence/0.7.3/warning-rationale.json)
and the [full remediation history](WORDPRESS-ORG-REMEDIATION.md).

Machine-readable evidence: [0.7.3 verification](../wordpress-org/review-evidence/0.7.3/verification.json).
Name/trademark and content-only remote-scope questions remain for reviewer
clarification; capitalization is not represented as resolving a naming concern.
