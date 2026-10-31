# WordPress.org Submission Notes: atshift DEFT 0.7.0

Prepared: 2026-11-01 (JST).
Runtime source: `9c80f9915d8f9732bfffae89994d8210cf34c825`.
Packaging-only correction: exclude upstream `build-phar.sh` and update its file manifest.
Submission ZIP: `atshift-deft-0.7.0-wordpress-org.zip` (686071 bytes).
SHA-256: `5b30827ca15b3f120546bef43fc2966428106a260cdb3a46fb6bb32f454d954d`.
Local artifact directory: `.local/v070-submission/`.

## Submission Result

Confirmed on 2026-11-01 at approximately 02:01 JST at
https://wordpress.org/plugins/developers/add/ :

- Accepted for review with the initial slug `atshift-deft`.
- Review status: `Awaiting Review - This plugin has not yet been reviewed.`
- Automated Plugin Scanning: `Pass`.
- The uploader's displayed scan retained only the two unexpected Markdown
  warnings; the separate local full Plugin Check report still has 327 warnings.
- Additional Information displays the corrected ZIP size/hash, packaging fix and
  explanations below. Acceptance for review is not approval or directory release.
- Evidence: `.local/v070-submission/wordpress-org-submitted.jpg` and
  `.local/v070-submission/plugin-check.json`.
- This follow-up records the packaging fix, regression test and review notes.
  GitHub release assets and existing sites are unchanged. The GitHub ZIP generated
  in `.local/v070-submission/` is for packaging comparison tests only, not a new
  published asset. Disposable verification database and credentials were removed.

## Additional Information for Reviewers

Thank you for reviewing atshift DEFT 0.7.0 (requested slug: atshift-deft).
The source, build instructions and tests are available at
https://github.com/at-shift/atshift-deft/tree/v0.7.0 .
The uploaded ZIP uses the wordpress-org distribution, not the GitHub release ZIP.

The initial uploader check rejected the upstream dependency development script
vendor/paragonie/random_compat/build-phar.sh. This was not reported by local
Plugin Check. We removed that non-runtime script, updated dependency-manifest.json
to describe the exact packaged files, and reran installation, Plugin Check and
305 runtime regression checks. No runtime or translation file changed. The
published GitHub release was not replaced. This packaging-only correction is
included in the submitted 0.7.0 ZIP.

DEFT lets a user's chosen AI client compose structured articles, reusable layouts
and appearance settings through explicitly authorized MCP connections. Drafts are
owner-isolated; publishing requires separate permission and native destination
capabilities. MCP Adapter is a separately installed dependency for MCP use; DEFT
does not download or install it. There is no bundled AI service, provider API key,
paid feature unlock or telemetry. Resource limits are safety/concurrency bounds,
not commercial quotas. External-client disclosures are in readme.txt.

The plugin does not accept arbitrary PHP or JavaScript for execution. Interactive
behaviors select shipped, hash-verified assets from a fixed registry. Article
values, component markup and appearance settings are validated separately.

Plugin Check 2.1.0 was run against the installed submission ZIP in an isolated
WordPress 7.1 / PHP 8.3 environment: **0 errors and 327 warnings**. The remaining
warnings were reviewed rather than globally suppressed. Their reasons follow.

1. **149 DirectQuery and 131 NoCaching warnings.** DEFT has custom tables for
   immutable article versions, component revisions, ownership, credentials,
   transfers and publication jobs. These use prepared SQL or wpdb's structured
   insert/update/delete methods. Named database locks and transactions coordinate
   concurrent requests; schema/engine checks verify prerequisites. Authorization,
   revocation, current revision and quota decisions read authoritative state,
   including after acquiring locks. Caching those decisions without a complete
   invalidation scheme could preserve stale permissions or lose concurrent edits.
   Read-only immutable/list queries also currently use this uncached repository
   layer; they are not all inherently uncacheable. We retained that implementation
   instead of adding caching solely to silence the checker. These are intentional
   database operations, not assertions that every query requires a permanent
   exemption from performance review.

