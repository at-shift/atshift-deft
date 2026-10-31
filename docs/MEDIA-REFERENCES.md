# Media References (0.1.0-alpha.3)

Alpha.3 adds article placement to the private intake implemented in
alpha.2. It does not add MCP tools, external AI byte transfer, publication approval
or publishing. Alpha.5 permits the owning administrator or dedicated author with
`upload_files` to resolve media; see `MULTI-USER.md` for the current access boundary.

## Canonical Snapshot

The WordPress snapshot has top-level `schemaVersion: 2`. Image/link field values
keep `value` and `alt`, with an optional `assetRef`:

```json
{
  "value": "",
  "alt": "Download the event application form (PDF)",
  "assetRef": {
    "kind": "staged",
    "id": "00000000-0000-4000-8000-000000000001",
    "sha256": "0000000000000000000000000000000000000000000000000000000000000000"
  }
}
```

The example IDs/hashes are placeholders, not usable assets. The source must be:

- `staged`: an actual private asset ID from this article, purpose `publication`,
  with its exact SHA-256. `reference` assets cannot be bound, even by imported JSON.
- `attachment`: an existing WordPress attachment ID represented as a decimal
  string, with a server-verified SHA-256. Example ID syntax: `"123"`.

`value` must be empty when `assetRef` is present. `alt` is required contextual
image alternative text or PDF link text. Reference fields are image-only for
JPEG/PNG/WebP, PDF-only for download links. Plain text fields cannot hold references.
Unknown properties, versions, source kinds, missing hashes and conflicting URLs
are rejected. A URL field cannot store a session-bound `/wp-admin/` resource URL.

The component/block/field identity remains separate from its file identity. Each
saved version stores canonical source references and derived HTML placeholders;
session URLs are generated at preview time, not persisted in the snapshot.
Changes to references or contextual text require a newer article revision and the
current `expectedVersion`. Existing immutable versions are never rewritten.

## Compatibility

WordPress continues to accept v1 snapshots (no `schemaVersion`) and does not
migrate old saved records. V1 rendering/digest identity is retained. V2 uses a
separate renderer digest prefix and hides an unfilled optional image.

The standalone browser and Node review server still use the strict v1 contract.
They reject WordPress v2 rather than silently dropping media references. Use
WordPress import/export for a v2 round trip; automatic synchronization is absent.
There is no database schema migration for this feature.

## Read-Only Library API

- `GET /structured-fields/v1/media?search=term&page=1`
- `GET /structured-fields/v1/media/{attachmentId}`

Both require workspace/upload capabilities and, for cookie authentication,
the REST nonce. Results include a verified `assetRef`, file type/name/size and
`existingWordPressMedia: true`. They do not disclose local paths or return an
upload/publication authority. Search is paginated at ten records per page;
unsupported, inaccessible or unavailable files are skipped.

Only canonical, readable local files inside the uploads directory are accepted,
owned by the current uploader and with actual file MIME matching attachment metadata.
The intake limits also apply: JPEG/PNG/WebP/PDF, at most 10 MiB (or a lower host
limit), and 40 megapixels for images. Symlink/alias paths, cloud-only/offloaded
files and direct URL fetching are not supported. Nothing is copied, registered,
renamed or re-encoded. Original alt/caption metadata is not modified; contextual
text belongs to each article use. Existing public URLs/access rules are unchanged.

## Authenticated Theme Preview

Each preview media URL is tied to the saved article version, digest, field and
current WordPress session. The endpoint also rechecks permissions, source purpose,
article ownership and the hash of the exact bytes it sends. Raster images display
inline; PDFs download as attachments. Responses are no-store/noindex/nosniff with
a restrictive sandbox CSP. Anonymous, mismatched or expired access is rejected.

Missing, changed or inaccessible references fail closed instead of substituting
new bytes. This can block an old preview if its source file has been removed;
the old snapshot is retained. It is not a frozen screenshot or a claim of human
approval: themes/CSS can still change how the article looks. No publication is
authorized by saving, previewing or choosing a publication candidate.

The complete HTML/CSS import remains an administrator-trusted design operation.
These checks protect structured media bindings, not against an administrator
manually copying confidential content or inserting their own same-origin CSS URLs.
A server-owned approved component catalog and scoped AI operations remain required
before exposing editing to less-trusted agents. File checking is not antivirus
scanning or PDF sanitization.

## Manual Fallback

1. Open Structured Fields, choose **Media sample**, then **Save draft**.
2. Upload images/PDFs as publication candidates, or search existing WordPress media.
3. Select sources in **Article media** and enter contextual alt/link text.
4. Save and inspect the theme preview; export/reload preserves the same references.

This panel is a test/manual fallback. The intended AI flow should perform the
same preparation through scoped operations without requiring these clicks.

## Verification

```sh
npm run test:wordpress
npm run test:wordpress-assets
npm run test:wordpress-media
npm run test:wordpress-http
```

The media suite creates disposable `media-test-*` records/files/attachments in
the isolated local WordPress site and removes only its own fixtures. It covers
schema rejection, reference/cross-article separation, pinned bytes, attachment
reuse, unchanged originals, snapshot immutability, access controls and actual
HTTP image/PDF delivery. Synthetic PDF bytes test transfer, not full PDF semantics.

Playwright/Chrome also exercised save/reload, image decoding, PDF download with
byte-hash comparison, library reuse, and controls at 1440x1100 and 390x844. No
horizontal overflow or page runtime error was observed. This evidence applies
to the local WordPress 7.1/PHP 8.3.36/Twenty Twenty-Five environment only.

On 2026-10-30, all 126 automated checks passed: 33 article PHP, 16 intake PHP,
26 media PHP, 36 existing Node/HTTP, 8 intake HTTP and 7 media schema/HTTP checks.
The built ZIP contains twelve allowlisted plugin files. Its extracted plugin
also passed all 75 PHP checks, with the loaded source path verified against the
extraction directory. Extracted files match the source; disposable test rows and
attachment metadata were removed. The Vite build passed with existing lucide
module-directive warnings. Browser diagnostics still include a missing resource
and external Gravatar requests blocked by preview CSP; no page runtime error.
This is local verification only, not a deployed release.

Primary WordPress references: [unfiltered attachment paths](https://developer.wordpress.org/reference/functions/get_attached_file/),
[read-only upload directory information](https://developer.wordpress.org/reference/functions/wp_get_upload_dir/),
[object capability checks](https://developer.wordpress.org/reference/functions/current_user_can/).
