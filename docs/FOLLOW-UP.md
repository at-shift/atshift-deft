# Follow-up Work

## Local 0.5.0 Rename

- Product: atshift DEFT (D.E.F.T.); slug: `atshift-deft`. See [V0.5.0.md](V0.5.0.md).
- Local work only: no commit, push, release or deployment. Repository name unchanged.
- Guide-first setup now asks the AI to guide connection and verify live context,
  then offer an optional draft-only sample or continue the original article brief.
  Real AI-provider conversations still need separate validation.
- Before activating this package, deactivate Structured Fields Prototype without
  deleting data. Storage, permissions and MCP/OAuth identities remain compatible.
- Verify name/trademark availability and WordPress.org slug allocation before public release.

## Private Alpha.14 Checkpoint

- Per-owner pre-registered OAuth, PKCE S256, discovery, token rotation/revocation,
  and a plugin-wide PHP 8.2 runtime warning. See [ALPHA14.md](ALPHA14.md).
- Still needed: real ChatGPT/Claude/Gemini account tests on an authorized public
  HTTPS site, including callbacks, provider scope handling, attachments and previews.
- Automatic registration/CIMD and confidential-client authentication are not included.
- PHP 8.2 and pre-8.2 engine execution, multisite and subdirectory/rewrite hosting
  need separate environment tests. Current test engine is PHP 8.3.36.
- a14 is a private GitHub code checkpoint; no tag, Release or deployment.

## Alpha.13 Checkpoint (Historical)

- New explicit authoring grants connect bounded structure creation, own media
  discovery, exact-file transfer preparation and portable private previews to MCP.
- See [ALPHA13.md](ALPHA13.md). Private code checkpoint; no public Release or
  production deployment.
- Initial ChatGPT target expanded to Claude, Gemini and other clients. The connection
  screen and secret-free CLI templates distinguish actual client capabilities;
  see [connection routes](../wordpress/atshift-deft/docs/CONNECTIONS.md).
- Next: common per-owner OAuth for ChatGPT, eligible Gemini Apps accounts and
  individual authorization in Claude organizations. Never share one staff key as
  an organization connector. See [CHATGPT-INTEGRATION.md](CHATGPT-INTEGRATION.md).
- Still needed: real chosen-AI connection/attachment/artifact verification and
  standard OAuth onboarding for clients without custom Bearer support. Do not
  present SDK validation as automatic compatibility with every AI service.
- Portable HTML is not the active WordPress theme; exact theme preview still uses
  the owner's WordPress session. No anonymous preview link is introduced.
- The older alpha sections below describe their historical boundaries, not the
  current scope. Alpha.11 already added separately delegated publication.

## Local Alpha.9 Work

- Implemented bundled native/CSS-first behavior references, typed instance settings,
  v3 snapshots, exact asset revisions, and administrator stop/restore controls.
- See [ALPHA9.md](ALPHA9.md). Not pushed, released or deployed to production.
- Future: separately authorized third-party code registration, retained multi-version
  behavior catalogs and form/backend capabilities. No arbitrary JS upload exists.

## Included in the Private Alpha.8 Checkpoint

- Site-wide bounded structure recipes omit all input values, alt text and assets.
  Identical concurrent registrations reuse one shared ID rather than copying it.
- Per-user usage is recorded with article saves; AI searches own usage, then all
  shared components, before creating a genuinely missing structure. No extra user
  confirmation is needed for normal reuse. Other owners' articles/usage stay private.
- Existing private definitions are retained without automatic promotion. Shared
  definition changes still require management authority and never rewrite old articles.
- See [ALPHA8.md](ALPHA8.md). No tag, Release or deployment.
- Discussed future direction only: versioned behavior references on components,
  with separately trusted JS registration. No JS or behavior execution in alpha.8.

## Alpha.7 Work Included in Alpha.8

- Follow [ALPHA7-AI-HANDOFF.md](ALPHA7-AI-HANDOFF.md): reuse existing components
  first, consider supported combinations/presentations, then create only for a
  demonstrated gap. Explain choices in terms of the reader and communication goal.
- Return proposals, questions and explanations in the user's language, even when
  admin/API surfaces are English. Explicit preference and conversation language
  take precedence over country-only assumptions; content language is separate.
