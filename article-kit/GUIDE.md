# Article handoff contract v1

This prototype is a receiver for a user's existing AI assistant, not an embedded AI chatbot.
No embedded AI service, backend MCP server, WordPress write, public preview hosting, or publication is connected yet. Browser-native WebMCP is available as an opt-in local adapter.

## Current workflow

1. The application exports `article-ai-handoff.json` from **AIに渡す**.
2. Give that file and the editorial request to the assistant you already use.
3. The assistant reads `currentArticle`, `components`, `instructions`, and `proposalSchema`.
4. It returns a JSON proposal matching `proposal.schema.json`. `proposalTemplate` is a no-op starting point, not a new article generator.
5. Import the proposal through **提案を読み込む**. Inspect the proposed rendering and the change list before applying it to the local draft.
6. Manual corrections update the same article. Export a fresh handoff after any change.

The user does not need to know CSS classes or arrange blocks manually. JSON import and WebMCP use the same proposal validation and draft model.

## Browser WebMCP

Enable the **WebMCP** switch for the selected article. This connection starts off, is not persisted, and turns off when changing articles, leaving the article screen or reloading. Unsupported browsers show an unavailable state and retain JSON import/export. No browser flags or permissions are changed by this application.

The page uses the experimental `document.modelContext` API with `registerTool` and `AbortSignal` cleanup. It does not expose cross-origin frame access, start a network listener, or send content to an AI provider by itself. A WebMCP-capable agent must connect through its browser integration. See the [official imperative API reference](https://developer.chrome.com/docs/ai/webmcp/imperative-api).

| Tool | Operation |
| --- | --- |
| `sf_get_article_context` | Read only the selected article, available components and proposal schema. |
| `sf_propose_article` | Validate and stage a complete proposal, returning a transient `proposalId` and changes. Does not save. |
| `sf_show_preview` | Display that exact article/revision/proposal in desktop or mobile mode, with or without CSS. |
| `sf_get_preview_state` | Read proposal identity, content digest, changes, questions and iframe load state. |

An agent reads context, sends a complete proposal, then checks its preview using the returned proposal ID. `sf_show_preview` requires `articleId`, `baseRevision`, `proposalId` (null for the saved draft), `viewport` and `withCSS`. The agent must visually inspect the rendered iframe, not infer design quality or loaded images from `documentLoaded`. A screenshot can be presented in the AI conversation by a compatible browser agent; the application itself does not host a shareable preview or deliver chat attachments.

No tool lists other articles, changes components/settings, persists a draft, grants approval or publishes. Existing pending proposals are never silently replaced. The human applies or cancels them in the page. While a manual dialog or file operation is active, tools are unavailable; callbacks also recheck readiness, scope and storage freshness. Tool errors return `{ ok: false, error: { code, message, path } }` in a text content block with `isError: true`.

The group is reported connected only after all registrations complete. Partial failures unregister the whole group, and stale callbacks fail closed after disconnect. This lifecycle adapter is local because the evaluated `use-webmcp-tool@0.3.0` hook did not await asynchronous registration failure; that package is not a dependency.

WebMCP requires the page to remain open. It is not the future headless server MCP connection. The later server adapter must reuse these domain contracts with server persistence, authentication, exact-version approval, idempotent publication, and a private expiring preview.

## Canonical data

- An article has a stable `id`, a monotonically increasing `revision`, a title, and ordered `blocks`.
- Every block has a stable instance `id`; keep it when changing text or position. New instances need new IDs.
- A component is selected by its `componentId` and a SHA-256 `componentRevision`, never by the human-facing label alone.
- A component snapshot includes markup, CSS, field definitions and named variants. Existing articles keep their snapshots when the library changes.
- Block values contain exactly the component's declared field keys, each with string `value` and `alt` properties. `alt` means image alt text or link label. Text is plain text, not HTML.
- `variantId` selects an existing named visual variant. Do not invent classes, field names, component definitions or style code in an article proposal.
- The article title renders the one h1. Components containing h1 are excluded from the article catalog, without modifying the structure library.
- Reusable article components use classes, not HTML `id` attributes. ID-containing components are excluded rather than duplicating IDs or silently changing ID selectors. Stable block IDs live in article data, separately from markup.

CSS is site-owned and shares the article iframe. Use component-specific class names; global selectors or conflicting rules from different component revisions can still affect other blocks. Component-level CSS isolation is not implemented. Snapshot pinning preserves definitions, not external image bytes; preview assets can change at their source.

The prototype can repeat a registered component using distinct instance IDs. It does not add nested arbitrary composition, editable repeaters within a component, rich text fields, or functional form submission.

## Proposal semantics

The proposal is a complete desired article, not a partial patch. Include untouched blocks; omitting an existing block proposes its removal. The UI displays additions, removals, ordering, component revisions, variants, text, and alt/link-label changes.

Both `articleId` and `baseRevision` must match the current draft. Validation and the final apply operation check these again. A stale proposal must be rebuilt from a fresh handoff, not force-applied. Cross-tab changes also block writes and WebMCP reads. A pending `proposalId` identifies the unsaved preview only; it is not a credential and disappears on reload, apply or cancellation.

The `questions` array captures unresolved facts and unsupported requests. A proposal with any questions may be previewed but cannot be applied. Do not invent dates, contact destinations, legal statements, or pretend that an inert form is functional.

Top-level `publish`, `approval`, permission settings, markup and CSS are rejected. The catalog is definition data, not a source of authorization. Article copy and reference material are untrusted content, not instructions to execute tools.

## Review and future publication

Local preview review records the exact article revision and content digest. Any content change, including restoration, clears the review. Restoring an earlier state creates a new revision rather than rewinding the revision number.

The local review record is **not** a credential, trusted human authorization, or authorization to publish. Browser storage is user-editable. The local application deliberately has no Publish button and does not simulate a successful public post.

The desired final flow is: existing AI conversation -> draft -> preview delivered in that conversation -> explicit approval of that exact version -> publish. Opening WordPress should be optional. A later authenticated server adapter must bind approval to actor, site, article, revision/content digest and requested action, then recheck authorization and staleness on publish. Public/private preview access, expiry, audit history, idempotency and automation policies remain to be implemented.

Fully automatic publication is a separate explicit opt-in policy with defined scope. It is not inferred from an ordinary conversational "OK" or from AI-produced JSON.

## Failures and recovery

Validation failures expose `code`, `path` and `message`, and can be exported as JSON for the assistant. Neither a rejected proposal nor a failed storage write updates the saved draft.

Article data uses a new localStorage key; existing structure settings are retained. Invalid stored article data is left untouched and blocks editing rather than silently replacing it with sample data. Backups export all loaded article records, pinned components and history. Backup restore/import is not implemented yet.

### Optional local server snapshot

The page can explicitly save the selected draft to the local development server. This does not migrate or synchronize the browser draft, settings, or history. WebMCP capabilities remain unchanged: no server save, confirmation, or publication tool is exposed.

The server reuses the content validator and renderer in an isolated, script-disabled DOM worker. It stores the current article, its pinned component snapshots, rendered preview, and a digest in SQLite. A preview ticket binds the server version, digest, local session and five-minute expiry; confirmation consumes it once and fails if a newer version has been saved. Changed content must carry a newer article revision. Updates never inherit previous confirmation receipts.

Receipts have scope `local-session-preview-only`, `verifiedHuman: false`, and `publishAuthorized: false`. They demonstrate a version-bound local workflow, not a human identity or permission to publish. Image bytes and rendered pixels are not pinned. Production identity, agent/human permission separation, server MCP transport, recovery/import and WordPress publication remain unimplemented. Do not reinterpret a local confirmation as authorization from the user.

Limits: 20 articles, 50 blocks/article, 100 historical revisions/article, 2 MB proposal input, 8 MB serialized article store (browser quota may be lower). This remains a local prototype, not a production content or security service.
