# WordPress.org Submission Update: atshift DEFT 0.7.1

Prepared and submitted: 2026-11-01 (JST).
Existing application: `atshift-deft`, awaiting its first review when checked.
Runtime source: `b0500f21661a3b5f52665a86ebe0a941c78c631e`, plus the 0.7.1
version, changelog, guide and translation project-header updates.
Submission ZIP: `atshift-deft-0.7.1-wordpress-org.zip` (698927 bytes).
SHA-256: `4d52ee93596a09539ea08f8f12eed59bcbe1f53091fbeb7e1a934047fe472edb`.
Local artifacts: `.local/v071-submission/`.
The previous submission record is preserved in `WORDPRESS-ORG-REVIEW-0.7.0.md`.

## Submission Result

The existing application at https://wordpress.org/plugins/developers/add/ accepted
the update and displayed `New version of atshift DEFT uploaded for review.`
The newest submitted-file entry is `atshift-deft-0.7.1-wordpress-org.zip`, version
`0.7.1`, with the complete additional information and SHA-256 below. The earlier
0.7.0 entry remains in the submission history. The assigned slug is unchanged.

Status remains `Awaiting Review - This plugin has not yet been reviewed.` This
is an accepted submission update, not approval or a public directory release.
No separate successful automated-scan status was shown on this update page;
the local installed-artifact Plugin Check result is recorded independently.

Evidence: `.local/v071-submission/wordpress-org-updated.png` and
`.local/v071-submission/plugin-check.json`. The disposable test database and
generated credential fixtures were removed. No existing site was modified.
The GitHub v0.7.0 Release, tag and assets remain unchanged.

## Additional Information for Reviewers

This updates the existing atshift DEFT application (slug: atshift-deft) from
0.7.0 to 0.7.1. It is not a new plugin application or a request to change the slug.

Source, build instructions and regression tests:
https://github.com/at-shift/atshift-deft
Runtime implementation:
https://github.com/at-shift/atshift-deft/tree/b0500f21661a3b5f52665a86ebe0a941c78c631e
The candidate adds version/documentation/catalog-header updates to that code.

Uploaded file: atshift-deft-0.7.1-wordpress-org.zip (698927 bytes).
SHA-256: 4d52ee93596a09539ea08f8f12eed59bcbe1f53091fbeb7e1a934047fe472edb

This update adds recoverable owner-only draft trash/restoration, explicit owner
information, and private reservation entries in each chosen post/page/custom-type
list. Published and newly reserved articles leave DEFT's draft list without
deleting their stored content. Cancellation or failed revalidation returns a
reservation to drafts. Reserved entries contain no public article body and do
not use WordPress's future status. DEFT rechecks the owner, delegated access,
snapshot, files and destination/classifications before publishing into the same
entry. Native editing and premature publication cannot bypass these checks.

DEFT uses the user's separately authorized MCP client, not a bundled AI backend.
MCP Adapter is separately installed; DEFT does not download or install it.
Drafts are owner-isolated, and publishing requires separate permission plus
native destination capabilities. There is no provider API key, telemetry, paid
feature unlock or arbitrary executable PHP/JavaScript intake. Behaviors select
fixed, shipped, hash-verified assets. Resource quotas are safety/concurrency limits.

The actual WordPress.org ZIP was installed in a disposable WordPress 7.1 / PHP
8.3 environment. It passed 440 runtime checks (reservation lifecycle, trash and
owner isolation, destinations, taxonomies, appearance, public structure and
publication guidance). Seventeen packaging, translation and connection-guidance
tests passed, as did lint for all 331 packaged PHP files.

Local Plugin Check 2.1.0 completed with 0 errors and 347 warnings. We reviewed
the warnings rather than globally suppressing them:

1. 159 DirectQuery and 137 NoCaching warnings: DEFT uses custom tables for
   immutable article versions, credentials, ownership, components, files and
   publication jobs. Queries use prepared SQL or wpdb's structured operations.
   Locks, transactions and engine checks support concurrent state transitions.
   Permission/revocation/version/quota checks require authoritative state after
   locks are acquired. Immutable/list queries also use this uncached repository
   layer; not every query is inherently uncacheable. We retained the tested
   implementation instead of adding incomplete caching solely to silence lint.