2. **23 InputNotSanitized warnings.** Request values are string-checked and
   unslashed, then rejected unless they match strict identifiers, exact saved
   versions, allowlisted decisions or the exact nonce. Sanitizing a malformed
   identifier into a valid different identifier would weaken these checks.
   See Assets::serve(), Media::serve(), Access::preview_caps(), the private-preview
   callback in includes/plugin.php, Review::decide()/authorize_page(), and
   PublicationMedia::bytes(). DOCUMENT_ROOT is type-checked, unslashed and checked
   with realpath plus public/private directory separation, not used as free text.
   REQUEST_METHOD is only compared to fixed GET/HEAD/POST values.

3. **4 MissingUnslash warnings.** These are the REQUEST_METHOD comparisons in
   Assets::serve(), Media::serve(), Review::submit() and PublicationMedia::serve().
   The method is neither stored nor interpolated into output/SQL. Only exact
   allowed HTTP method strings are accepted; changing malformed tokens is not
   needed. Request payload fields are unslashed separately.

4. **6 NonceVerification.Missing and 8 NonceVerification.Recommended warnings.**
   Review::submit() delegates to Review::decide(), which verifies the exact
   review/manifest nonce, authenticated cookie session, capability, owner and
   allowlisted decision before mutation (class-review.php:112-141). The review
   display is read-only and checks owner/capability. plugin.php:184 only detects
   that screen to add privacy headers. PublicationMedia::serve() is an intentional
   public, read-only GET/HEAD route: bytes() validates IDs, the publication record,
   current public post status/type/owner/password constraints, file membership and
   byte integrity on every request. A private/scheduled/withdrawn publication is
   not served. An expiring visitor nonce would break public published media and
   would not substitute for those state checks.

5. **1 SchemaChange warning** (class-catalog.php:60). Installation conditionally
   removes the old machine_key index from DEFT's own table. The replacement
   owner_key index is created through dbDelta; dbDelta does not remove obsolete
   indexes. This preserves owner-scoped component names when upgrading.

6. **2 NonPrefixedHooknameFound warnings.** sf_private_media_limit_per_user and
   sf_shared_component_limit_per_user retain the existing Structured Fields
   prototype's sf_ prefix so existing site integrations continue to work after
   the DEFT rename. Neither is an unprefixed generic hook.

7. **1 load_plugin_textdomainFound warning.** Translation registration is deferred
   to init (or run after init during activation) for the bundled partial Japanese
   translations and runtime guards. It also supports the independently distributed
   package. The WordPress.org and GitHub builds share this translation loader.

8. **2 unexpected_markdown_file warnings.** AI-GUIDE.md is runtime input for the
   downloadable AI instructions/catalog context. GETTING-STARTED.ja.md is the
   Japanese user installation/usage manual referenced by readme.txt. These are
   intentional user/runtime documents, not development logs or test artifacts.

**Separate GitHub-only error:** Plugin Check reports plugin_updater_detected for
the GitHub release ZIP because that distribution intentionally has an Update URI
and GitHub updater. It is not being submitted here. The uploaded WordPress.org
ZIP contains no Update URI header, updater bootstrap or class-github-updater.php;
it does not fetch GitHub updates. Core features and translation files are otherwise
the same. We are not requesting an exception to the WordPress.org updater rule.

These explanations describe our review of the remaining findings, not a claim
that Plugin Check proves security or that reviewer feedback can be ignored. We
will address specific issues identified during review.

## Local Evidence

- The submission ZIP passed ZIP CRC and SHA-256 verification again before upload.
- Compared with the original submission ZIP, only build-phar.sh was removed and
  vendor/dependency-manifest.json changed; the other 422 files are byte-identical.
- Four distribution tests verify channel separation, update metadata, translation
  parity, exclusion of shell tools and exact dependency-manifest hashes.
- The corrected installed ZIP passed 74 public-structure, 95 destination,
  63 taxonomy, 53 appearance and 20 publication-guidance checks (305 total).
- The installed-artifact Plugin Check report is retained locally with the release
  test evidence. Its category counts sum to 327 warnings and zero errors.
- The published GitHub ZIP and release assets were not modified for this submission.
- This document is outside the installable ZIP; the reviewer section above is
  intended for the submission form's Additional Information field.
