# atshift DEFT 0.5.2

License: GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.

Start at **Articles > Download AI guide**. Give the downloaded Markdown file to
the AI you normally use and describe the new article. It includes current shared
component contracts and schemas, not private drafts or credentials. The first-visit
introduction is shown once per account/site; the download remains available.
Use **Article handoff** for file-based edits, or connect an MCP client to resume
your own drafts directly. Initial connection setup is required; publishing requires
a separately granted publishing key and the publisher role (or administrator).

An experimental WordPress plugin for structured article snapshots. This build
is intended only for an isolated test site, not a production website.

## Install

Download `atshift-deft-0.5.2.zip` from the [GitHub Release](https://github.com/at-shift/atshift-deft/releases/tag/v0.5.2), which also includes its SHA-256 checksum. Upload it through Plugins >
Add New > Upload Plugin, activate it, and open **atshift DEFT** as an administrator.
Developers can build scoped PHP dependencies with `npm run build:php-deps`, then
build the ZIP with `npm run pack:wordpress` from the repository. GitHub's source
archive is not the installable plugin package.
For manual installation, place the `atshift-deft` folder in `wp-content/plugins`.

DEFT means **Delegate Execution. Focus on Thinking.** (`D.E.F.T.` is the expanded-initialism styling.)
The 0.5.2 changes clarify the draft-to-publication workflow without changing data formats or permissions.
After upgrading, download a fresh AI guide from Articles and ask your AI to read it.
When upgrading from Structured Fields Prototype, back up the site, deactivate the
old plugin, then install and activate atshift DEFT. Do not delete the old data.
The changed folder/main file is a different WordPress plugin identity, not an
in-place update. The old and new versions cannot run together. Drafts, media,
component IDs, roles/capabilities, connection grants and reservations are retained.
The `structured-fields` API/MCP/admin route names and OAuth issuer are intentional
compatibility identifiers; do not replace them with `atshift-deft` in client settings.
No Node.js process, separate AI service, or SQLite database is required on the server.
Japanese step-by-step installation and use: [GETTING-STARTED.ja.md](GETTING-STARTED.ja.md).
External-AI instructions: [AI-GUIDE.md](AI-GUIDE.md).

## AI Connection

Start by downloading the AI guide from Articles and giving it to your usual AI.
The AI guides the relevant connection route and verifies a read-only live context
response. It then offers the included optional sample or continues your article.
The sample needs no separate file: it creates a clearly labeled private draft and
preview, without publication, media upload or new component registration. Skip it
freely; existing-draft continuations do not repeat setup or the sample offer.
Keep the original article brief in the same conversation. Connection/sample consent
is not publication consent. The plugin does not automatically configure AI clients.

Activate the official WordPress **MCP Adapter** plugin, version 0.7.1 or newer,
separately. Open **AI connections**, create a named key, and register the displayed
endpoint in a client supporting Streamable HTTP with `Authorization: Bearer <key>`.
Keep the key in secure connection settings, never chat text. It is shown once,
expires after 30 days, and can be revoked without deleting drafts. HTTPS is required
except local loopback development. Cloud AI services cannot reach localhost.

Four tools expose current component context and your own draft list/read/save.
Saves use exact versions and refuse stale writes. Newly issued keys can separately
allow four authoring tools: create-structure, list-media, prepare-file-transfer and
get-preview. Existing keys keep their previous authority. The portable HTML preview
embeds verified images within a 2 MiB media budget, without theme/JS or public links.
The owner-login workspace remains the full theme-preview fallback. File transfers
need actual attachment bytes and HTTP capability in the client, and are invalidated
when their issuing connection expires or is revoked. No arbitrary code, shared
definition updates, automatic client setup or review decisions are exposed.
Manual import/edit/save remains available without MCP Adapter.

Alpha.14 also supports pre-registered public OAuth clients with PKCE S256.
Administrators register an exact callback URL; each staff member signs into WordPress
and grants their own access. No AI provider API key or shared owner key is required.
Access tokens last ten minutes; refresh tokens rotate within a 30-day consent grant.
Refresh reuse revokes the grant. See [CONNECTIONS.md](docs/CONNECTIONS.md).
Actual ChatGPT, Claude and Gemini account flows still require a public HTTPS test.
Automatic client registration and remote client metadata fetching are not supported.

## Publish a Saved Draft

**Save draft** and **Review** do not publish. Open **Articles > How to publish or
schedule** for the in-plugin steps.

1. Save and check **Open theme preview**.
2. Open **AI connections** using the draft owner's WordPress account (administrator
   or **atshift DEFT publisher**). For a key-based client, select **Allow immediate
   and scheduled public publication of my drafts**, then **Create connection**.
   Add its returned publishing URL and new key as a separate AI connection; keep
   the draft connection. For OAuth, choose **Use the publishing endpoint** and
   authorize publication with the same owner account and an application that permits it.
