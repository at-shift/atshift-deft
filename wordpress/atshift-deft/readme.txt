=== atshift DEFT ===
Contributors: atshift
Tags: ai, mcp, publishing, structured-content
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Structured drafts, scoped AI connections, and owner-authorized immediate or scheduled publication.

== Description ==

This is an early release. Evaluate it on an isolated test site before production use.
It does not replace atshift Fields or migrate existing posts.
DEFT stands for Delegate Execution. Focus on Thinking.

Use the AI guide from the Articles screen with your preferred AI. Drafts retain
typed content, pinned component versions, private media references and an
authenticated preview. Publishing requires a separate publishing connection and
the appropriate WordPress role.

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
5. For MCP access, install the official WordPress MCP Adapter 0.7.1 or newer separately.
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

= Can my AI decorate components without editing my theme? =

Yes. An authorized authoring connection can reuse or create shared structures with
typed color, spacing, border, typography, column and link-button options. DEFT
generates component-scoped CSS. Saved articles retain their component revisions.
Arbitrary CSS and JavaScript are not accepted by the AI structure-creation tool.

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
