# Installation and User Guide

**atshift DEFT 0.5.1** / October 31, 2026

The 0.5.1 plugin ZIP includes the updated connection guidance. After upgrading,
download a fresh AI guide from DEFT; an earlier downloaded guide does not update itself.

DEFT means **Delegate Execution. Focus on Thinking.** Use your preferred AI to assemble structured articles, save drafts, review previews, and set an immediate or scheduled publication time.

This is a development release. Start on an isolated test site with a backup, not your production site. DEFT is not a replacement for atshift Fields / CFS and does not migrate existing posts.

## Quick Start

1. Install the [WordPress plugin ZIP](https://github.com/at-shift/atshift-deft/releases/download/v0.5.1/atshift-deft-0.5.1.zip) and activate **atshift DEFT**.
2. Open DEFT, choose **Download AI guide**, and give that file to your usual AI.
3. Say, **"Read this guide and walk me through connecting to my site."** Follow the client-specific setup and authorize only the required access.
4. After the AI verifies the connection, try the optional sample or start your own article.
5. Review the preview and request changes. With a separately authorized publishing connection, tell the AI to publish now or specify a date, time, and time zone.

The plugin does not configure the AI application automatically. Initial setup is required, and client capabilities vary. Never paste passwords or connection keys into chat.

## 1. Prepare the Site

| Requirement | Details |
| --- | --- |
| WordPress | 6.9 or later |
| PHP | 8.2 or later, with DOM, mbstring, OpenSSL, and Sodium |
| Database | MySQL / MariaDB with InnoDB and named-lock support |
| Remote AI access | An externally reachable HTTPS site |
| MCP connection | Official WordPress MCP Adapter 0.7.1+ and a compatible MCP client |
| Private file uploads | PHP fileinfo, Unix permissions, and a directory outside the public document root |

Node.js, a separate AI server, and an AI provider's API key are not required on your WordPress host. You still need the appropriate AI account and client capabilities. A browser extension is not required for the normal user workflow.

The tested environment is WordPress 7.1, PHP 8.3.36, MySQL 8.4, and Twenty Twenty-Five. The declared minimum versions, multisite, other themes, and other hosting environments have not all been verified.

On PHP below 8.2, DEFT stops before loading its implementation and shows an administrator notice. Existing data is not changed, but scheduled publication and other plugin functions cannot run. Check PHP before updating.
The HTTP exception is for an explicitly configured loopback development environment only. A cloud AI service cannot access your computer's `127.0.0.1` or `localhost`.

## 2. Install DEFT

1. Back up the test site's files and database.
2. Download `atshift-deft-0.5.1.zip` from the [GitHub Release](https://github.com/at-shift/atshift-deft/releases/tag/v0.5.1). The SHA-256 file is available there too.
3. Sign into WordPress as an administrator and open **Plugins > Add New Plugin > Upload Plugin**.
4. Upload the ZIP, install it, and activate **atshift DEFT**.
5. Open **atshift DEFT** in the sidebar. Under **Components**, check that **Introduction** and **Article resources** are available.

Use the named plugin ZIP, not GitHub's automatically generated **Source code (zip)**. The source archive does not contain the built runtime dependencies. This version is not distributed through WordPress.org.

### Moving from Structured Fields Prototype

Back up the entire test environment, deactivate the old **Structured Fields Prototype**, then install and activate DEFT. Do not activate both plugins together. The old plugin may remain installed but inactive; do not delete its data.

The changed slug makes this a separate WordPress installation identity, not an automatic in-place update. Existing internal storage names, permissions, and connection URLs are preserved. Do not manually replace `structured-fields` in the MCP endpoint, OAuth issuer, or `page=structured-fields` admin URL. Check saved articles, components, connections, and reservations after activation.

## 3. Set Up Staff Accounts

Use a separate WordPress account for each person. WordPress account ownership, not the AI account, determines which drafts, files, and private components a connection can access.

An administrator can assign the appropriate role under **Users**:

| Role | Access |
| --- | --- |
| atshift DEFT author | Own articles, files, and review records; bounded creation of shared structures |
| atshift DEFT publisher | Author access plus immediate/scheduled publication of own drafts and publishing connections |
| atshift DEFT designer | Author access plus shared component registration, editing, and deactivation |
| Administrator | Shared component administration and own work; DEFT does not provide access to another owner's private articles or components |

Only trusted people should receive designer access: shared HTML/CSS can affect presentation beyond an individual article. DEFT does not automatically change existing users' roles. Role changes can affect their other WordPress capabilities, so test with a dedicated account first.
Staff handover and ownership transfer are not implemented. Do not work around isolation by sharing an account or connection key.

## 4. Give the Guide to Your AI

Open **Articles** and choose **Download AI guide**. The initial dialog appears once per user and site; the download button remains available afterward.

Give `atshift-deft-ai-guide.md` to your usual AI conversation or add it to the chat project's files. Ask the AI to read it and guide the connection. You do not need to create an article first.

The downloaded guide contains connection instructions, an optional fictional sample, authoring rules, shared component definitions, schemas, and a new-article template. It does not contain private components, article text, media files, usage history, or credentials. Shared component labels and descriptions are included, so make sure those settings are appropriate to share with the chosen AI service.

The AI should perform an actual read-only MCP context request to verify the intended site. Downloading the guide, creating a key, seeing a tool list, or viewing a previous **Last connected** timestamp is not proof that the current AI conversation is connected.

### Optional Sample

After connection verification, the guide asks the AI to offer **try the sample** or **start your own article** once. You may skip it; resuming an existing article does not require repeating it.

The sample uses a fictional brief included in the guide. It creates a separate, clearly labeled private draft using existing components and previews it. No extra materials, real-file upload, new components, publication, or scheduling are part of the exercise.
Try a small revision such as "Make this shorter," then continue with your own brief. The sample remains a private draft; it is not automatically deleted.

If the AI cannot display a portable preview or lacks permission to retrieve one, use the owner-only WordPress preview. These are instructions for the AI, not a guarantee that every AI application will follow them. Connection or sample consent is not publication consent.

## 5. Connect Your AI

Use the exact client route shown under **AI connections**. The [AI Client Connection Routes](../wordpress/atshift-deft/docs/CONNECTIONS.md) document distinguishes ChatGPT, Claude, Gemini, their CLI clients, and other MCP clients. Availability can vary by account, region, and application; a configuration route is not a certification of end-to-end compatibility.

For **Codex desktop**, choose its separate desktop entry, then use **Settings > Plugins > Add > Add MCP server**. Select **Streamable HTTP**, not STDIO. Later edits use the gear beside your server name in the **MCP** tab. Follow the field-by-field header instructions in [Codex Desktop App](../wordpress/atshift-deft/docs/CONNECTIONS.md#codex-desktop-app), not the CLI environment template.

For **Claude Desktop**, use **Settings > Connectors > Add**. If your private connector offers **Request headers**, choose **No sign-in** and add `Authorization` with the full value `Bearer <connection-key>`. This skips OAuth, not DEFT authentication. Follow the [Claude steps](../wordpress/atshift-deft/docs/CONNECTIONS.md#claude); never share an owner's key in an organization connector.

For **ChatGPT web**, the observed Japanese entry is **設定 > プラグイン > エージェント一覧を見る > 追加 > カスタムMCPサーバーを追加**. The last menu is in the plugin directory, not the installed-plugin settings list. DEFT uses OAuth here; successful Codex testing does not establish Chat/Work compatibility. See [ChatGPT Web Entry](../wordpress/atshift-deft/docs/CONNECTIONS.md#chatgpt-web-entry).

If a cloud connector fails while a local client works, check hosting access restrictions as well as credentials. On Xserver, review the domain's **WordPress security > Overseas access restrictions > REST API access restriction** with its administrator. Do not disable protections wholesale; see [Cloud Connection Errors](../wordpress/atshift-deft/docs/CONNECTIONS.md#cloud-connection-errors).

### OAuth

1. Have the administrator install and activate the official **WordPress MCP Adapter 0.7.1+**.
2. In **AI connections**, choose the service. Under **Register an application**, register the exact callback URL supplied by that client. Allow only the required scopes.
3. Enter the displayed endpoint and client ID in the AI client's MCP settings. Choose OAuth and leave the client secret empty. The client must support public-client authentication with PKCE S256.
4. Continue to WordPress, sign in with your own account, review the requested access, and grant it there. Do not give your password to the AI conversation.
5. Return to the same conversation and request the read-only connection check. Use the publishing endpoint and `sf:publish` only when deliberately authorizing publication.

Application registration is shared site configuration; consent and data remain owner-specific. Grants last up to 30 days, access tokens last ten minutes, and refresh tokens rotate. **Revoke** cancels your connection. An administrator's **Disable application** affects all connections and pending reservations for that application.

Dynamic client registration and client-secret authentication are not supported. If OAuth discovery shows a warning, the host may need routing configuration for the displayed metadata URLs. DEFT does not create a public tunnel for a local site.

### Connection Key

1. Ensure the MCP Adapter is active, then open **atshift DEFT > AI connections** as the intended owner.
2. Choose **Your AI / client**, read the displayed requirements, and name the connection.
3. To delegate bounded layout creation, private-file transfers, and portable previews, explicitly select **Allow reusable layouts, private file transfers and portable previews**.
4. Choose **Create connection**. Store the displayed endpoint and `Authorization: Bearer <connection-key>` in the client's secure MCP settings.
5. Return to the same AI conversation and ask it to verify the connection through an actual context request.

This route requires Streamable HTTP and configurable authentication headers. Do not put a connection key into an OAuth client ID or client secret field.
Keys are shown once, expire after 30 days, and can be revoked without deleting drafts. Configuration templates omit secrets; configure the displayed environment variable securely in that owner's client environment. Add the relevant entry without overwriting unrelated client settings.

The default connection can read component context and save/resume only the owner's drafts. Optional authoring permissions do not allow arbitrary code, overwriting shared components, or recording review approval. Existing keys do not gain additional permissions automatically. Publication requires separate authorization.

## 6. Create and Review an Article

1. Describe the audience and message, then supply the relevant materials. A layout reference image is optional.
2. Ask the AI to reuse existing components first. Only request a new permitted structure if the catalog is insufficient.
3. Transfer only images or PDFs intended for the article. Giving the AI reference material does not itself authorize publishing that material.
4. Have the AI save the draft and retrieve a preview of that saved version.
5. Request revisions in the conversation. Leave the article as a draft until you choose to publish.

Example:

> Create a visitor announcement from these materials. Reuse existing components, make the date and registration details clear, and explain your layout choices briefly in English. Ask about missing facts rather than inventing dates or prices. Save a draft and show me a preview; do not publish it yet.

Portable previews are for checking structure and content. They omit the theme and JavaScript, embed images up to a combined 2 MiB, and report omitted images or PDF contents. Use **Open theme preview** while signed in as the owner to check the actual theme. Public preview links are not issued.

### Choose a Design Direction

Ask about layout as well as wording. You can describe the arrangement or show a reference image; neither code nor a reference image is required.

- **Use the current theme's look:** "Keep the design consistent with my site." The AI reuses suitable registered components; theme styles apply where those components do not override them. Theme-specific design elements must be supported by the available components.
- **Assemble a separate article layout:** "Use an image-and-text two-column section, followed by the event details." The AI combines existing components and, with authoring permission, can fill a genuine gap with a supported stack, two-column or Q&A structure. Columns stack on narrow screens.

The AI should reuse your stated preference or suggest a suitable layout, not require a design questionnaire. A reference image is a guide to structure, not a guarantee of exact reproduction. MCP does not supply an inventory of the theme's styles, and theme settings are not changed automatically. A separate layout still appears within the theme, so check both directions in the owner-only theme preview. Additional custom styling requires a separate designer/administrator task, not arbitrary code through the AI connection.

Chat attachments do not transfer automatically. The AI client must be able to read actual file bytes and make the HTTP upload. Word documents and text can serve as source material for the AI, but managed uploads are limited to JPEG, PNG, WebP, and PDF.

### Small Manual Edits

Open **Articles > Edit article** to edit values, reorder/remove components, or change available presentation variants. Choose **Save draft**, then check the preview. Required fields and stale versions are validated when saving.

To try saving without AI, choose **New article**, enter a **Title**, add **Introduction** using the **+** button, fill **Heading** and **Body**, and save. Reload and select the article to verify that it was retained. DEFT drafts are separate from the standard WordPress **Posts** list and are not public.

## 7. Publish Now or Schedule

Publication is a separate, powerful permission. Grant it only to a trusted client.

1. Have an administrator assign **atshift DEFT publisher** to the intended staff account.
2. Authorize a publishing connection. For a key-based connection, explicitly select **Allow immediate and scheduled public publication of my drafts**, create a new connection, and configure its dedicated endpoint and key. For OAuth, grant the publishing endpoint/scope during consent.
3. Review the article, then say **"Publish now"** or **"Publish on November 5 at 9:00 a.m., Japan Standard Time."**
4. The AI should ask for any missing timing details, then use the latest saved version to publish or schedule it.
5. Check the result and the article's **Publication** state. Cancel a pending reservation through the AI or **Cancel reservation**.

An authorized connection can publish without an additional WordPress approval screen. The guide instructs the AI to ask for your intent, but the server does not prove that a human gave an instruction in chat. The separate **Review** record is not a publication gate.

Changes to the reserved article, files, or behavior policy stop the reservation; review the latest content and request a new reservation. **Revoke** cancels pending reservations. Expiry alone prevents new operations but does not cancel an already accepted reservation. A publication already in progress may finish, and revocation does not unpublish an existing post.

Reservations wait inside DEFT and create a normal WordPress post when executed. WP-Cron may run late on low-traffic sites; use a server cron job when timing matters. Updating an already-published post is not implemented: a later **Save draft** does not update its public content.
Published managed files are served from pinned private copies, not automatically added to the normal Media Library. Deactivation stops reservations, managed-file delivery, and styles.

## 8. Manual JSON Fallback

If MCP is unavailable, agree explicitly to use file exchange instead. Generating JSON is not the same as saving to WordPress.

1. For a new article, give the AI the downloaded guide. For an existing article, select it and download **Article handoff**.
2. Ask for JSON based on `composeTemplate`, containing `article` and `expectedVersion`, and describe the changes you want.
3. Choose **Import snapshot** in WordPress and select the returned JSON.
4. Review the unsaved candidate, choose **Save draft**, and check the theme preview.
5. For further changes, download a fresh handoff after saving.

The handoff may contain your article text and component descriptions; share it only with an appropriate AI service. It contains no login credential. Passwords, REST nonces, and authenticated preview URLs are not needed for this exchange.
If the article changed after the handoff, use a new handoff rather than forcing a stale update. Importing alone does not save.

## 9. Reuse Components and Behaviors

The AI searches **Used by me**, then the **Shared catalog**, before proposing a new structure. An active shared component can be used without making a personal copy. Saving records its use automatically; another person's usage list, articles, and files remain private.

If no component is suitable, use the current catalog context and its structure schema. A permitted structure registration includes `recipe`, `expectedVersion`, and `reuse`, including the considered IDs/versions, `catalogToken`, and the reason for the new structure. A duplicate structure returns the existing ID and version; the AI must use the returned values, not assume its requested ID was created.

For manual registration, give **Components > AI handoff** to the AI, import the result with **Import component**, and choose **Register version**. Bounded structures support `stack`, responsive two-column `columns`, and `qa`, with up to 12 text, textarea, image, or link fields. They do not accept arbitrary code, custom classes, nested repeats, or submission forms.

Shared structure definitions contain no article values. Keep personal data, client details, and secrets out of their names and descriptions, which are visible to staff. Matching is based on normalized definitions, not complete semantic understanding.

The standard limits are 100 site-wide components, 30 saved versions per component, and 20 new shared components per owner. Inactive components count; reuse does not consume a new-component slot. No automatic deletion occurs. Existing articles retain their pinned component version rather than changing when the catalog is updated.

Earlier private components remain under **My private components** and are not automatically shared. **Register shared copy** is an explicit, privileged exception that may share sample content as well as definitions. Check it carefully before using it; ordinary shared-structure reuse does not require it.

Prefer native HTML, then CSS, and JavaScript only when needed. Q&A uses native expansion, entrance animation uses CSS, and image zoom uses a bundled script. **Components > Behaviors** lists available behavior IDs, versions, targets, and settings. Article-level settings are under **Edit article > Behaviors**. Arbitrary or external scripts cannot be registered. Behavior changes may invalidate earlier review records; reload previews after changes.

## 10. Configure Private Images and PDFs

You can postpone this setup while trying a text-only sample. Before using private uploads, have the server administrator create a dedicated directory outside every public document root and configure the real absolute paths in `wp-config.php`:

```php
define( 'SF_PRIVATE_MEDIA_DIR', '/srv/private/structured-fields' );
define( 'SF_PUBLIC_DOCUMENT_ROOT', '/srv/www/example/public' );
```

These are examples, not paths to copy unchanged. Back up the configuration first. Ensure the storage is not exposed by another site, alias, or CDN. Private subdirectories use mode 0700 and files use 0600. Symlinks or uncertain public boundaries are rejected rather than falling back to normal uploads. Windows ACL environments are not supported.

1. Save your article, then open **Private files**.
2. Choose **Reference only** for background materials or **Publication candidate** for files intended for the article.
3. Use **Add files** to upload JPEG, PNG, WebP, or PDF files.
4. Add a component with an image or link field. Under **Article media**, choose the file and provide alt text or link text.
5. Save and preview the article.

Reference-only files cannot be bound into the article. Publication candidates remain private until used in publication. Existing WordPress media search is limited to supported files uploaded by the owner; their original public URLs do not become private, and the originals are not modified or duplicated by reuse. Missing or changed bytes cause a refusal rather than silently substituting different content.

Default limits are the lower of 10 MiB or the host's limit per file, 50 files / 100 MiB per article, 100 MiB per owner across articles, and 500 MiB site-wide. Both purposes count toward private storage limits. Retransmitting identical bytes for the same article and purpose does not double-count. Owner quotas can be adjusted through server-side filters documented in [ALPHA12.md](ALPHA12.md).
Acceptance does not mean a file has passed virus scanning or PDF sanitization.

## 11. Language, Review, and Backups

The AI is instructed to respond in your preferred language, even if the administration UI is English. An explicit preference takes priority over the conversation language, then the WordPress user locale. Country alone does not determine language. **Components > Reply language** accepts an explicit locale such as `en-US`, `ja`, or `pt-BR`. Article language may differ from the language of explanations; IDs and field keys are not translated.

**Review** records an account's decision about a saved version and its files. Approving does not publish, and changed content or files invalidate earlier approval. Unpinned image URLs or external CSS images prevent this strict file review. It is not proof of human presence.

Resume with the same WordPress account. Deactivation retains data, but retention/deletion controls and ownership handover are not implemented. Before an update, back up the database, plugin, and private files together. Restoring an older ZIP alone may not restore newer-format data.

## Troubleshooting

| Situation | Action |
| --- | --- |
| DEFT menu is missing | Check the WordPress account's role. |
| Another person's article is unavailable | This is intentional isolation. Do not share a login to bypass it. |
| `sf_catalog_stale` / Catalog changed | Read the current catalog and request a fresh handoff. |
| `sf_reuse_component` | Use the specified existing component; check your private list if relevant. |
| `sf_component_quota` / `sf_shared_component_quota` | Reuse or revise existing components; ask an administrator about the applicable quota. |
| `sf_asset_user_quota` | Reuse existing files or ask about the owner's storage quota. Nothing is automatically deleted. |
| Article changed | Reload the article and use its latest saved version. |
| Private storage unavailable | Ask the host administrator to check the paths, constants, public boundaries, and permissions. |
| API returns 401/403 | Check the account, scope, credential expiry, and session/nonce where applicable. Do not disable authentication. |
| Cloud connector returns 403 or cannot connect, but a local client works | Check hosting/WAF logs and overseas REST API restrictions. Review a narrowly scoped exception with the administrator; see [Cloud Connection Errors](../wordpress/atshift-deft/docs/CONNECTIONS.md#cloud-connection-errors). |
| Image is missing | Check ownership, purpose, hash, authentication, and external-image restrictions. |
| No publish button | Use an explicitly authorized publishing AI connection and give the intended timing. |
| Publication is invalidated / blocked | Recheck the latest article, files, and permissions, then request a new reservation. |
| Publication is overdue | Ask the server administrator to check WP-Cron. |

Report bugs, feature requests, or improvements through [GitHub Issues](https://github.com/at-shift/atshift-deft/issues) or the [English support form](https://plugins.at-shift.net/en/support/?product=deft).
For vulnerabilities, use the form's **Security report** category privately. Never include passwords or connection keys.

[Japanese user guide](../wordpress/atshift-deft/GETTING-STARTED.ja.md) | [Back to README](../README.md)