2. 23 InputNotSanitized warnings: callers type-check and unslash request values,
   then strictly validate identifiers, versions, allowlisted decisions and exact
   nonces. Transforming an invalid identifier into another valid identifier is
   not acceptable. This applies to Assets::serve(), Media::serve(),
   Access::preview_caps(), the private-preview callback, Review's decision and
   authorization paths, and PublicationMedia::bytes(). DOCUMENT_ROOT is resolved
   with realpath and checked for public/private directory separation.

3. 4 MissingUnslash warnings: REQUEST_METHOD is compared only to exact allowed
   GET/HEAD/POST strings and is not stored or interpolated into SQL/output.
   Payload values are unslashed separately; malformed method tokens are rejected.

4. 6 NonceVerification.Missing and 8 NonceVerification.Recommended warnings:
   Review::submit() delegates to Review::decide(), which verifies the exact nonce,
   authenticated session, capability, owner, manifest and allowed decision before
   mutation. Review display is read-only and owner/capability checked; plugin.php
   only detects that screen for privacy headers. Public publication media is an
   intentional read-only GET/HEAD route. It validates identifiers, publication
   state, public post type/status/owner/password, membership and byte integrity
   per request. A visitor nonce is neither appropriate for public media nor a
   substitute for those checks. Private/reserved/withdrawn content is not served.

5. 1 SchemaChange warning: installation conditionally removes the obsolete
   machine_key index from DEFT's own table. dbDelta creates the owner_key
   replacement but cannot remove the old index. This preserves owner-scoped keys.

6. 4 hook-prefix and 2 namespace-prefix warnings: sf_private_media_limit_per_user
   and sf_shared_component_limit_per_user preserve the prototype's sf_ hooks for
   compatibility. atshift_deft_public_structure_enabled and
   atshift_deft_json_ld_enabled already use the product-specific prefix.
   AtshiftDeft in i18n.php and legacy-guard.php is the product-specific namespace;
   the main implementation retains StructuredFields for compatibility. These
   are explicit plugin identifiers, not unprefixed generic globals. Renaming
   externally usable identifiers merely to match checker inference would break
   existing integrations; we can discuss any specific reviewer concern.

7. 1 load_plugin_textdomainFound warning: registration runs on init (or after
   init during activation), supporting bundled partial Japanese translations,
   runtime guards and the independent distribution. Both packages use this loader.

8. 2 unexpected_markdown_file warnings: AI-GUIDE.md supplies the downloadable
   runtime AI instructions; GETTING-STARTED.ja.md is the Japanese installation
   and usage guide referenced by readme.txt. These are intentional user/runtime
   documents, not development or test files.

The separate GitHub ZIP intentionally includes a GitHub updater and would trigger
plugin_updater_detected. It is not the submitted ZIP. The WordPress.org ZIP has
no Update URI, updater class/bootstrap or GitHub update HTTP requests. It includes
the same core and translation files. The previously rejected non-runtime
build-phar.sh remains excluded with an exact dependency manifest. We are not
requesting an exemption to WordPress.org's external-updater rule.

The published GitHub v0.7.0 Release and assets have not been replaced. Updating
this pending application does not install the plugin on any existing website.
These results do not prove production readiness or replace manual review. We
will address specific issues raised by the Plugins Team.

## Local Evidence

- ZIP CRC, 425-file inventory, dependency hashes and channel separation passed.
- Japanese PO/MO and JavaScript catalogs match across both candidate packages.
- Actual installed ZIP: 61 reservation, 69 draft trash, 74 public-structure,
  95 destination, 63 taxonomy, 53 appearance and 25 guidance checks passed.
- Seventeen Node tests passed after specifying the test WordPress root, WP-CLI
  and candidate ZIP paths; initial missing fixture-path errors were harness
  configuration, not product failures.
- All 331 packaged PHP files passed syntax lint. Plugin Check: 0 errors / 347 warnings.
- Compared with the previously browser-tested development ZIP, runtime PHP/JS/CSS
  are unchanged except the main plugin's version strings. Only seven packaged
  files differ (version metadata, changelog/guides and translation headers).
  Browser interaction was not repeated for this metadata-only candidate rebuild.
- Evidence files are retained in `.local/v071-submission/`; no test credentials
  or real site data are included in either candidate archive.
