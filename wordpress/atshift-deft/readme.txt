=== atshift DEFT ===
Contributors: atshift
Tags: ai, mcp, publishing, structured-content
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create and publish WordPress articles with your usual AI. Focus on your content, not the editor.

== Description ==

Start with one thing in DEFT: download the guide for your AI.

Give it to the AI you already use, then follow its instructions to connect to
WordPress. Once a compatible AI client is connected and authorized, you can create
and publish new articles through that conversation, without opening DEFT for
everyday authoring.

Work with your AI on the article, ask it to create the page design and column
layout, and tell it where the article should appear. Review the result, then
simply ask, "Publish it." Ask your AI to select existing categories and tags, too.
Published DEFT articles also output JSON-LD and a public, content-only structure
that other AI tools can read.

Focus on creating content. Let your AI handle the assembly and publication.

Initial setup, permission changes, reconnection and private theme-based previews
can still require WordPress sign-in. Site maintenance remains a separate responsibility.

This is an early release. Evaluate it on an isolated test site before production use.
It does not replace atshift Fields or migrate existing posts.
DEFT stands for Delegate Execution. Focus on Thinking.

The AI guide is available from the Articles screen. Drafts retain
typed content, pinned component versions, private media references and an
authenticated preview. Publishing requires a separate publishing connection and
the appropriate WordPress role.

Ask the AI for page composition and column layouts as well as text: headings,
images, stacked sections, responsive two-column sections and supported Q&A.
Describe the design in conversation or provide an optional reference image.
Reuse your theme's look or assemble an article layout from DEFT components;
supported component styling does not require changes to theme Additional CSS.

See README.md for architecture and deployment constraints, GETTING-STARTED.ja.md
for installation and usage, and AI-GUIDE.md for the external-AI authoring contract.
These Markdown files are required documentation shipped with the plugin.

== Installation ==

1. Back up an isolated WordPress test site.
   If upgrading from Structured Fields Prototype, deactivate the old plugin first.
   Do not delete its data. The new slug is atshift-deft; this is not an in-place update.
2. Upload the plugin ZIP using Plugins > Add New > Upload Plugin and activate it.
3. Open atshift DEFT and download the AI guide.
4. Provision private local storage outside all public document roots before uploading files.
5. Before AI connection setup, an administrator must install and activate the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) 0.7.1 or newer. Open Plugins > Add New Plugin, search for MCP Adapter by WordPress.org, then choose Install Now > Activate. If already installed, update if needed and ensure it is active. Ask your site administrator if you cannot install or activate plugins. Manual JSON import does not require it.
6. Give the downloaded guide to your AI, follow connection setup, then try the optional private sample.
7. Describe the article and its layout, review the saved preview, and explicitly request publication only after granting publishing access.

Private storage requires PHP fileinfo, local Unix permissions and explicit server
configuration. HTTPS is required except for explicitly local loopback development.
No AI provider credentials or external AI service are bundled with this plugin.

== Frequently Asked Questions ==

= Is this ready for production? =

Evaluate this early release on an isolated test site first. Published-post updates,
ownership transfers and file-retention controls are not yet implemented.
Back up before upgrading and review the documented permission boundaries.

= Does installing the plugin publish existing drafts? =

No. Existing drafts remain private. Immediate or scheduled publication requires
the owning user's publishing authorization through the supported connection.

= Can I delete a saved draft? =

Published and scheduled articles leave the DEFT draft list and appear in their
selected destination. Scheduled entries are marked Scheduled (DEFT) and remain
private until DEFT's authorization checks pass at the requested time. Cancelled,
invalidated and failed reservations return to drafts. Saved versions and files
are retained. Ask your AI to find reservations with list-publications or use
Manage reservation in DEFT from the destination list.

Ask your AI to move the draft to Trash, or select the trash icon in DEFT's
draft list. Restore it from the Trash view using the undo icon or your AI.
Only the owner can trash or restore it. Cancel any publication reservation first.
Saved versions and files are retained; published posts and shared components
are not deleted. Restore does not publish or reschedule. There is no permanent
deletion or automatic purge, and Trash does not reclaim storage.

= Can my AI decorate components without editing my theme? =

Yes. An authorized authoring connection can reuse or create shared structures with
typed color, spacing, border, typography, column and link-button options. DEFT
generates component-scoped CSS. Saved articles retain their component revisions.
Arbitrary CSS and JavaScript are not accepted by the AI structure-creation tool.

= Can I choose where each article is published? =

Version 0.7.0 supports normal posts, pages and eligible existing
public custom post types. If you have not chosen a destination for the article,
the AI guide instructs it to ask where to publish, show available destinations and
wait for your answer. An existing choice is validated and stated without asking
again. Choosing a destination alone does not publish. Native WordPress permissions
and separate DEFT publication access still apply.

= Can the AI select categories and tags? =

Version 0.7.0 can select existing categories, tags and public
custom taxonomy terms attached to the selected destination. The AI confirms a
relevant selection with you; native assignment permissions are required. It does
not create terms. Without a selection, WordPress defaults apply. Changes to selected
terms or lost permissions stop pending reservations.

