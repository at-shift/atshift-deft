# atshift DeFt for WordPress 0.7.4

0.7.4 fixes draft listing across different WordPress and DeFt post-type collations without rewriting saved content or database columns. See [release notes](../docs/RELEASE-0.7.4.md) for verification and upgrade requirements.
Installable ZIPs and checksums are distributed through GitHub Releases. Earlier release assets are unchanged.
For installation and everyday use, see the [Installation and User Guide](../docs/GETTING-STARTED.md).
See [V0.5.0.md](../docs/V0.5.0.md) for identity and legacy activation steps.

Alpha.14 was the preceding private GitHub code checkpoint. Its OAuth and runtime changes remain:
[ALPHA14.md](../docs/ALPHA14.md). Existing keys and separately delegated publication
remain unchanged. Version 0.7.4 is released through GitHub only; the existing WordPress.org submission remains at 0.7.3.
This does not imply a WordPress.org release or a production installation.

Development candidate, not for production use. Earlier `0.2.0` labels were local-only
prototype labels and were never released. The browser package and WordPress
plugin use the same development version.
Known issues and deferred work are tracked in `../docs/FOLLOW-UP.md`.
Japanese installation and usage instructions are in
[GETTING-STARTED.ja.md](atshift-deft/GETTING-STARTED.ja.md), included only in the GitHub ZIP along with Japanese catalogs. The WordPress.org ZIP is English-only and excludes the GitHub updater; it retains WordPress language-pack support.
Current MCP additions are in [ALPHA10.md](../docs/ALPHA10.md), extending [ALPHA9.md](../docs/ALPHA9.md).

This is a separate plugin, not an update to atshift Fields. It runs on WordPress's
existing PHP/MySQL server. Node, SQLite and a separate daemon are not required on
the hosting server. The browser prototype and its local review server remain
available, but are not automatically synchronized with WordPress.

## Scope

- Owner-only article operations, with administrator-only import of new HTML/CSS.
- Shared catalog with delegated registration/management capabilities and immutable revisions.
- Owner-private bounded recipes, reuse checks, duplicate detection and quotas.
- Site-wide content-free structures, automatic duplicate reuse and private usage lists.
- Dedicated author role for active catalog or the same article's saved component revisions.
- ID/value-only composition API and minimal manual article editing.
- Bundled native/CSS-first behaviors, semantic targets and typed instance settings.
- PHP-side schema, type, component SHA-256, HTML binding and field validation.
- Immutable snapshots, compare-and-swap version checks and transactional draft creation.
- Read/export the stored JSON and preview its exact content through the active theme.
- Private preview URLs require the current WordPress login and a version-bound nonce.
- Article-bound image/PDF references with pinned hashes; reference-only files excluded.
- Read-only reuse of supported local WordPress media without creating attachments.
- Exact-file delegated transfer and a separate account-authenticated review screen.
- Four draft MCP tools, plus four separately authorized authoring tools; three
  publication tools are available on the opt-in publishing endpoint.
- AI-set immediate/scheduled initial publication; no public draft previews or verified-human-presence assertions.

Complete arbitrary design snapshots require `manage_options`. Raw shared registration
also accepts `sf_register_components`, and updates require `sf_manage_components`.
The opt-in designer role grants both. Bounded content-free structures can be newly
registered by any workspace author; changing existing shared definitions still requires
management authority. Personal recipes remain as private exceptions. Neither recipe
path accepts raw HTML/CSS or scripts. Successful changed saves record usage automatically.
The composition API references server-owned, active component revisions or this
article's saved revisions; it accepts no HTML/CSS. Scoped AI connection credentials
are owner-issued. Catalog registration is not approval to publish an article.
WordPress login/nonce is session authorization, not proof that a human approved
this specific content. Draft saves do not authorize publication; a separate publishing
connection or publisher browser session is required to set publication.
Alpha.4 records an explicit approval decision by the logged-in account, separately
from transfer credentials. It cannot distinguish a human from automation using
the same privileged browser session.

