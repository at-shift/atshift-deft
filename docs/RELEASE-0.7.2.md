# atshift DEFT 0.7.2

**Important setup correction:** Our previous AI guide mentioned MCP Adapter but did not include the installation and activation steps. **To connect your AI to WordPress through DEFT, you must install and activate the official [MCP Adapter plugin by WordPress.org](https://wordpress.org/plugins/mcp-adapter/), version 0.7.1 or newer.** This release adds those missing steps before AI connection setup. Manual JSON import does not require MCP Adapter.

## Getting Started

1. Update DEFT, then download a fresh **AI guide** from **Articles** and give it to your usual AI. Previously downloaded guides do not update automatically.
2. Before configuring a new AI connection, an administrator opens **Plugins > Add New Plugin**, searches for **MCP Adapter** by **WordPress.org**, then chooses **Install Now > Activate**. If already installed, update if needed and make sure it is active. Ask your site administrator if you cannot install or activate plugins.
3. Continue with **atshift DEFT > AI connections** and the client-specific instructions. Verify a read-only connection before starting an article. A working connection does not need setup repeated.

## Changes

- Add official MCP Adapter links and the required installed-and-active version to the README requirements and installation guides.
- Place prerequisite instructions before AI client selection in WordPress, with a plugin-search link for users who can install plugins and administrator-assistance guidance for other users.
- Include the same installation-first sequence in the downloaded AI guide and packaged authoring instructions, without automatic plugin installation or permission changes.
- Add Japanese translations and regression coverage for instruction order, permission-aware links and guide consistency.

## Updating

Install `atshift-deft-0.7.2.zip`, not GitHub's source archive. GitHub distributions from 0.6.0 onward can receive this release through WordPress updates; metadata is cached for up to six hours and automatic updates remain the site owner's choice. The attached SHA-256 and `atshift-deft-update.json` identify the exact GitHub ZIP.

Requires WordPress 6.9+ and PHP 8.2+. This is an early development release: back up and test on an isolated site. Saved articles, components and connection permissions are unchanged. Publication still requires separately delegated publishing access.

The separate WordPress.org review package excludes the GitHub updater and includes the same translation files. Submission is not directory approval. Earlier GitHub releases and their ZIPs are not replaced.

## Verification

- 27 packaging, translation, identity and connection-guidance tests passed.
- 69 updater checks passed, including a native WordPress upgrade from the published 0.7.1 ZIP to this 0.7.2 ZIP. Existing data/settings were preserved and tampered ZIPs rejected. Release HTTP responses were controlled fixtures for this pre-publication test.
- The installed GitHub and WordPress.org ZIPs each passed 27 prerequisite checks with MCP Adapter active and again with it inactive (108 checks total), including actual guide generation, administrator/author access and Japanese MO loading.
- All 332 GitHub and 331 WordPress.org packaged PHP files passed syntax checks.
- Plugin Check 2.1.0: WordPress.org ZIP, **0 errors / 347 reviewed warnings**; GitHub ZIP, the expected `plugin_updater_detected` error / 343 reviewed warnings. No new warning category was introduced. The updater is excluded from the submission package.

GitHub ZIP SHA-256: `797a443b835586b0c5cee7e04f95c612dd93fd81b50ea9e2d4d8a5aa418e2c6b`.