= Is machine-readable public content available? =

Published DEFT pages include minimal BlogPosting/WebPage JSON-LD and a discoverable
public structure JSON endpoint. It describes public HTML headings, lists, images,
links and built-in layout hints, not private snapshots, component definitions,
CSS/JS or account data. Drafts and password-protected or withdrawn posts return 404.
Membership/paywall sites must veto restricted posts with
atshift_deft_public_structure_enabled. SEO integrations can disable only JSON-LD
with atshift_deft_json_ld_enabled. See the Public Structure documentation in GitHub.

== External Services ==

DEFT does not call an AI provider on its own. You connect your chosen external AI
client through the separately installed WordPress MCP Adapter. That authorized
client receives the shared component catalog and the owner's requested drafts,
previews and files. Sending material to that client is subject to the provider's
terms and privacy policy. Connection setup and publication access are opt-in.
OAuth uses administrator-registered clients; it does not fetch remote client metadata.

The WordPress.org distribution uses WordPress.org updates only. The separate GitHub
distribution checks api.github.com for official at-shift/atshift-deft releases and
downloads update metadata and ZIPs from github.com. It sends its plugin version
and the request IP address, not article contents, credentials or site URL. GitHub
terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
GitHub privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement
The GitHub updater is excluded from WordPress.org packages.

== Changelog ==

= 0.7.2 =
* Add the previously missing installation and activation steps for the official MCP Adapter to the AI guide. MCP Adapter 0.7.1 or newer must be installed and active for AI connections; manual JSON import does not require it.
* Link the official plugin in requirements and show prerequisite instructions before AI connection setup, including administrator assistance and Japanese guidance.

= 0.7.1 =
* Add owner-only draft trash and restoration through the workspace, REST and MCP, preserving saved versions and files.
* Show the draft owner's display name and role; place the DEFT menu below Settings.
* Remove published and newly reserved articles from the private draft list while retaining rendering data.
* Show private DEFT reservations in the chosen post, page or custom-type list and publish into the same entry only after revalidation.
* Return cancelled, invalidated or failed reservations to drafts; prevent native editing or premature publication of reserved entries.
* Refresh the AI guide, workspace help and Japanese catalogs. Download a fresh AI guide after upgrading.

= 0.7.0 =
* Choose a post, page or eligible existing custom post type separately for each article.
* Guide the AI to ask where to publish and confirm existing categories or tags with the timing.
* Preserve native destination/taxonomy permissions and recheck pinned choices before publication.
* Add public-content-only structure JSON and minimal JSON-LD; exclude drafts and private configuration.
* Refresh Japanese catalogs and user guides. Download a fresh AI guide after upgrading.

= 0.6.0 =
* Add reusable shared component appearance with bounded settings and component-scoped CSS.
* Carry styling through saved snapshots, previews and publication without modifying theme Additional CSS.
* Preserve field-key targeting, shared reuse, author isolation and existing article revisions.
* Include matching translation catalogs and separate WordPress.org and GitHub distribution packages.
* The GitHub-only package supports verified release updates through WordPress; existing 0.5.x installs need one manual ZIP upgrade.

= 0.5.2 =
* Add publication and scheduling steps to Articles and AI connections, including Japanese guidance.
* Guide the AI from a saved draft through separately authorized publication and result confirmation.
* Expand README and user-guide steps for key-based and OAuth connections without changing permissions or saved data.
* Download a fresh AI guide after upgrading; existing guide files do not update automatically.

= 0.5.1 =
* Add separate Codex desktop setup and clarify the observed ChatGPT web and Claude connector menus.
* Document private Claude header authentication and cloud-only connection troubleshooting, including Xserver REST API restrictions.
* Clarify theme-aligned or separate article layouts and record the scope of actual client checks.
* Update Japanese connection guidance and regression tests without changing saved data or authentication behavior.

= 0.5.0 =
* Guide the user from AI-guide download to AI-led connection, an optional private sample and their own article.
* Rename the product to atshift DEFT and the plugin slug to atshift-deft.
* Preserve saved data, role capabilities, API routes and OAuth identities.
* Prevent simultaneous loading with the legacy Structured Fields Prototype plugin.

= 0.1.0-alpha.14 =
* Add individual OAuth consent for pre-registered public clients, PKCE S256, scoped short-lived tokens and refresh rotation.
* Require PHP 8.2 and stop safely with an administrator warning on unsupported PHP versions.
* Preserve existing keys, owner isolation and previously authorized reservation expiry policy.

= 0.1.0-alpha.13 =
* Add separately authorized AI structure creation, own media discovery, exact-file transfers and portable private previews.
* Simplify connection setup and show authenticated connection activity without exporting credentials.
* Keep existing keys unchanged and publication separately authorized.

= 0.1.0-alpha.12 =
* Add per-user shared component and private media limits.
* Unify HTTPS and local-loopback transport checks.
* Prepare plugin metadata and compatibility checks for the current development package.
