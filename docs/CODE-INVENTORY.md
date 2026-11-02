# Code and Compatibility Inventory

Reviewed locally on 2026-11-03 against the uncommitted 0.7.2 tree. This inventory
precedes the remaining WordPress.org remediation work; it does not replace the
security review, authorize publication, or mark its unfinished report complete.
Existing branding, icon, nonce, OAuth and review-header changes were preserved.

## Classification

| Area | Classification | Evidence and disposition |
| --- | --- | --- |
| `wordpress/atshift-deft/includes/*.php` (35 files) | Runtime / conditional runtime | The plugin entry loads four files; `includes/plugin.php` loads 29 more; `OAuth::libraries()` loads the two OAuth entity/repository files. No orphan PHP file was established. Keep. |
| `wordpress/atshift-deft/{admin,catalog,connections,connection-profiles}.js`, CSS, icons and behavior assets | Runtime | Admin enqueue paths, browser controls and the behavior registry consume them. Keep. |
| `distribution/github/class-github-updater.php` | GitHub-only runtime | `scripts/package-wordpress.mjs` injects the bootstrap and implementation only for the GitHub channel. Keep out of WordPress.org ZIPs. |
| `src/`, `server/review-*.mjs`, `server/validate-worker.mjs`, `vite.config.js` | Active development prototype | `src/main.jsx` loads App and ArticleWorkspace; ServerReview calls the local review client; Vite mounts the review middleware/store and the store launches the validator worker. Documented in `BROWSER-PROTOTYPE.md`; not shipped as WordPress runtime. Keep. |
| `server/snapshot-schema.mjs`, `server/behavior-schema.mjs` | Shared build inputs | `scripts/build-wordpress.mjs` generates the packaged v1/v2/v3 schemas from these modules. Removing the local server folder wholesale would break the WordPress build. Keep. |
| `ai-kit/`, `article-kit/`, `scripts/`, `tests/`, sample/reference images | Build, test and authoring inputs | Package scripts, Vite, schema generation and AI-kit builds reference these sources. Reference images and prototype assets are not WordPress runtime files. Keep separate from the ZIP. |
| Versioned release/review/alpha documents | Historical records | Describe the corresponding checkpoints, not unused executable code. Preserve; use the current remediation checklist for current status. |
| `.local/`, `node_modules/`, `dist/`, generated `public/ai-kit/` | Ignored local/generated material | Already excluded from Git and absent from the package allowlist. Do not delete local databases, user drafts or credentials as code cleanup. |
| Four unused Lucide imports in `src/App.jsx` | Confirmed unused | `ChevronDown`, `Braces`, `Link` and `ExternalLink` had no JSX/JavaScript reference. Removed only these import specifiers. |

## Compatibility Is Not Dead Code

- `StructuredFields`, `sf_`, REST/MCP routes, OAuth issuer identities, roles,
  option/table/meta keys and browser storage keys are still consumed. Prefix
  changes belong to R05 and require an explicit migration plan and tests.
- `legacy-guard.php` prevents simultaneous execution with Structured Fields
  Prototype, including multisite activation order. Its old plugin path is an
  intentional conflict check, not an unused include.
- `Snapshot::schema()` and validation select all three snapshot formats. Existing
  v1/v2 data and current v3 behavior bindings must keep their respective schemas.
- Schema-version installation branches and the branding-label migration still
  run when an older installation is upgraded. Do not remove them because a new
  installation no longer needs that branch.
- `PostTypes::legacy_post()` preserves the existing scoped ordinary-post
  permission. It is called by selection, publishing and post-edit checks; removing
  it would change existing authorization behavior.
- OAuth repository methods such as `getNewAuthCode`, `finalizeScopes` and
  `persistNewAccessToken` implement vendor interfaces. Their callers are inside
  the bundled OAuth library. WordPress admin pages/notices likewise use string
  callbacks and are not dead merely because a direct call search finds none.

## Method and Limits

The inventory traced package allowlists, PHP includes, WordPress hooks/REST
callbacks, MCP dispatch, lazy OAuth loading, Vite imports/worker entrypoints and
schema/AI-kit generators. PHP tokenization and JavaScript AST inspection produced
candidates, followed by manual caller checks. CSS selectors were screened for
missing source references; no selector was sufficiently proven unused to remove.
No tracked backup/reject files were found in the checked Git paths.

This is not proof that every possible runtime branch executes, nor an exhaustive
third-party dependency-pruning exercise. Vendor code and its integrity manifest
were not edited. No PHP endpoint, permission check, migration, schema, stored
record or public interface was removed. The OAuth whitespace-state compatibility
issue and unfinished security-report finalization remain in
`WORDPRESS-ORG-REMEDIATION.md` / the retained scan; this cleanup does not fix them.

## Verification

`tests/distribution.test.mjs` now checks that both ZIP channels exclude prototype,
test, build and local-state trees while retaining runtime guards, OAuth adapters
and all three supported snapshot schemas.

- Node tests: 58 build/package/branding/i18n/guidance/schema/registration checks
  and 13 local review-server checks passed, with no skips. The latter cover
  save/reload, restart persistence, concurrent version conflicts, confirmation
  binding and HTTP session/CSRF restrictions in the development prototype.
- PHP fixture tests: legacy activation guard 12 and runtime guard 16 passed.
  These are fixture checks, not a real multisite or unsupported-PHP installation.
- Browser build and AI-kit generation passed before and after cleanup.
  `diff -qr` confirmed identical complete `dist` output, including HTML, CSS and
  JavaScript. The existing two Lucide `use client` bundler warnings occur on both
  builds. No new visual/screenshot verification is claimed.
- WordPress schema/sample generation matched the source plugin tree exactly.
  Both newly built ZIPs passed the five distribution tests. `cmp` confirmed each
  channel is byte-identical to its pre-cleanup package: WordPress.org SHA-256
  `76ce41240ccf1180544ed9c608fad92117e03b4fdf83b230ff787d373f73544a`,
  GitHub SHA-256
  `d082d4993e064d14129fb707318e44fc30dd8b2ab77939e7d3b281f02ba11e84`.
- Initial fixture runs lacked the copied core translation helpers, WP-CLI path
  and local ZIP fixture; supplying those test dependencies resolved the failures.
  The local HTTP suite initially hit a Node assertion in the restricted process;
  its complete permitted loopback run passed without a source change.
- `git diff --check` passed. No existing WordPress database or account was used,
  and no WordPress upgrade/migration run or Plugin Check rerun is claimed for this
  cleanup. Packaged runtime bytes did not change. The disposable test server exited.

Local artifacts: `/private/tmp/deft-code-inventory-NOesDc/` (before/after ZIPs and
isolated checkout/build). Version remains 0.7.2. No commit, push, release, site
deployment or WordPress.org application update was performed.
