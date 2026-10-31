=== atshift DEFT ===
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Structured drafts, scoped AI connections, and owner-authorized immediate or scheduled publication.

== Description ==

This is an experimental plugin for isolated test sites, not a production release.
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

Private storage requires PHP fileinfo, local Unix permissions and explicit server
configuration. HTTPS is required except for explicitly local loopback development.
No AI provider credentials or external AI service are bundled with this plugin.

== Frequently Asked Questions ==

= Is this ready for production? =

No. This development build is for evaluation on an isolated test site. The matching Stable tag
identifies this package for validation; it does not indicate a production release.

= Does installing the plugin publish existing drafts? =

No. Existing drafts remain private. Immediate or scheduled publication requires
the owning user's publishing authorization through the supported connection.

== Changelog ==

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
