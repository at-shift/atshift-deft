# Private Asset Intake (introduced in 0.1.0-alpha.2)

Historical design and verification record. The external-directory setup below
is superseded by [Private Storage](PRIVATE-STORAGE.md). New files use the database;
only read-only access to already stored legacy files retains the old path policy.
For current publication behavior, see [Getting Started](GETTING-STARTED.md).

Alpha.3 adds article-field references and existing-media reuse on top of this
intake boundary. See [Media References](MEDIA-REFERENCES.md). Uploading alone
still does not change an article snapshot or authorize publication.
Alpha.4 implements scoped transfer and account-authenticated review as described
in [TRANSFER-REVIEW.md](TRANSFER-REVIEW.md); actual publishing remains absent.

## Product Contract

The normal workflow should ask the person only for the final public result.
Within the previously authorized scope, AI prepares the draft, chooses assets,
checks types, retries transfers, proposes contextual alt/link text, and prepares
the preview. Ask earlier only when public/private use, a consequential fact,
broader permissions, or an irreversible action cannot safely be resolved.
Consolidate those questions. A missing or ambiguous file purpose defaults to
reference-only, never public.

The eventual final approval must identify the exact article revision and public
asset set (stable IDs, byte hashes and uses), including downloadable documents.
Changed content or assets invalidate that approval. An unchanged retry must not
ask again. Session authentication, an AI's `approved` field and a successful
upload are not human publication consent. No approval or publication operation
is implemented by this change.

## Implemented Boundary

- Saved WordPress articles accept JPEG, PNG, WebP and PDF via authenticated multipart upload.
- `reference` is the default purpose. `publication` means a candidate, not approval.
- Both purposes remain outside the web document root, not in the media library.
- Current alpha.7 requires the owning workspace account plus `upload_files` for
  upload, metadata and bytes; the original alpha.2 administrator-only restriction
  was superseded by staff isolation in alpha.5.
- Cookie REST authentication requires the WordPress REST nonce. File viewing
  separately requires the logged-in session and an asset/hash-bound nonce.
- PDFs download as attachments. Raster images can be viewed privately. Neither
  the plugin nor the server executes uploaded documents or treats them as instructions.
- Content type, extension, image dimensions, size and SHA-256 are checked.
  This is not antivirus scanning, PDF sanitization or proof that a file is benign.
- Upload retry is idempotent for the same article, bytes and purpose. A new
  filename does not rename the first retained file. Different purpose yields a
  separate immutable asset; a reference is never silently promoted.
- No public URL, attachment ID, automatic asset placement or publication is returned.
- The optional WordPress UI supports selecting several files in one operation.
  It is a fallback/test surface, not a requirement that the future AI workflow
  ask people to classify every file manually.

## One-Time Host Configuration

Provision a dedicated writable directory outside **every** public document root
and web-server alias. Set these trusted server constants in `wp-config.php`:

```php
define( 'SF_PRIVATE_MEDIA_DIR', '/srv/private/structured-fields' );
define( 'SF_PUBLIC_DOCUMENT_ROOT', '/srv/www/example/public' );
```

Use canonical absolute paths without symlinks or trailing slashes. The plugin
checks WordPress, content, the configured public root and the runtime document
root, and creates a site-specific child directory with mode 0700. File mode is
0600. It does not guess a safe parent folder or fall back to `uploads`.
Unconfigured, unverifiable, public or shared storage fails closed.

The host administrator must verify aliases, additional virtual hosts, CDN and
backup exposure. PHP cannot discover every web-server mapping. Shared server
accounts still share their Unix identity; 0700 is not isolation from that account.
Windows storage ACLs are not supported by this prototype's Unix-mode checks.

The isolated local site uses `.local/private-media` beside, not within,
`.local/wordpress`. Configuration and file bytes are git-ignored. No existing
site settings, passwords or normal WordPress media are changed.

## API

`GET /structured-fields/v1/articles/{id}/assets` returns assets, the current saved
article version/digest, and an ordered asset-set `manifestDigest`. This manifest
is an inventory, not a final approval document. Returned `contentUrl` values are
session-bound access links, not canonical article field values.

`POST /structured-fields/v1/articles/{id}/assets` takes multipart `file` and
optional `purpose` (`reference` or `publication`). Unknown body fields, multiple
files in one request, and unknown purposes are rejected. Batch clients send one
file per request; they may retry the same transfer without making duplicates.
The article must already have a saved draft. This phase does not change its
immutable snapshot or render the new asset in its theme preview.

Responses include `assetId`, `articleId`, `purpose`, `sha256`, `filename`, `mime`,
`bytes`, `state: "private-staged"` and `publishAuthorized: false`.
Public access, a copied viewing URL without its user session, an invalid nonce,
or changed file bytes cannot retrieve the asset. Responses are no-store/noindex;
binary responses also use nosniff and a restrictive sandbox CSP.

Limits: 10 MiB per file (or the lower PHP/WordPress limit), 40 megapixels per image,
50 files / 100 MiB per article and 500 MiB per site. A database lock serializes
deduplication and quota accounting. Errors do not retain partially written files.
Deactivation retains assets; no automatic expiry, retention UI, deletion API,
uninstall cleanup or crash-orphan recovery is implemented yet. An interrupted
process can leave a private orphan; administrators must not manually clear the
directory without reconciling its metadata first.

## Next Steps (Not Implemented)

1. Connect real AI clients to alpha.4 transfers and add an upload handoff when the
   client cannot access chat attachments. MCP alone does not ensure byte access.
2. Trusted chat-side approval handoff, then standard WordPress media registration and
   publication, with partial-failure recovery and no repeated approval for a retry.
3. Explicit retention/removal policy and host-level file scanning where required.

No direct URL fetching or external downloads are performed by this phase.

## Verification

```sh
npm run test:wordpress
npm run test:wordpress-assets
npm run test:wordpress-http
```

The asset suite uses only disposable `asset-test-*` articles in the isolated local
database, actual multipart HTTP uploads and byte downloads, then deletes its own
records/files. Storage checks include public/symlink paths, type spoofing, quotas,
tampering, anonymous access, insufficient roles, nonce failures and idempotency.

Local verification on 2026-10-30: 33 article PHP checks, 16 asset PHP checks,
36 existing Node/HTTP tests and 8 asset HTTP tests passed (93 total). The Vite
build passed with the existing lucide module-directive warnings. Bundled
Playwright/Chrome exercised import/save, multi-file intake, lower host-limit
rejection and retry, thumbnail loading, purpose separation, deduplication and
reload at 1440x1100 and 390x844, without horizontal overflow. Browser plugin was
not available. Expected browser diagnostics were the initial missing-article
lookup/favicon and the existing preview CSP blocking Gravatar; no new application
runtime error was observed. This is local evidence, not hosting/deployment proof.

Primary WordPress references: [upload directories and URLs](https://developer.wordpress.org/reference/functions/wp_upload_dir/),
[file type checks](https://developer.wordpress.org/reference/functions/wp_check_filetype_and_ext/),
[REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/).
