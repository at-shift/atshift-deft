# atshift DeFt 0.7.3

This release includes WordPress.org review remediation, the DeFt icon and a clearer separation between distribution channels. The official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) 0.7.1 or newer must be installed and active before connecting an AI client.

## Changes

- Standardize the display name as **atshift DeFt**, keeping the `atshift-deft` slug, text domain and existing API/MCP connection addresses.
- Isolate PHP and dependencies under `Atshift\Deft` and migrate known legacy identifiers to `atshde_`, preserving owners, draft history, capabilities, signing material, connections and reservations. Ambiguous migration conflicts stop safely.
- Store new private uploads and frozen publication media in bounded, hash-verified database chunks. Existing private files remain readable without rewriting them; existing Media Library paths use WordPress APIs.
- Harden canonical nonce/OAuth input handling, preserve opaque OAuth state, retain review privacy headers and prevent AI connections from dispatching unrelated nested REST requests.
- Preserve unrelated title-filter output while escaping plugin-owned replacements. Add focused upgrade, recovery, storage, HTTP and multisite regression coverage.
- Include the prepared DeFt icon.

## Packages

- **GitHub ZIP:** GitHub updates through the normal WordPress updater, plus partial Japanese PO/MO and JavaScript catalogs and the Japanese guide.
- **WordPress.org submission ZIP:** English-only, no GitHub updater or bundled locale catalogs/Japanese guide. WordPress translation support and the POT template remain. Directory updates and language packs require approval and publication; submitting this ZIP is not a directory release.

Install `atshift-deft-0.7.3.zip`, not GitHub's source-code archive. The release also provides its SHA-256 checksum and exact update metadata.

## Before Updating

Back up the database and files and test on an isolated site first. Legacy-data migration requires InnoDB core tables. Do not run old and new plugin workers together, or downgrade only plugin files after migration; restore the matching database/files backup instead. Keep legacy private-directory configuration while existing files still reference it.

Download a fresh AI guide after updating. Private storage is access-controlled, not encrypted at rest. This is an early release, not a claim of an independent security audit. See [migration requirements](https://github.com/at-shift/atshift-deft/blob/v0.7.3/docs/PREFIX-MIGRATION.md) and [private storage](https://github.com/at-shift/atshift-deft/blob/v0.7.3/docs/PRIVATE-STORAGE.md).

## Verification

The actual ZIPs were tested on disposable WordPress 7.1 / PHP 8.3.36 sites. Checks covered migration from the released 0.7.2 package, 29 PHP suites (1,256 assertions), 130 HTTP tests, 52 static/package tests, relocated storage, two-site isolation and desktop/mobile save-preview-trash-restore workflows. Both channels passed MCP prerequisite checks with the adapter active and inactive. The GitHub ZIP also passed Japanese UI checks and native WordPress upgrades using controlled GitHub responses (69 checks with the released updater and 69 with the new namespace).

The English-only submission ZIP passes Plugin Check with **0 errors and 404 reviewed warnings**. These are retained database operations, explicit/compatibility prefixes, helper-based authorization, translation registration and the runtime AI guide; no blanket suppression was added. All 333 PHP files in that ZIP pass syntax lint. See the [verification record](https://github.com/at-shift/atshift-deft/blob/v0.7.3/docs/WORDPRESS-ORG-REVIEW-0.7.3.md) for evidence and limitations. Minimum PHP 8.2 / WordPress 6.9 engines and live provider reconnection were not rerun in this pass.