## Transfer and Review

See [TRANSFER-REVIEW.md](../docs/TRANSFER-REVIEW.md) for scoped upload grants,
the Node transfer client and the account-approval boundary. In a saved v2 article,
**Review** opens the pinned content/asset set and active-theme preview. Approval,
requesting changes and withdrawal never publish the article. A new saved version
or changed file invalidates the earlier approval. Unpinned article images and
CSS image URLs must be replaced before this review flow can be used.

HTTPS is required, with an explicit exception for local HTTP on 127.0.0.1.
Alpha.13 authoring connections can issue exact-file transfer grants without another
WordPress visit. Chat attachment bytes and HTTP upload capability must be supplied by
the AI client. Revocation/expiry of the issuing connection invalidates these grants.
Portable previews are private HTML exports, not public links or active-theme renders.

## Components and Composition

Open **Components** to register one component JSON, inspect its fields and export
the API schemas. Shared changes require the corresponding capability; owners manage
their personal recipes. The AI handoff download and proposal import work without a
direct AI connection; see the packaged getting-started guide.
Open **Articles > New article** to choose active components, enter values, select
variants and reorder/remove blocks. Saved articles keep the editor collapsed so
the theme preview remains primary. No automatic AI connection is implied.

Catalog updates never rewrite saved articles. Inactive or superseded revisions
cannot be newly introduced, but can remain in the owner's same saved article.
See [WORDPRESS-CATALOG.md](../docs/WORDPRESS-CATALOG.md) for the AI/API workflow,
limits and the distinction between immutable hashes and CAS versions.

## Installation

The installable folder is `wordpress/atshift-deft/`. Copy that folder to a
test site's `wp-content/plugins/`, activate it, and open **atshift DeFt**.
The committed schema and sample are already included. Development regeneration:

```sh
npm run build:wordpress
```

Requirements: WordPress 6.9+, PHP 8.2+ with DOM, mbstring, OpenSSL and Sodium, MySQL/MariaDB with
InnoDB and named locks. Current runtime verification is WordPress 7.1, PHP 8.3.36,
MySQL 8.4 and Twenty Twenty-Five; the minimum-version matrix and other themes
have not yet been verified. This is not a production release.

## Article Transfer

1. In the browser prototype, use **WordPress用スナップショットを書き出す**.
2. In WordPress, choose **Import snapshot**, then **Save draft**.
3. Read the saved version from the article list and inspect **Open theme preview**.

The WordPress screen also has a small **Load sample** fixture. It does not call AI.
Imported JSON is revalidated on Save, not merely trusted because the browser
accepted it. An article with changed contents needs an incremented article
revision; an old expected WordPress version returns 409 instead of overwriting.

Media files are not embedded or uploaded by the snapshot transfer. The
private-intake API and **Private files** panel accept images/PDFs after a draft is
saved. In alpha.3, **Article media** binds publication candidates to image/PDF
fields, with per-use alternative text or link text. Existing local WordPress
media can also be searched and reused; originals and their metadata are not changed.
Choose **Media sample**, save it, then upload publication candidates or search
the existing library, select the sources, enter contextual text, and save again.
Both the saved references and the preview pin the exact bytes with SHA-256.
See [private storage and upgrade compatibility](../docs/PRIVATE-STORAGE.md) and
[v2 references, API and limits](../docs/MEDIA-REFERENCES.md).

Old URL-based v1 snapshots remain supported. Root-relative URLs resolve on the
WordPress site. The isolated test
site contains a copy of `public/assets/atelier.png` at `/assets/atelier.png` only
to exercise the existing sample. External media/CSS/font requests are blocked
in preview by CSP. WordPress v2 snapshots are not accepted by the browser/local
review server's v1 import; no media references are silently discarded.

