# Prefix Migration (R05)

Included in version 0.7.3. Back up the database, configuration and legacy private files before
updating. A plugin-only downgrade is not supported after the
data migration; restore the matching backup instead.

## Runtime Names

- First-party PHP uses `Atshift\Deft`; locked dependencies use
  `Atshift\Deft\Vendor`. No legacy PHP namespace alias is exported.
- Script/style handles start with `atshift-deft-`. The browser globals are
  `atshiftDeftWordPress` and `atshiftDeftConnectionProfiles`.
- The prototype activation guard still reads `StructuredFields\VERSION` to
  prevent loading conflicting old/new implementations together.
- WordPress data and new registration names use the six-character `atshde_`
  prefix. This is deliberately shorter than the slug to fit post-status limits.

## Exact Saved-Data Migration

`PrefixMigration` runs before registering the plugin's tools. It recognizes the
old plugin through its schema option and required columns, not a wildcard string
replacement. It migrates only these owned identifiers:

| Area | Old identifier | New identifier |
| --- | --- | --- |
| 13 tables | `sf_` plus snapshots, draft_states, assets, transfers, reviews, catalog, components, component_usage, connections, oauth_clients, oauth_tokens, oauth_flows, publications | Same suffix with `atshde_` |
| 12 options | Exact schema-version, brand-version, behavior-state and OAuth-server-key allowlist in the migration class | Same suffix with `atshde_` |
| Roles/capabilities | Exact author/designer/publisher and five capability names | `atshde_` equivalents |
| Private preview type | `sf_article` | `atshde_article` |
| Reservation statuses | `sf_reserved`, `sf_reserve_hold` | `atshde_reserved`, `atshde_reserve_hold` |
| Publication marker | `_sf_publication` | `_atshde_publication` |
| Current-site introduction state | `_sf_ai_welcome_seen_{blog_id}` | `_atshde_ai_welcome_seen_{blog_id}` |

Table rows, serialized payloads, digest identities, owner IDs, draft history,
grant/revocation state, pending jobs, option values/autoload and encrypted OAuth
signing material are preserved. Custom capabilities and explicit denials remain
intact. Only the current site's capability metadata is changed.

The migration uses a site-specific database lock and atomic multi-table rename.
The following option/role/post updates run in an InnoDB transaction. A persistent
pending marker allows retry after the rename's implicit DDL commit. Ambiguous
old/new tables, options, metadata or contradictory capabilities fail closed;
they are not merged. Until resolved, tools are disabled and administrators see
an actionable error. The marker is completed only after the transaction commits.
This is not a zero-downtime rolling deployment across old/new PHP workers.

## Explicit Compatibility Contracts

- New admin-post links/forms use `atshde_asset`, `atshde_media`,
  `atshde_ai_guide`, `atshde_decide` and `atshde_oauth_authorize`.
  The exact old actions remain authenticated aliases.
- New reservations use `atshde_publish_article`. Existing `sf_publish_article`
  events still execute the same owner/version/revocation checks. Jobs are not
  duplicated or silently rescheduled.
- Existing administrator quota filters remain aliases underneath the new
  `atshde_private_media_limit_per_user` and
  `atshde_shared_component_limit_per_user` filters.
- Old preview post-type query values are mapped to the new private type before
  the same signed-preview authorization runs.
- REST/MCP/OAuth URLs, ability names, nonce actions, error codes, token formats,
  scopes, public media URLs, workspace links, component markup and JSON schemas
  remain stable. Renaming these would invalidate external clients or immutable
  stored content; they are versioned contracts, not unprefixed PHP declarations.
- Legacy private-file directory hashes retain the original `sf_assets` table
  identity. Existing private/frozen bytes remain read-only at their old paths.
  See [Private Storage](PRIVATE-STORAGE.md). New uploads use database chunks.

## Verification

- `tests/prefixes.test.mjs`: namespaces, isolated vendor, handles and globals.
- `tests/wordpress-prefix-upgrade.php`: run `seed` against the actual previous
  ZIP, install the candidate ZIP, then run `verify` in a new PHP process. Checks
  every row/option/role, real credentials, signing keys, owner denial, old signed
  previews, reservation hooks, legacy staged/frozen image/PDF bytes and editing
  an old draft without changing its historical version.
- `tests/wordpress-prefix-recovery.php`: repeated migration, conflicts,
  explicit-denial preservation and interruption after DDL, including exact
  signing-key bytes/autoload on recovery.
- `tests/wordpress-storage-portability.php` and
  `tests/wordpress-multisite.php`: relocated content/uploads, fresh storage,
  site-specific initialization and cross-site credential/blob isolation.

Use only disposable databases and private fixture files. Never ship fixture
credentials, WordPress configuration or request logs. Recorded run results and
environment limitations belong in [the remediation record](WORDPRESS-ORG-REMEDIATION.md).
