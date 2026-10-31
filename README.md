# atshift DEFT

**Focus on your message. Let the AI you already use handle the rest.**

**Start with one thing in DEFT: download the guide for your AI.**

Give it to the AI you already use, then follow its instructions to connect to WordPress. Once a compatible AI client is connected and authorized, you can create and publish new articles through that conversation, without opening DEFT for everyday authoring.

Work with your AI on the article, ask it to create the page design and column layout, and tell it where the article should appear. Review the result, then simply ask, **"Publish it."** Ask your AI to select existing categories and tags, too. Published DEFT articles also output JSON-LD and a public, content-only structure that other AI tools can read.

**Focus on creating content. Let your AI handle the assembly and publication.**

*Initial setup, permission changes, reconnection and private theme-based previews can still require WordPress sign-in. Site maintenance remains a separate responsibility.*

DEFT stands for **Delegate Execution. Focus on Thinking.**

The latest GitHub release, **0.7.0**, adds per-article publication destinations, existing categories/tags and public structure JSON with JSON-LD. The **0.7.1 review candidate** adds recoverable drafts, owner information and private reservations in destination lists; it is not yet a GitHub Release. Start on an isolated test site with a backup.

## Getting Started

### STEP 1. Install the Plugin

[Download the WordPress ZIP](https://github.com/at-shift/atshift-deft/releases/download/v0.7.0/atshift-deft-0.7.0.zip), then open **Plugins > Add New Plugin > Upload Plugin** in WordPress and install and activate it.
You need **WordPress 6.9+** and **PHP 8.2+**. The [release page includes the SHA-256 checksum](https://github.com/at-shift/atshift-deft/releases/tag/v0.7.0). GitHub's **Download ZIP** contains source code, not the installable plugin package.
Upgrading from 0.5.x requires this ZIP replacement once. GitHub packages from 0.6.0 onward receive later official releases through the normal WordPress plugin update screen. Automatic updates remain your choice.

### STEP 2. Give the Guide to Your AI

Open **atshift DEFT** in WordPress and choose **Download AI guide** in the welcome dialog. Attach the file to your usual AI conversation or add it to your chat project's files.
After upgrading, download a fresh guide using the same button in **Articles** and ask the AI to read it; an earlier copy does not update itself.

> Read this guide and walk me through connecting to my site.

### STEP 3. Follow the Connection Instructions

Configure your AI client as guided, then use your own WordPress account to grant only the access you need. Continue once the AI has verified a real connection.
Your site administrator must activate the official **WordPress MCP Adapter 0.7.1+**. Available connection methods depend on the AI client. Never paste passwords or connection keys into chat.

### STEP 4. Try the Sample or Start Your Article

The guide includes an optional sample for trying draft saving and previews without preparing any materials. You can skip it.
Tell the AI who your article is for and what you want to communicate. Add supporting documents or a layout reference image when useful.
You can ask the AI to create the **page composition and column layout**, not just the text: arrange headings, images and body sections, combine stacked sections with responsive two-column layouts, and add a supported Q&A presentation. **Work with your current theme's look** or **assemble a separate article layout** from available components. A reference image is optional; the AI checks what the site supports before proposing a layout.

DEFT supports **reusable component appearance**: an authorized AI can register bounded colors, spacing, borders, typography, column proportions and link-button styling inside DEFT. These styled components are shared through the existing catalog and travel with saved articles and previews. No theme Additional CSS changes are needed.

> Create an announcement for first-time visitors. Organize these materials so the date and registration instructions are easy to find.

For example, add "Keep the look of my current theme" or "Use a two-column layout inspired by this image." Check the theme-based result in the owner-only WordPress preview; portable previews omit theme styles. This does not automatically change your theme or promise an exact design copy.

### STEP 5. Review and Choose Where and When to Publish

**Saving a draft or approving Review does not publish it.** Check the preview, then:

1. **First time only:** open **atshift DEFT > AI connections** as the draft owner (administrator or **atshift DEFT publisher**).
2. For a key-based client, check **Allow immediate and scheduled public publication of my drafts**, choose **Create connection**, and add the returned publishing URL and new key as a separate AI connection. Keep the draft connection; its key does not gain publishing rights. For OAuth, choose **Use the publishing endpoint** and grant publication access with the same owner account.
3. Return to your AI conversation, verify publication access, then say **"Publish this draft now and give me the public URL"** or **"Publish on November 5 at 9:00 a.m., Japan Standard Time."**
4. Check the returned result or **Articles > Publication**. **View published post** opens a published article; a scheduled article is not public yet. **Cancel reservation** cancels a pending schedule.

**Destination selection:** choose where each article
will appear: a normal post, a page, or an eligible existing custom post type such
as Events. If you have not specified one, the AI asks **"Where would you like to
publish this article?"**, shows the destinations available to your account, and
waits for your answer. An existing choice for this article is reused without asking
again and stated with the publication timing. Selecting a destination alone does
not publish anything. Existing categories and tags can be selected for destinations
that support them; the AI confirms the selection with you and does not create terms.

Grant publication access only to a trusted connection: it can publish **without another WordPress approval**. Keep keys in secure client settings, never chat. Initial publication only is supported; later draft saves do not update a published post.
The sample walkthrough remains draft-only. For role requirements, OAuth setup and troubleshooting, see [Publish Now or Schedule](docs/GETTING-STARTED.md#7-publish-now-or-schedule).

DEFT appears below **Settings** in the WordPress menu. Its draft workspace shows the current owner and WordPress role. Once an article is published or scheduled, it leaves the DEFT draft list and appears in the selected destination's list. Scheduled entries are marked **Scheduled (DEFT)** and stay private until DEFT rechecks authorization at the requested time. Use **Manage reservation in DEFT** or ask your AI to change or cancel the reservation; cancelled, invalidated and failed reservations return to drafts. Saved versions and files are retained.

See the [Installation and User Guide](docs/GETTING-STARTED.md) for detailed steps and [AI Client Connection Routes](wordpress/atshift-deft/docs/CONNECTIONS.md) for client-specific setup.
A [Japanese user guide](wordpress/atshift-deft/GETTING-STARTED.ja.md) is also available.

## Features

Choose the destination separately for every article. See [Publication Destinations](docs/PUBLICATION-DESTINATIONS.md) and [Categories and Tags](docs/PUBLICATION-TAXONOMIES.md).

- **Build articles through conversation.** The AI reads available components, fields, and rules to assemble headings, text, images, and links.
- **Compose pages and columns.** Ask for section order, image-and-text columns, stacked content and Q&A layouts, with an optional design reference image. Supported columns stack on narrow screens; styling stays in DEFT rather than theme Additional CSS.
- **Reuse before creating.** Search the shared component catalog first; add a permitted structure only when existing components are insufficient. Choose presentation variants such as visible or expandable Q&A answers.
- **Save and resume drafts.** Store articles under their WordPress owner and check version numbers to prevent stale updates.
- **Trash and restore drafts.** Ask your AI to move an unwanted draft to Trash, or use the trash icon beside it in DEFT. Switch to Trash and use Restore to recover it. Only the owner can do this. Cancel any publication reservation first. Trashing preserves saved versions and files; it does not delete published posts or shared components, and does not reclaim storage.
- **Use images and PDFs.** Store selected files privately and bind them to a saved article version. Supported existing WordPress media can also be reused.
- **Review previews, not editing controls.** Use a portable HTML preview or an owner-only preview with the site's actual theme. Make small manual edits when needed.
- **Publish immediately or schedule.** Let the AI set your requested time through a connection explicitly authorized for publication.
- **Choose each article's destination.** Select a post, page or eligible custom post type in conversation. The AI asks where to publish when the destination is not yet chosen.
- **Select categories and tags.** Reuse existing categories, tags or public custom taxonomy terms supported by the destination. The AI proposes a relevant selection for confirmation; native assignment rights are required. No terms are created automatically.
- **Expose public structure.** Published DEFT pages include minimal JSON-LD and a link to content-only structure JSON. External readers can inspect headings, lists, links and built-in column hints without receiving private editing data. See [Public Structure](docs/PUBLIC-STRUCTURE.md), including access-control and SEO integration notes.
- **Keep staff work separate.** Articles, files, and connections belong to individual owners. Shared structures can be reused without sharing article content.

DEFT does not include an AI model or require an AI provider's API key in WordPress. Your chosen external AI connects through MCP. The AI service's account, subscription, and supported capabilities are separate requirements.

## Connection Options

| Route | Setup |
| --- | --- |
| OAuth-capable remote MCP client | An administrator pre-registers the application with its exact callback URL. Each user grants access in WordPress. A public client supporting PKCE S256 is required. |
| MCP client or CLI with authentication-header support | Store an individual connection key in the client's secure credential settings. Secret-free CLI configuration templates are available. |
| AI without a usable MCP connection | Exchange article JSON and import it manually in WordPress. This is a separate fallback, not an automatic connection. |

Guidance is provided for ChatGPT, Claude, Gemini, and their relevant CLI routes. This does not guarantee support for every application, subscription, or region. Real-account connection, attachment access, and preview rendering each need verification.
Cloud-hosted AI clients need an externally reachable HTTPS site; they cannot reach your computer's `localhost`.

## Requirements

| Item | Requirement |
| --- | --- |
| WordPress | 6.9 or later |
| PHP | 8.2 or later, with DOM, mbstring, OpenSSL, and Sodium |
| Database | MySQL / MariaDB with InnoDB and named-lock support |
| AI connection | Official WordPress MCP Adapter 0.7.1+ and a compatible MCP client |
| Private files | Storage outside the public document root; see the user guide |
| Scheduled publication | WP-Cron; use a server cron job when timing matters |

Node.js and a dedicated AI server are not required on your WordPress host.
The tested environment is WordPress 7.1, PHP 8.3.36, MySQL 8.4, and Twenty Twenty-Five. Verification of the declared minimum versions, multisite, and all themes is not complete.

## Permissions and Data

- Create a connection for each staff member and grant the minimum required access. Do not share a personal connection key across an organization.
- Draft access and publication access are separate. An authorized publishing connection can publish without another WordPress approval screen. Approval in chat is an instruction to the AI; the server does not prove that a person actually gave that instruction.
- Revoking a connection cancels pending reservations. Token or key expiry alone does not cancel a reservation already accepted.
- Materials and previews you give to an AI are also shared with that service. Check its terms before sending confidential or personal information.
- HTML and field values are validated, and arbitrary JavaScript registration is not allowed. However, CSS registered by an administrator or designer can affect the whole page. Grant that role only to trusted people.

Report vulnerability details privately through the support form below, not in a public issue. Never send credentials.

## Scope of 0.7.0

DEFT is **separate from atshift Fields / CFS**. It does not automatically convert or migrate existing posts.
Attaching a file to chat does not automatically upload it to WordPress: the AI client must be able to read the file's bytes and perform the HTTP transfer.
Portable HTML previews do not include your theme or JavaScript. Use the owner-only theme preview to check the final presentation.

Updating already-published posts, transferring ownership between staff, file retention/deletion controls, and automatic registration in the standard Media Library are not implemented.
Deactivating the plugin stops its scheduled publication, managed media delivery, and styles.
See [FOLLOW-UP.md](docs/FOLLOW-UP.md) for known limitations and [CHANGELOG.md](CHANGELOG.md) for version history.

## Feedback

**Feature requests and improvement suggestions are welcome.** Tell us what you want to achieve, what gets in the way, and the result you expect. No technical implementation proposal is needed.

- [GitHub Issues](https://github.com/at-shift/atshift-deft/issues): bug reports, feature requests, and improvement suggestions.
- [English support form](https://plugins.at-shift.net/en/support/?product=deft&category=idea): send feedback without a GitHub account.
- [Japanese support form](https://plugins.at-shift.net/support/?product=deft&category=idea): the same feedback channel in Japanese.
- Security reports: use the [private English form](https://plugins.at-shift.net/en/support/?product=deft&category=security) or [private Japanese form](https://plugins.at-shift.net/support/?product=deft&category=security). Do not include passwords or connection keys.

## Translations

The WordPress UI uses gettext and supports additional POT, PO/MO, and JavaScript JSON catalogs.
The bundled Japanese catalog currently covers only some existing guidance, not the entire interface.
Both distribution channels include the same versioned POT, Japanese PO/MO and JavaScript catalogs. No additional complete language translations are claimed.

## Distribution Channels

The GitHub ZIP includes a GitHub-only updater; the separate WordPress.org submission ZIP does not. Their core features, plugin slug, data identifiers and translation files are identical. WordPress.org submission remains on hold; preparing a ZIP is not submission or approval.
See [Release and Update Channels](docs/RELEASING.md) for packaging, verification, first-upgrade and channel-switching steps.
See [Adding Translation Files](wordpress/atshift-deft/languages/README.md).

## Development and Build

Use Node.js 24+ and PHP 8.2+ in your local development environment.

```sh
npm ci
npm run build:php-deps
npm run pack:wordpress
```

Dependencies are locked and bundled under an isolated namespace. The installable ZIP and its SHA-256 file are written to `.local/releases/`.
Do not install the repository's source archive or an unbuilt `wordpress/atshift-deft/` directory as the release package.

| Documentation | Contents |
| --- | --- |
| [WordPress Development Guide](wordpress/README.md) | Storage, APIs, and local verification |
| [Component Catalog](docs/WORDPRESS-CATALOG.md) | Component and field contracts |
| [Staff Isolation](docs/MULTI-USER.md) | Ownership and permission boundaries |
| [Browser Prototype (Japanese)](docs/BROWSER-PROTOTYPE.md) | An independent UI and structure-design experiment, separate from the WordPress plugin |
| [0.5.0 Notes](docs/V0.5.0.md) | Migration from the earlier prototype and verification records |

Run WordPress tests only in a dedicated local environment. Never point them at a production database.

## License

[GPL-2.0-or-later](LICENSE). Dependencies retain their respective copyright and license notices.