The theme supplies the article title and page layout. The plugin supplies the
structured content and component CSS, in article block order. It bypasses
`the_content` filters only on its private preview request: values are plain text,
so shortcodes, embeds and block directives must not execute. No filter is removed
from ordinary posts. Theme/header/footer behavior otherwise stays theme-owned.
The theme and URL-based media can change later; this is not a frozen visual proof.
Pinned media references fail closed if the file changes, disappears or becomes
inaccessible. Existing attachment URLs retain their original access behavior.

## Storage and API

Snapshots live in `{prefix}sf_snapshots`. Each snapshot has a separate internal
`sf_article` draft for theme preview; these are hidden from ordinary post lists,
REST posts, search and public archives. The plugin forces this type to remain
draft even if `wp_update_post()` requests publication. No existing post is modified.
There are up to 100 snapshots per article; the current list displays up to 100
articles. Deactivation retains data. Uninstall cleanup/retention UI is not implemented.

- `GET /wp-json/structured-fields/v1/articles`: latest saved articles.
- `GET /wp-json/structured-fields/v1/articles/{id}`: read current snapshot.
- Same read with `?version=N`: read an older immutable snapshot.
- `POST /wp-json/structured-fields/v1/articles`: `{snapshot, expectedVersion}`.
- `POST /wp-json/structured-fields/v1/articles/compose`: `{article, expectedVersion}`.
- `GET /wp-json/structured-fields/v1/catalog`: active component descriptors.
- `GET /wp-json/structured-fields/v1/catalog/schema`: registration and composition schemas.
- `POST /wp-json/structured-fields/v1/catalog/structures`: bounded content-free shared recipes.
- `GET /wp-json/structured-fields/v1/catalog?scope=used`: the caller's used, available components.

Article writes are JSON-only, maximum 4 MiB, owner-only. Catalog writes are limited to
512 KiB. Raw designs require the delegated design capability (complete snapshot imports
require an administrator); authors can register bounded content-free structures and use
active catalog or their article's saved components. Cookie authentication
also requires WordPress's REST nonce. API and previews send no-store headers.
WordPress IDs use ASCII letters, digits, underscores and hyphens (max 80).
URLs must be absolute HTTP(S), root-relative, or link-only mailto/tel/fragment;
ambiguous relative paths and protocol-relative URLs are rejected. The PHP boundary
is intentionally stricter here than the early browser-only prototype.

## Isolated Local Validation

Test site: http://127.0.0.1:8128/wp-admin/admin.php?page=structured-fields

The local installation in `.local/wordpress/` uses its own `sf_prototype_local`
database and `sfwp_` table prefix. Existing Fields test environments are untouched.
`.local/wp-credentials.json` contains only the generated local test account and is
git-ignored, mode 0600, outside the site's document root. Setup never resets it.
The setup script expects a prepared WordPress core, config and plugin symlink;
it is not a general hosted-site installer.

```sh
php scripts/setup-wordpress-local.php
php -S 127.0.0.1:8128 -t .local/wordpress
npm run test:wordpress
# Save Load sample once through the UI before HTTP tests.
npm run test:wordpress-http
npm run test:wordpress-assets
npm run test:wordpress-media
npm run test:wordpress-workflow
npm run test:wordpress-users
npm run test:wordpress-catalog
npm run test:wordpress-personal
npm run test:wordpress-structures
npm run test:wordpress-behaviors
npm run test:wordpress-onboarding
```

The PHP suite creates disposable records and removes only those records. HTTP
tests require the isolated local account and read existing sample previews; they
do not publish content. Source code, schema and tests belong in Git; local core,
database files, login cookies/credentials and test-site data do not.

## Next Boundary

See [MULTI-USER.md](../docs/MULTI-USER.md) for role assignment, first-creator ownership,
parallel sessions and the distinction between plugin isolation and host administrator trust.

Alpha.13 exposes scoped draft/authoring abilities through the official MCP Adapter;
validate each chosen AI client's connection, file access and artifact viewer separately.
Real provider OAuth onboarding verification, trusted chat-side approval proof and media-library promotion remain
later stages. Initial publishing exists only with separately delegated authority.
