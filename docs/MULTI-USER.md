# WordPress Staff Isolation (introduced in alpha.5, extended through alpha.10)

Alpha.10 adds owner-issued, expiring MCP connection keys without transferring
article ownership. The key is limited to component context and own-draft read/save,
not the owner's general WordPress privileges. See [ALPHA10.md](ALPHA10.md).

Alpha.8 adds global content-free structures and a private per-user usage index.
An author may use any active shared definition without copying it or requesting
per-part approval. Saves record usage transactionally; another user's usage is not
visible. Existing personal definitions remain private and are never auto-promoted.
Shared updates/retirement still require management authority. See [ALPHA8.md](ALPHA8.md).

## Identity and Workflow

Use one WordPress account per person, not one shared team login. The AI provider
account or chat session does not own data. A person saves a draft and resumes its
article ID/version later, even from a new login. Multiple people can connect at
the same time; there is no server-wide current article. This is not collaborative
editing or staff handover. MCP authentication still binds work to the original
WordPress person, not their chosen AI provider.

An administrator explicitly assigns the `sf_author` role (Structured Fields
author) using WordPress Users. It grants `read`, `upload_files`, `sf_edit_drafts`
and `sf_review_drafts`, not `manage_options`, `publish_posts` or access to others'
drafts. Installation registers the role but never assigns it to an existing user.
Generic editors do not gain access automatically. Administrators may use their
own workspace, but the plugin UI/API has no cross-owner override.

## Ownership and Concurrency

- The first snapshot's `created_by` is the immutable owner. Later versions, assets,
  transfer grants and reviews belong to that article. Clients cannot specify an owner.
- Existing articles retain their first creator without payload migration. A shared
  historical admin account stays the owner; there is no automatic distribution.
- Missing/deleted owners fail closed. Do not delete an owner's account before a
  separate, audited recovery/handover design is available.
- Listing, read/export/history, save, preview, asset upload/list/download, transfer
  issue/revoke, review read/approve/withdraw all check the target owner. No admin bypass.
- WordPress library search/reuse is limited to attachments uploaded by the current
  account. Existing public upload URLs retain their normal WordPress access rules.
  Old snapshots that reused another account's uploads now fail closed in preview;
  files/snapshots are not rewritten or silently transferred to a new owner.
- Per-article database locks serialize even first saves. Two owners claiming the
  same ID cannot overwrite each other. Same-owner stale writes fail with a conflict.
  Different article IDs are independent. Sample buttons create random IDs.
- Core editing of preview posts is denied. The native `edit_post` capability is
  temporarily satisfied only on the owner's exact signed GET/HEAD preview request.
  Saving immutable snapshots still goes through the Structured Fields API.

## Design and Delegation Boundary

New component HTML/CSS imports require `manage_options`. Authors may use active
shared catalog hashes or hashes pinned in their own article's latest saved version.
Hash/schema validation still runs on every save. An author cannot change a
component's markup/CSS and rehash it to grant themselves design authority.
The alpha.6 catalog is shared across workspace users; articles and files are not.
Catalog registration/availability is administrator-only and does not grant article
access or publication authority. See `WORDPRESS-CATALOG.md`. Per-team catalog
assignment remains future work; scoped MCP credentials are implemented in alpha.10.

Alpha.7 extends that alpha.6 boundary: shared catalog registration can also use
`sf_register_components`; updates/availability use `sf_manage_components`. Both
still require workspace access. The opt-in `sf_designer` role grants both, without
site administration or access to other owners. Author personal creation accepts
only constrained recipes, never raw HTML/CSS. Personal catalog entries are owner-only,
including when the requesting user is an administrator. Explicit sharing creates a
separate shared copy and retains the private original. See `ALPHA7.md` for limits.

Transfer tokens remain narrowly scoped bearer credentials for exact file bytes,
purpose and article version. Anyone intentionally given that token can send only
those bytes until expiry/revocation; possession is not a WordPress identity and
cannot list data or approve. Issuer capability and ownership are rechecked on use.
Reviews require the owner's authenticated WordPress cookie, capability and exact
nonce. Removing capabilities disables use; restoring them may restore still-valid
grants/decisions. Explicitly revoke grants/withdraw approvals for permanent cancellation.
No public posting or public media promotion is implemented.

## Limits and Verification

This boundary protects operations through this plugin, not against a server/DB
administrator, a malicious plugin, stolen user credentials, or two people sharing
one WordPress account. Site-wide quotas remain shared; independent storage quotas,
fair scheduling and organization tenancy are not implemented. Multisite is not
part of the verified matrix.

`npm run test:wordpress-users` creates disposable author accounts and articles on
the isolated local site. It verifies owner/admin denial, private file and media
access, exact previews, safe designs, grants, approval, capability loss, historical
ownership, simultaneous requests, stale saves and resumed logins. It removes only
its fixture data. Existing account passwords are never changed.

Verified locally on 2026-10-30: 152 automated checks passed (138 previous cases
plus 14 staff-isolation cases), including a race between separate PHP processes.
WordPress 7.1 / PHP 8.3.36 / MySQL 8.4 / Twenty Twenty-Five; minimum versions,
multisite, hosted TLS and other themes remain unverified. Playwright/Chrome used
two separate author sessions for save/reload, images, PDF hash checks, library
reuse, approval/withdrawal/request-changes and cross-owner review denial.
Desktop 1440x1100 and mobile 390x844 had no horizontal overflow or page runtime
errors. Known favicon 404 and blocked Gravatar requests remain; the preview CSP
intentionally excludes external images. No production deployment is implied.
