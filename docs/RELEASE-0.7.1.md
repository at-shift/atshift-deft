# atshift DEFT 0.7.1

Recoverable drafts and private reservations in your chosen WordPress destination.

## Getting Started

1. Install `atshift-deft-0.7.1.zip` on an isolated test site, or update an existing GitHub distribution through WordPress's plugin update screen.
2. Open DEFT and download a fresh AI guide. Give it to your usual AI client; previously downloaded guides do not update automatically.
3. Connect or resume your existing connection, compose the article and layout, choose the destination and existing categories/tags, and request publication now or at your chosen time.

Requires WordPress 6.9+ and PHP 8.2+. MCP use additionally requires the separately
installed MCP Adapter and a compatible AI client. Publication needs explicitly
delegated publishing access and the corresponding WordPress permissions.

## Changes

- Move your own saved drafts to Trash and restore them through DEFT, REST or MCP. Saved versions and files are retained; other owners' drafts remain inaccessible.
- Show the draft owner's display name and role, place DEFT below Settings, and explain the difference between the private workspace and publication destinations.
- Remove published and newly reserved articles from the draft list without deleting data needed to render them.
- When scheduling, create a private `Scheduled (DEFT)` entry in the selected Posts, Pages or eligible custom-type list. Revalidate the pinned publication before publishing into that same entry.
- Return cancelled, invalidated or failed reservations to drafts. Prevent native editing or premature publication from bypassing DEFT checks.
- Let publishing connections rediscover their own publication jobs; refresh AI instructions, user guides and the partial Japanese catalogs.

## Update and Verification

The GitHub ZIP includes the verified GitHub updater, translation files and the
same runtime features as the separately submitted WordPress.org package. The
two packaged guides now link to this release; the submitted ZIP is unchanged.
The attached SHA-256 and `atshift-deft-update.json` identify the exact release ZIP.

ZIP SHA-256: `1b02bc51c16aa8b33bc4bae17d0e4149f5a155e66fdc9cff535e84bfaded381d`

Validation used the actual installable ZIP on disposable WordPress 7.1 / PHP
8.3.36 sites:

- 440 runtime regression checks passed on a clean installation.
- 69 GitHub updater checks passed, including a native WordPress upgrade from the published 0.7.0 ZIP to 0.7.1, existing data/settings preservation, and tampered-download rejection. Release HTTP responses were controlled fixtures for this pre-publication test.
- 21 distribution, translation, identity and connection-guidance tests passed; all 332 packaged PHP files passed syntax lint.
- Plugin Check 2.1.0 reported only the expected `plugin_updater_detected` error for this GitHub-only distribution, plus 343 reviewed warnings. The separately submitted WordPress.org ZIP excludes the updater and was verified with zero errors. See the [review notes](https://github.com/at-shift/atshift-deft/blob/v0.7.1/docs/WORDPRESS-ORG-REVIEW-0.7.1.md) for the retained-warning rationale.

GitHub distributions from 0.6.0 onward can receive this release through WordPress
updates. Metadata is cached for up to six hours. Automatic updates remain the
site owner's choice. Version 0.5.x needs one manual ZIP replacement first.

This is an early development release. Back up and use an isolated test site.
Publishing remains an initial-publication workflow; later draft saves do not
update an already-published post. Legacy scheduled jobs without destination
entries keep their existing behavior until rescheduled or executed. Scheduled
publication relies on WP-Cron and is not a guarantee of exact-time execution.

The WordPress.org 0.7.1 application is awaiting review. This GitHub Release is
not directory approval. No existing site's settings or content are modified
by publishing the release itself, and the v0.7.0 assets are not replaced.

See [the installation guide](https://github.com/at-shift/atshift-deft/blob/v0.7.1/docs/GETTING-STARTED.md)
and [the changelog](https://github.com/at-shift/atshift-deft/blob/v0.7.1/CHANGELOG.md).
