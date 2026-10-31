# Alpha.13: Conversation to Page

Private GitHub code checkpoint, not a public release or production deployment.
Existing Fields and KOTOTSUGI are unchanged. No product rename is included.

## One-Time Setup

Activate the official MCP Adapter on an HTTPS test site. The account creates a
named connection, optionally granting authoring and separately publication.
Register its endpoint and Bearer key in the AI client's secure connection settings.
The connection page reports the last authenticated request; this proves credential
use, not a completed draft/upload. Download the credential-free AI guide and ask
the AI to read it. Keeping a file in a project does not itself cause execution.

OAuth-only clients still require a future standard authorization integration.
No provider account is connected automatically. Never paste a key into a chat,
Markdown file or Git repository. No additional hosting daemon is required.

## Client Routes

AI connections now separates ChatGPT, Claude remote, Claude Code, Gemini Apps,
Gemini CLI, local Codex, other HTTP clients and JSON-only workflows. See the
packaged [CONNECTIONS.md](../wordpress/atshift-deft/docs/CONNECTIONS.md), also
included in downloaded AI guides. CLI templates contain environment references
with endpoint-specific names, never issued keys, and retain client tool prompts.
OAuth/JSON choices cannot create unused keys. Claude/generic header routes require
confirmation of private per-owner credential storage; known private/loopback
addresses cannot be used by the Claude cloud route. This UI is guidance, not a
new authorization boundary: the existing server permission checks remain decisive.

Actual provider-account configuration, OAuth and app-level attachment/preview
integration are not completed. Claude header beta availability and Gemini Apps
regional/account restrictions are explicit; no provider is marked verified from
an SDK request. Existing connections remain manageable without selecting a client.

## Authoring Contract

Connections schema 3 adds authoring=0 by default. Old rows and keys stay unchanged.
Only a newly issued, explicitly authorized key enables these tools:

- create-structure: content-free bounded recipes, expectedVersion=0 only; reuse
  receipts and quotas remain authoritative. No shared updates or arbitrary code.
- list-media: this owner's article files and library images/PDFs, without session
  nonces or file paths. Reference-only files have no publishable assetRef.
- prepare-file-transfer: exact file manifest, saved owner article/version, short-lived
  credential and an existing multipart upload endpoint. Transfer schema 2 adds an
  optional issuer ID; browser-issued legacy transfers retain existing semantics.
- get-preview: exact latest saved version, credential-free self-contained HTML,
  verified images embedded within 2 MiB, disabled outgoing links, restrictive CSP,
  explicit omissions and content/theme distinction. Output is capped at 4 MiB.

Tools are discoverable but reject calls without the required grant. context reports
the actual per-connection capabilities. Publishing still needs its separate grant.
The client must display preview HTML in an isolated artifact viewer, not inject it
into its trusted application UI. The plugin never promises that a client rendered it.

## Natural Flow

Read context, understand the audience/message, inspect optional references, reuse
components, create a missing bounded structure if needed, save a draft, reuse or
transfer the necessary publication files, save exact references, retrieve preview,
discuss changes, then act on explicit publication intent and the requested time.

Office/text/PDF source materials can remain in the AI conversation. They are not
automatically uploaded or published. Uploads accept only JPEG/PNG/WebP/PDF. The
client must be able to read actual attachment bytes and perform multipart HTTP.
No arbitrary remote-URL fetch, local-server-path read or document parsing is added.
For a media-first article, save an empty draft first, then upload and compose.

## Security and Compatibility

- Existing connection tokens, saved articles and component versions are untouched.
- Other owners remain inaccessible, including through administrator plugin APIs.
- Revoke/expiry/owner access removal disables MCP-issued transfer grants; each upload
  rechecks the issuer, file manifest and unchanged article. No publication is implied.
- At most 20 active grants per account; existing site/asset/author quotas remain.
- Portable exports contain only explicitly bound publication images. Reference-only
  material and PDF bytes are not exported. No public anonymous preview endpoint.
- Theme fidelity and JavaScript behavior still require the logged-in theme preview.
- Human conversation intent is an AI instruction, not cryptographic human-presence
  proof. The existing delegated-publication policy is unchanged.

## Verification

Run all existing test scripts, especially test:wordpress-mcp, publications, workflow,
onboarding and quotas. MCP regression covers legacy grants, duplicate reuse, denied
shared updates, owner privacy, file bytes/retries, preview version/digest and issuer
revocation/expiry. Validate desktop/mobile connection and portable-preview rendering.
The user expanded the initial ChatGPT target to Claude, Gemini and other clients.
See the client routes above and [CHATGPT-INTEGRATION.md](CHATGPT-INTEGRATION.md)
for the remaining OAuth, file and UI gaps. SDK tests do not establish native-client
or cloud-chat compatibility.

Local verification on 2026-10-31: all 20 regression suites (458 checks) passed;
41 PHP files passed syntax checks and the frontend build succeeded (the two
existing lucide-react use-client bundler warnings remain). Desktop
1440x1100 and mobile 390x844 Chrome checks covered actual MCP authentication,
client selection and blocking, secret-free configuration downloads, secret
clearing, revocation, responsive columns, decoded inline images and no
portable-preview network requests. The Browser plugin was unavailable, so bundled
Playwright controlled installed Chrome. Packaged ZIP: 40 files, installed and
byte-compared in isolated WordPress. Plugin Check 2.1.0 completed all 34 standard
checks with 0 errors and 258 warnings. The four added warnings are two uncached,
prepared dedicated-table reads for live issuer revocation and per-owner transfer
quota enforcement; caching either decision would weaken the boundary. Existing
254 warning dispositions remain applicable; no new suppressions were added.
The provider-routing addition introduces no further Plugin Check warnings.
Three generated client configuration shapes were resolved against test environment
variables and exercised through the official MCP SDK; owner isolation and existing
grant boundaries held. These were configuration-contract tests, not authenticated
Claude/Gemini/Codex app sessions. The final UI pass had no page errors.

Verified local ZIP SHA-256 (not uploaded as a Release asset):
`4c975ff07d167fb3bd4924e3dab288acc3d6fea0dfafbe2eeaf24c9a17152174`.