- Runtime handoff download and composition JSON import now carry these rules.
- Owner-private recipes use server-generated stack/columns/Q&A markup, fresh reuse
  context, normalized duplicate checks and per-user limits. Q&A supports native
  visible/expandable presentations without recreating its field values.
- Shared registration/management capabilities and an opt-in designer role replace
  the administrator bottleneck for shared design work. No existing role is reassigned.
- See [ALPHA7.md](ALPHA7.md) and the packaged Japanese getting-started guide.
  Included in the private alpha.8 checkpoint without a separate tag, Release or deployment.

## Alpha.6 Work Included in Alpha.8

- Server-owned shared component catalog, administrator registration, stable keys,
  immutable revisions, availability and compare-and-swap update checks.
- ID/value-only article composition API and strict machine-readable schemas.
- Minimal WordPress article creation/editing, with existing owner isolation and
  pinned old component definitions preserved. See `WORDPRESS-CATALOG.md`.
- Included in the private alpha.8 checkpoint without a separate tag, Release or deployment.

## Completed in Alpha.5

- First-creator ownership across all article, private-file, transfer and review
  operations; two-user isolation also applies to administrators using plugin APIs.
- Dedicated author role and pinned-component restriction; owner-scoped WordPress
  media search, unique sample IDs, read-only native preview access and CAS checks.
- No existing records or user roles are automatically reassigned.

## Completed in Alpha.4

- Exact-file transfer grants: short-lived, revocable, no administrator password
  handed to the upload client; CLI checks destination and bytes before transfer.
- Separate authenticated review of the exact article version and managed assets,
  with approval, changes-request, withdrawal, idempotent retries and invalidation.
- Account-authenticated intent only. No verified human presence or publishing.

## Completed in Alpha.3

- Image/PDF fields now store stable asset references and exact byte hashes.
  Private publication candidates resolve only through authenticated, version-bound
  previews; reference-only and cross-article bindings are rejected.
- Supported local WordPress media can be searched and reused without duplicating
  attachments or editing their original alternative text/caption.
- WordPress schema v2 preserves references; existing v1 snapshots are unchanged.
- Save/reload, private image rendering, PDF download, library reuse and responsive
  controls verified at 1440x1100 and 390x844. This is not a production deployment.

## Completed in Alpha.2

- 2026-10-30: The user resumed the deferred icon fix. WordPress toolbar Dashicons
  inherited a 34.2px line-height inside an 18px frame. The plugin now sets its own
  line-height and fixed flex width without changing other WordPress admin screens.
  Verified all six toolbar icons at 1440x1000 and 390x844: line-height 18px,
  centered icon frames, no horizontal overflow, and working refresh interaction.

## Before Broader Testing

- Broaden mobile layout coverage for the browser prototype and other screens.
  The new WordPress private-file flow was verified with Playwright at 390x844;
  this does not retroactively verify the earlier browser-prototype viewport tests.
- Test the advertised minimum WordPress/PHP versions and additional themes.
- Connect real AI clients to the scoped transfer API. The owning WordPress
  account currently issues grants; chat attachments are not fetched automatically.
- Alpha.10 adds scoped expiring/revocable MCP keys. Audited account handover/recovery and per-user resource quotas remain later work.
  These are not implied by draft ownership; see `MULTI-USER.md`.
- General semantic presentation families beyond the bounded Q&A adapter, richer
  supported layouts and semantic similarity need further design. Current matching
  is lexical and duplicate detection is structural, not an embedded AI model.

## Later Product Stages

- Alpha.10 exposes four draft/context tools through the official MCP Adapter. Broaden real-client coverage and evaluate standard OAuth onboarding separately.
- Add a trusted chat-side approval handoff and separately gated publishing. The
  alpha.4 WordPress approval screen is the current account-authenticated fallback.
- Keep normal workflows to one final approval covering the exact article and
  public asset set. Ask earlier only for real ambiguity, authority changes or
  irreversible operations. See `MEDIA-WORKFLOW.md`.
- Add storage retention, cleanup and uninstall policy without deleting data silently.

No public preview links, publishing, or production deployment is part of
`0.1.0-alpha.10`. MCP requires explicit client setup; see `ALPHA10.md`.
Existing atshift Fields remains separate and unchanged.
Alpha.5 is a private code checkpoint including alpha.4, not a tagged or deployed release.
