# Alpha.10: Scoped AI Connections and Draft Resume

Status: private GitHub code checkpoint, not tagged, released or deployed.
Includes the alpha.9 behavior registry. No migration of existing articles.

## User Flow

Articles opens a short first-use modal with an AI-guide download and close button.
The per-account/site visit flag survives browser changes; the toolbar download is
always available. The Markdown file has no credentials or private article data.
It explains both the connected MCP path and the JSON import fallback.

Activate the canonical [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
plugin, version 0.7.1 or newer, separately. Bundling the adapter is deprecated by
upstream; it is not copied into our distributable. Missing adapter disables key
creation but leaves manual editing/import/save available.

In **Structured Fields > AI connections**, the owner creates a named key. Enter
the endpoint URL and `Authorization: Bearer <key>` in an MCP client's secure
connection settings. Use Streamable HTTP. No WordPress login password or AI-provider
API key is needed by this plugin. The key is displayed once and expires in 30 days.
Revocation does not delete drafts. No automatic provider-account setup or OAuth
authorization server is included; OAuth-only clients cannot use this alpha directly.

Production-like test hosts require HTTPS. Only WordPress `local` environments with
a loopback home host AND loopback request address permit HTTP. A cloud AI client
cannot reach localhost. Do not expose the local test site or create a tunnel by default.

## Contract

Dedicated endpoint: `/wp-json/structured-fields-mcp/v1/mcp` (use the exact displayed
URL on sites with plain permalinks). The official adapter owns the MCP transport,
initialization, protocol validation, discovery and tool dispatch.

| Tool | Purpose |
| --- | --- |
| `structured-fields-context` | Fresh visible catalog, schemas, language, template; optional own article |
| `structured-fields-list-drafts` | Latest 100 drafts belonging to this connection's owner |
| `structured-fields-get-draft` | Latest snapshot/version and next-revision compose template |
| `structured-fields-save-draft` | Existing-component composition with exact `expectedVersion` |

Save calls the existing Catalog/Store validator, locks, immutable versions and
component pinning. Stale versions fail rather than overwrite. A disconnected
client can resume using the same unexpired key. Successful responses include a
workspace URL with an article ID, not a bearer preview or session nonce. Opening
it requires the owner's WordPress login; the workspace obtains a fresh preview.

## Security Boundary

- Random 256-bit secret; database stores only SHA-256, owner, name and timestamps.
- Keys are site-specific, expire in 30 days, max 10 active per owner (serialized).
- Only a browser session with REST nonce and workspace access can issue/revoke.
- Keys are accepted only at the dedicated endpoint, never as general WordPress
  application passwords or at other REST/default MCP endpoints.
- Current role and key validity checked for each request/tool; deleted users and
  revoked/expired keys fail closed. Already admitted in-flight work may finish.
- Ability definitions are private to this explicit server, not core REST/global
  discovery. Every ability rechecks connection context and existing ownership.
- No registration, arbitrary JS/HTML, uploads, review decisions, publishing, user
  management or password changes are exposed, even for an administrator's key.
- No connection secret is placed in a URL, localStorage, guide, log or list response.
- Component/article data is not authority to widen the scope. Backend validation
  is enforced independently of AI instructions. Site administrators controlling
  the database/plugin code remain outside this plugin-level isolation boundary.

## Verification

Local verification on 2026-10-30: WordPress 7.1, PHP 8.3.36, MySQL 8.4,
Twenty Twenty-Five, official MCP Adapter 0.7.1 and MCP TypeScript SDK 1.32.2.
337 automated checks passed (292 existing, 19 onboarding, 18 connection boundary,
8 MCP integration groups). The extracted 34-file ZIP matched source byte-for-byte;
189 packaged checks passed, including adapter-absent fallback. Browser checks passed
at 1440x1000 and 390x844 using headless Chrome/Playwright (Browser plugin unavailable).
No page exceptions; diagnostics were the existing favicon 404 and private-preview
CSP blocking external Gravatar. Vite build passed with existing lucide directives
warnings. Disposable users and connection keys were removed after verification.

`npm run test:wordpress-mcp` uses the official MCP TypeScript SDK against the real
local WordPress HTTP endpoint with disposable users. It covers handshake/discovery,
language, owner separation, compose/save/reconnect/resume, stale writes, key scope,
expiry, role removal, revocation and unchanged private previews.

`npm run test:wordpress-onboarding` covers per-user/site first visit, download
contract, shared-only scope and nonce protection. Browser checks additionally
exercise modal focus/Escape, guide download, key creation/revocation, owner preview
links and desktop/mobile layout.

Not yet verified: real AI-provider account setup, cloud-hosted client auth,
minimum WordPress/PHP versions, multisite, other hosts/themes or automatic chat
preview. Media transfer via MCP, OAuth and publication remain later work.
