# atshift DeFt 0.7.4 Verification

Date: 2026-11-03 (Japan Standard Time).

## Scope and Artifact

- GitHub-only maintenance release. Do not update the WordPress.org application, its submission branch or its retained 0.7.3 ZIP for this task.
- GitHub ZIP: `atshift-deft-0.7.4.zip`, 798674 bytes, 431 files.
- SHA-256: `390477f887993c1244c7ad2051f55df8c7ea56c885a8f1b3d8d09f16613f23c5`.
- Companion assets: `atshift-deft-0.7.4.zip.sha256` and `atshift-deft-update.json`.
- Japanese PO/MO and JavaScript catalogs, Japanese guide and GitHub updater are included. No new translatable runtime strings were added; catalog project versions and source references were refreshed.
- A local WordPress.org-format 0.7.4 archive was built only for the channel-difference tests. It is not a submitted or published artifact.

## Reproduction and Fix

The released 0.7.3 package can fail to list saved drafts when the core `posts.post_type` column uses `utf8mb4_general_ci` and DeFt's publication column uses `utf8mb4_unicode_520_ci`. This is a failed read, not deleted content.

`Store::listing()` now compares those machine identifiers using `CAST(... AS BINARY)` on both sides. It does not change database collations, column definitions, content, access control or publication authorization. The reservation test helper now fails explicitly on SQL errors, and `tests/wordpress-draft-collations.php` runs the reservation lifecycle with deliberately mismatched collations before restoring the original definitions. This test requires a guarded disposable local database.

## Executed Checks

Environment: disposable WordPress 7.1, PHP 8.3.36, MySQL 8.4.9, MCP Adapter active. External WordPress HTTP requests were blocked. Updater tests provided controlled release metadata and the exact candidate ZIP through the existing HTTP fixture.

1. Installed the released GitHub 0.7.3 ZIP and reproduced the mixed-collation SQL error. The expected failing case is proof of reproduction, not an unresolved candidate failure.
2. Ran `tests/wordpress-github-updater.php` from the old package: 69 checks passed, including native upgrade to the candidate, integrity checks, unchanged saved state and unchanged automatic-update preferences.
3. Compared all 431 installed files against the exact candidate ZIP after the upgrade.
4. Ran `tests/wordpress-draft-collations.php`: 61 reservation checks passed with mixed collations.
5. Ran `tests/wordpress-reservations.php`: 61 checks passed with the original collations.
6. Ran `tests/wordpress-draft-trash.php`: 66 checks passed, including owner boundaries and restoration.
7. Ran WordPress translation, onboarding and active-MCP-prerequisite suites: 20, 34 and 27 checks passed respectively.
8. Ran distribution, branding, prefix, connection-profile, MCP guidance, destination guidance, media-schema, WebMCP registration and i18n Node suites: 52 tests passed.
9. PHP syntax lint passed for all 334 packaged PHP files. ZIP integrity, PO format checks and `git diff --check` passed.
10. Deleted the disposable database after the run. No live site was manually updated during this release preparation.

Total: 338 runtime assertions and 52 static/package tests passed. Early harness attempts stopped on fixture path/guard mismatches; these were corrected only in the disposable harness, and the complete final run passed. All databases created by those attempts were removed.

## Limits

- Minimum PHP 8.2 / WordPress 6.9 engines, the complete browser matrix and Plugin Check were not rerun for this narrow patch. The previous verification record remains specific to 0.7.3.
- No broad performance or independent security audit is claimed.
- The same comparison fix was separately validated on the affected MySQL 5.7 site before release preparation. That site-specific hotfix is not an installation of this 0.7.4 artifact.
- Public asset download/hash verification is performed after release publication; preparing this record alone is not proof of publication.
