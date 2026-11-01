# WordPress.org Submission Update: atshift DEFT 0.7.2

Prepared: 2026-11-01 (JST).
Existing application: `atshift-deft`, still awaiting its first review when checked.
Submission candidate: `atshift-deft-0.7.2-wordpress-org.zip` (701916 bytes, 425 files).
SHA-256: `348a26ce62c483b9cac8cf4e7a66197c558e1d8c9922bba6431aba93d2e2d301`.
Artifacts: `.local/v072-publish/`. Earlier ZIPs and submission records are unchanged.

## Submission Result

Uploaded on 2026-11-01 after explicit approval. WordPress.org confirmed:
"New version of atshift DEFT uploaded for review." The newest submitted file is
`atshift-deft-0.7.2-wordpress-org.zip`, with detected version `0.7.2`.
The accompanying explanation below was submitted with the ZIP.

The existing application remains **Awaiting Review**. This is acceptance of an
updated review package, not directory approval or public availability. Earlier
submitted files remain listed. No existing website was updated.
Local evidence: `.local/v072-publish/wordpress-org-updated.png`.

## Additional Information for Reviewers

This updates the existing atshift DEFT application (slug: atshift-deft) from 0.7.1
to 0.7.2. It is not a new application or a slug change.

Our previous AI guide mentioned MCP Adapter but did not include the installation
and activation steps. AI connections require the official MCP Adapter by
WordPress.org, version 0.7.1 or newer, installed and active:
https://wordpress.org/plugins/mcp-adapter/

This update inserts explicit installation/activation instructions before AI
connection setup, links the official plugin in requirements and user guides,
and adds Japanese guidance. Users without installation permissions are directed
to their site administrator. DEFT does not download, install or activate MCP
Adapter automatically. The separate manual JSON workflow does not require it.
Stored article formats, authorization and publication behavior are unchanged.

Source, build instructions and tests: https://github.com/at-shift/atshift-deft
Release notes: https://github.com/at-shift/atshift-deft/blob/main/docs/RELEASE-0.7.2.md
File: atshift-deft-0.7.2-wordpress-org.zip (701916 bytes).
SHA-256: 348a26ce62c483b9cac8cf4e7a66197c558e1d8c9922bba6431aba93d2e2d301

The actual submission ZIP was installed in a disposable WordPress 7.1 / PHP
8.3.36 site. It passed 27 prerequisite checks with MCP Adapter active and 27
with it inactive, including guide generation, administrator/author permissions
and Japanese MO loading. The GitHub package passed the same checks. Twenty-seven
Node packaging/translation/guidance tests passed. All 331 packaged PHP files in
the submission ZIP passed syntax lint. The GitHub updater separately passed 69
checks including native upgrade from the published 0.7.1 ZIP to 0.7.2, using
controlled release HTTP fixtures. No existing user website was modified.

Local Plugin Check 2.1.0 reports 0 errors and 347 warnings, the same category
counts as the previous submission. No new category was introduced. The detailed
caller analysis in WORDPRESS-ORG-REVIEW-0.7.1.md remains applicable; in summary:

1. 159 DirectQuery and 137 NoCaching: prepared/structured custom-table operations,
   locks, immutable versions and authoritative permission/version/quota checks.
   Not every query is inherently uncacheable; tested behavior was retained
   instead of adding incomplete caching solely to silence lint.
2. 23 InputNotSanitized and 4 MissingUnslash: callers check types, unslash payloads
   and validate exact IDs/versions/nonces. REQUEST_METHOD is compared only to
   allowed values; DOCUMENT_ROOT is resolved and checked for storage separation.
3. 6 NonceVerification.Missing and 8 Recommended: mutation paths delegate to
   explicit nonce/capability/owner checks. Intentional read-only public media
   checks publication, password/visibility, ownership, membership and integrity;
   visitor nonces are not a substitute for these checks.
4. 1 SchemaChange: removal of the obsolete machine_key index from DEFT's own table
   preserves the owner-scoped replacement that dbDelta creates.
5. 4 hook-prefix and 2 namespace-prefix findings: compatibility sf_ identifiers
   and existing product-specific atshift_deft/AtshiftDeft identifiers are retained.
6. 1 load_plugin_textdomainFound: deferred init loading supports bundled partial
   Japanese catalogs, runtime guards and independent distribution.
7. 2 unexpected_markdown_file: AI-GUIDE.md is runtime input for the downloaded AI
   instructions; GETTING-STARTED.ja.md is the linked Japanese user guide.

The WordPress.org ZIP has no Update URI, GitHub updater class/bootstrap or GitHub
update HTTP requests. The separate GitHub package intentionally includes its
updater and reports plugin_updater_detected; that ZIP is not submitted here.
Both packages share the same core and translation files apart from the updater.
The non-runtime dependency build-phar.sh remains excluded. No exemption to the
directory's updater rule is requested. These checks do not replace manual review
or prove production readiness; specific reviewer feedback will be addressed.
