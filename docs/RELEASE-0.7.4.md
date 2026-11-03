# atshift DeFt 0.7.4

This maintenance release fixes draft listing when WordPress and DeFt use different database collations for post-type identifiers. On affected installations, saved drafts could appear missing in the workspace and AI draft list even though the data remained intact.

## Changes

- Compare post-type identifiers as exact binary strings during draft listing, avoiding incompatible database collation comparisons.
- Preserve saved content, database column definitions, connection permissions and publication rules. No database collation conversion is required.
- Add mixed-collation regression coverage for listing, reservations, cancellation, publication and failure recovery; make reservation tests fail explicitly on SQL errors.

## Install or Update

Install `atshift-deft-0.7.4.zip`, not GitHub's source-code archive. The release also includes the ZIP's SHA-256 checksum and `atshift-deft-update.json` for the existing GitHub updater. Partial Japanese catalogs and the Japanese guide remain included.

GitHub installations from 0.6.0 onward can receive this version through WordPress's normal plugin updater, subject to the site's update preferences and metadata cache. Existing 0.5.x installations require one manual ZIP replacement. Back up the database and files before updating.

The official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) 0.7.1+ must be installed and active for AI connections. When upgrading from a version before 0.7.3, the [migration requirements](https://github.com/at-shift/atshift-deft/blob/v0.7.4/docs/PREFIX-MIGRATION.md) still apply: use InnoDB core tables, do not run old and new plugin workers together, and do not downgrade only the plugin files after migration.

## Distribution Scope

This is a **GitHub-only release**. The existing WordPress.org submission remains at 0.7.3; its ZIP, submission branch and review correspondence are not updated by this release. Earlier GitHub tags and assets remain unchanged.

## Verification

The actual GitHub ZIP was installed through the native WordPress updater from the released 0.7.3 package in a disposable WordPress 7.1 / PHP 8.3.36 / MySQL 8.4.9 environment. The unpatched package reproduced the collation error; the installed update passed 188 reservation and draft-lifecycle checks, 69 updater checks and 81 translation/onboarding/MCP-prerequisite checks. All 52 static/package tests passed, all 431 installed files matched the ZIP, and all 334 packaged PHP files passed syntax lint.

These are focused maintenance-release checks, not a new full security audit or a rerun of the complete 0.7.3 browser/Plugin Check matrix. Minimum supported engines were not rerun. See the [verification record](https://github.com/at-shift/atshift-deft/blob/v0.7.4/docs/VERIFICATION-0.7.4.md).