3. Return to your AI conversation, verify publication access, and say "Publish
   this draft now and give me the URL" or specify a date, time and time zone.
4. Check **Articles > Publication** and **View published post**. A scheduled article
   is not public yet; **Cancel reservation** cancels a pending schedule.

Only grant this access to a trusted client: there is no second WordPress approval.
Never paste a key into chat. Missing publication options require administrator
help with the same account, not another person's connection. Setup alone does
not request publication; the optional sample stays private.

An opt-in **atshift DEFT publisher** can create a separate publishing key.
Its displayed endpoint additionally exposes get-publication, set-publication and
cancel-publication. The user chooses now or a desired date/time; the AI sets it
without a second WordPress approval. Existing keys remain draft-only. Reservations
bind exact content/media/policy and stop if any change. Revocation cancels pending
reservations; expiry blocks new requests but preserves previously authorized jobs.
WordPress cron may run late; use a server cron for reliable due-event processing.
Only initial publication is supported. Later draft saves do not update the public post.
Images/PDFs use frozen private copies served after publication, not media-library
promotion. The plugin must remain active to serve these files and styles. A disabled
plugin cannot process reservations. Retention/deletion UI is not implemented.

For each contributor, use a separate WordPress account with the **atshift DEFT
author** role. No existing roles or accounts are changed automatically. Articles
belong to their first creator; plugin APIs/UI have no administrator override.
Authors may use active catalog or their article's saved component revisions, and
create shared content-free structures from bounded recipes, not arbitrary HTML/CSS.
Existing private definitions remain private; the legacy recipe path is still available.
The opt-in **atshift DEFT designer** role grants shared registration and
management without site administration or access to other owners' private work.
Administrators can still import arbitrary validated designs into their own articles.
Account handover is not implemented.

Requirements: WordPress 6.9+, PHP 8.2+ with DOM, mbstring, OpenSSL and Sodium, MySQL/MariaDB with
InnoDB and named locks. Verified locally on WordPress 7.1, PHP 8.3.36, MySQL 8.4,
and Twenty Twenty-Five only; minimum-version compatibility is not yet verified.
PHP below 8.2 stops the plugin before its implementation or dependencies load and
shows administrators a warning with the required/current versions. Stored data is
not modified. WordPress also receives the Requires PHP header for new installations.

## Try It

Administrators can import one component JSON in **Components**, then register it.
Use **New article** to choose active components and enter values; save to preview.
Saved articles keep their component revisions even after catalog updates or
deactivation. The **Edit article** section is a minimal manual fallback.
The schema download describes `POST /structured-fields/v1/catalog` (shared design capability)
and `POST /structured-fields/v1/articles/compose` (owner workspace). Compose sends
only `{article, expectedVersion}` with block IDs, component IDs/hashes and values.
It does not grant new AI credentials, connect a chat, approve or publish.

**AI handoff** downloads instructions, field contracts and a composition template.
Use existing parts first: own usage, then the whole shared catalog. **New structure**
is for genuine gaps and requires catalog review plus a missing-capability reason.
Recipes contain no field values, alt text, URLs or asset references. An equivalent
shared structure returns its existing ID/version instead of creating a copy. Changed
article saves automatically record exact-version usage; no extra approval is needed.
Recipes support stack, columns and native Q&A list/accordion. IDs, content and old snapshots remain
stable when Q&A presentation changes. Reply-language guidance is independent from
article language; identifiers are never translated. AI JSON composition results can
be staged with **Import snapshot** and saved through the same owner-only API.

Choose **Load sample**, then **Save draft**, and inspect the active-theme preview.
To import an article from the browser prototype, export its WordPress snapshot
JSON, then choose **Import snapshot**. Required media must already exist on the
WordPress site; the JSON transfer does not upload images.

