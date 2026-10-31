# Transfer and Account Review (introduced in 0.1.0-alpha.4)

Alpha.4 builds on private intake and pinned article media and is included in the
private alpha.5 code checkpoint. Alpha.5 adds owner checks described in `MULTI-USER.md`. No production
site, password, existing Fields data, public post or WordPress.org release changes.

## Intended Conversation Flow

1. The usual AI prepares an article and identifies the files it can actually access.
2. A trusted integration issues narrowly scoped transfer grants within previously
   authorized preparation rights. The AI transfers only the named bytes.
3. The article binds the resulting stable asset IDs and hashes, then saves a new version.
4. The person reviews that exact article/file set and makes one final decision.

Implemented here: the transfer protocol/client and a separate WordPress review
screen. A real chat connector, automatically obtaining chat attachment bytes,
scoped agent article-editing credentials, server MCP and a trusted chat-side
approval channel are not implemented. Do not pretend that an attachment the AI
cannot access has been transferred. The manual screen is a fallback, not the
intended requirement to approve each file separately.

## Exact-File Transfer

The owning administrator or dedicated author with `upload_files` issues a grant using JSON:

`POST /structured-fields/v1/articles/{articleId}/transfers`

```json
{
  "expectedVersion": 2,
  "filename": "application.pdf",
  "mime": "application/pdf",
  "bytes": 1234,
  "sha256": "0000000000000000000000000000000000000000000000000000000000000000",
  "purpose": "publication"
}
```

Replace the example size/hash with actual bytes. Purpose is explicit: `reference`
or `publication` (a candidate, not permission to publish). Unknown fields/types,
unsafe names, unsupported MIME/extension, stale versions and oversized files fail.
Private storage must already be configured. HTTPS is required; the isolated
HTTP test exception requires both site host and request address to be 127.0.0.1.

The response contains a 15-minute `token`, `transferId`, `uploadUrl`, file manifest,
article/version and expiry. Store credentials outside public roots, logs and Git
with restrictive permissions, and expose them only to the intended upload client.
Only a SHA-256 of the 256-bit random token is retained on the server. At most 100
unexpired grants are retained per site; expired grant rows are purged on issuance.
File metadata and retained bytes are not purged by expiry or revocation.

The client sends multipart `file` and `purpose` to `uploadUrl`, using the
`X-SF-Transfer-Token` header, without WordPress cookies/passwords. Never put the
token in a URL. The server rechecks issuer permissions, expiry, revocation,
article version/digest, purpose, sanitized filename, MIME, byte length and SHA-256.
It uses the same private-storage, type, size, quota and deduplication checks as
ordinary private intake. Changed bytes or purpose cannot use the same grant.

The receipt includes the stable asset ID/hash, not a file access URL. Retrying
the same file while the grant and article version remain valid returns the same
asset rather than making duplicates. A lost issuance response requires a new
grant; a new article version requires a new grant. No privileges are inherited
from the issuer's WordPress session. Transfer credentials cannot list assets,
read bytes, edit articles, issue grants, approve or publish.

The owning WordPress account can revoke via:
`POST /structured-fields/v1/transfers/{transferId}/revoke`.
Revocation and upload share the article lock; a completed upload is retained.

## Node Client

```sh
node scripts/transfer-wordpress.mjs /private/path/grant.json /private/path/application.pdf https://your-wordpress.example
```

Use Node 24+. Set the expected site origin from trusted site configuration, not
from an untrusted attachment or grant. The client verifies destination, file size
and hash before sending, rejects redirects, sends no admin credentials, validates
the receipt and prints no token. It does not request remote URLs or automatically
copy an AI chat's attachments. Keep the grant file private and remove it when no
longer needed. No new runtime is required on the WordPress server.

## Separate Review Boundary

An authorized account requests review with:
`POST /structured-fields/v1/articles/{articleId}/reviews`
and `{ "expectedVersion": N }`. Read status through
`GET /structured-fields/v1/reviews/{reviewId}`. Cookie REST calls require the WP
REST nonce. The response supplies a login-protected `reviewUrl`, not approval power.
The WordPress **Review** button invokes the same creation endpoint.

The manifest binds article ID/version/revision, canonical content digest and each
managed file use's ID/hash/type/size/name. Reference-only files are not part of
output. V2 is required; unpinned article images and CSS image resources are rejected
before review. The trusted administrator still owns component HTML/CSS. The manifest
does not freeze theme files, browser pixels or external website contents behind links.

The person opens the review URL, inspects the actual-theme preview and asset list,
then chooses **Approve this version** or **Request changes**. Approval can be
withdrawn. Decisions are POST-only and require a valid WordPress login cookie,
article ownership, workspace/upload/review capabilities and the exact review/session nonce.
Application-password-only and transfer-token clients cannot approve. A GET, JSON
`approved` flag or upload success cannot record a decision. Pending requests expire
after 30 minutes. Decisions/retries are serialized with article saves; unchanged
retries keep the original record and do not demand approval again. Review history
is capped at 100 records per article and retained on deactivation.

Changing the article version or any bound file invalidates the previous decision.
Deleted/inaccessible media and lost approver capabilities also invalidate it.
Withdrawing approval blocks replay of the earlier approve form; a new request is
required. Withdrawal records its own actor/time without overwriting the original
decision actor/time. Existing snapshots and original attachment metadata are unchanged.

**This records account intent, not proof of a human's presence.** Automation with
the same privileged cookie session can operate the screen. `humanPresenceVerified`
remains false. `publishAuthorized` remains false, even after approval: there is no
public-media promotion or publishing operation, and no downstream consumer should
treat this alpha record as publication authority. The future gate must revalidate
the exact record and assets at publication time and establish its human-trust model.

## Verification

`npm run test:wordpress-workflow` exercises real HTTP transfers, the CLI client,
scope/type/hash/expiry/revocation checks, idempotency, version changes, approval
authentication, rejection/withdrawal/replay and changed-media invalidation. Fixtures
are isolated `media-test-*` records with synthetic files and are removed afterward.
The existing article, intake, media and Node/HTTP suites are also regression-tested.
Playwright tests the visible review flow at desktop and mobile widths; Browser
plugin was not available. Minimum WordPress/PHP versions, other themes, hosted TLS,
real chat clients and any publishing operation remain unverified.

Local verification on 2026-10-30: 138 checks passed (the 126 alpha.3 regression
checks plus 12 transfer/review cases), PHP/JavaScript syntax checks and the Vite
build passed. The build retains existing lucide module-directive warnings. The
14-file WordPress ZIP was extracted, matched against source and loaded for the
26 media PHP checks, including verification of the packaged Review class path.
Chrome exercised save/reload, PDF download, approval, withdrawal and request changes
at 1440x1100 and 390x844. No page runtime errors or horizontal overflow remained.
Existing Gravatar CSP blocks and a missing-resource diagnostic remain; no new PHP
warnings followed the review-page title/header fixes. This is not production proof.

WordPress references: [cookie and REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/),
[cookie validation](https://developer.wordpress.org/reference/functions/wp_validate_auth_cookie/).