The **Private files** panel accepts JPEG/PNG/WebP/PDF for a saved draft.
Both reference files and publication candidates stay private, outside normal
WordPress uploads. In alpha.3, **Article media** can place publication candidates
in image/PDF fields with contextual text. Reference-only files cannot be bound.
Your own uploaded WordPress media can be searched and reused without copying files
or changing their original metadata. New private files are not published.
Provision a canonical directory outside all public roots/aliases and set
`SF_PRIVATE_MEDIA_DIR` and `SF_PUBLIC_DOCUMENT_ROOT` in `wp-config.php` first.
PHP fileinfo and Unix permissions (0700 directories, 0600 files) are required.
Missing/unverifiable storage fails closed; there is no public-uploads fallback.
Limits are 10 MiB per file (or lower host limit), 50 files and 50 media bindings / 100 MiB per article,
100 MiB of staged files per uploader across articles, and 500 MiB per site.
New shared component IDs default to 20 per creator; existing shared reuse does not
consume a slot. Disabled IDs still count. Manager edits never transfer creator usage.
Trusted site code may adjust per-user limits using `sf_shared_component_limit_per_user`
and `sf_private_media_limit_per_user` (integer count/bytes, then the user ID).
Existing data is retained even when over quota. Assets are retained on deactivation;
cleanup is not implemented.

Choose **Media sample**, save it, then select sources in **Article media** and
save the changed draft. Media references use a stable ID and SHA-256, never a
temporary preview URL. Missing or modified files block preview instead of showing
different bytes. Existing library URLs keep their original access behavior.
Reuse supports local JPEG/PNG/WebP/PDF inside uploads under the same per-file
limits; offloaded files, external fetching and other formats are not supported.
Snapshots with references have `schemaVersion: 2`. WordPress still reads v1;
the separate browser/local-server v1 importer rejects v2 rather than losing refs.

Saved versions are immutable. Changed article content needs a newer article
revision, and conflicting writes are rejected. Previews require the authorized
WordPress session and exact saved-version URL. Deactivation retains saved data.
Uninstall cleanup is not implemented.

## Limits

Only administrators may import complete component HTML/CSS in this first phase.
Raw shared registration/updates can be delegated separately via the designer role.
Bounded structure creation is available to workspace authors; shared updates and
retirement remain manager-only. Shared labels and guidance must be generic configuration,
not confidential article information. Alpha.9 adds bundled behavior references, not arbitrary JS registration.

Prefer native HTML, then CSS, then JS. Q&A uses native disclosure. The bundled
fade-in is CSS-only and honors reduced motion; image-zoom uses JS with a native
dialog and keyboard focus restoration. Components bind exact behavior revisions
to semantic field keys (or root), with typed settings. Articles may override only
those settings. V3 snapshots carry behaviors without rewriting v1/v2 data.
Components > Behaviors lists the shipped registry; administrators can stop/restore
an exact revision. Stopped or missing assets fall back to static content on the next
preview load. V3 reviews become invalid after availability-policy changes.
No code upload, external scripts, form backend or publishing capability is added.
Personal recipes are owner-only, capped at 20 IDs per user, and normalized duplicates
are rejected. Explicit sharing creates a separate shared copy; nothing is auto-shared.
The catalog holds up to 100 component IDs, 30 revisions per component and 32 MiB
of definitions. Registration is limited to 512 KiB. IDs and machine keys stay
stable; machine keys are unique within their owner scope. Availability changes affect new selection only,
not old pinned articles, and are not emergency revocation. No hard delete is provided.
Separate accounts cannot read, edit or approve each other's articles or private
files. Concurrent saves of the same article must use its current version. A new
sample creates a unique article ID; resuming a saved draft keeps its existing ID.
Site/DB administrators remain trusted infrastructure operators. Existing public
WordPress upload URLs are not made private. Shared site quotas are not per-user isolation.
Alpha.4 adds exact-file transfer grants and a separate **Review** screen with
approval, changes-request and withdrawal controls. A changed article or file
invalidates approval. Approving records WordPress account intent, not proof of
human presence, and does not publish. Transfer keys cannot approve or browse files.
HTTPS is required. HTTP is accepted only with WordPress environment type `local`,
a loopback home URL and a loopback peer. Connections, transfers and reviews share
this policy. The bundled Node transfer client still only accepts HTTP at 127.0.0.1.
Only v2/v3 snapshots with pinned article media qualify for review. Unpinned image URLs
and CSS image resources are rejected. Theme files and linked website contents are
not frozen or covered as managed assets.
Beyond the scoped MCP tools, there is no chat-attachment integration, public preview link
or automatic synchronization. A saved draft is not publication permission.
External media/CSS/font requests are blocked in preview.

Button icon line-height and width are scoped to this screen to keep them centered.
The private-file and media-binding flows are verified at desktop/mobile widths; broader mobile layout
and other themes still need testing. Existing atshift Fields is not modified.

Project: https://github.com/at-shift/atshift-deft
