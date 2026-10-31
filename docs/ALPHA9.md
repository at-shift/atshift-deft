# Alpha.9: Native/CSS-First Component Behaviors

Development stage `0.1.0-alpha.9`, included in the private alpha.10 code checkpoint.
Not separately tagged, released or deployed to production. Existing Fields and the browser prototype are unchanged.

## Contract

Articles now offers a permanent `structured-fields-ai-guide.md` download. Its first
visit introduction is tracked in user metadata per site and does not depend on
browser storage. The Markdown includes authoring instructions, current shared field
contracts, typed schemas and a new-article template, with no private article values,
personal components, usage records, examples, credentials or executable sources.
It requires workspace access and a WordPress nonce. The file is not an AI connection
credential; generated JSON still goes through import/save/preview. Existing article
editing uses the separate Article handoff, not the new-article template.

Prefer native HTML, then CSS, then JS only when needed. Existing Q&A list/accordion
uses native HTML. Two immutable, bundled behaviors are available: `fade-in` is a
short CSS opacity animation, and `image-zoom` adds a keyboard-accessible native
dialog. Reduced-motion users receive no fade animation. All content remains visible
without CSS/JS; enlargement controls are added only after a usable image loads.

`GET /structured-fields/v1/behaviors` requires workspace access and returns IDs,
exact content revisions, implementation type, semantic target type, strict settings
schemas, defaults and availability. `catalog/context` includes the same registry and
native/CSS/JS priority. No AI service is embedded and no code-upload route exists.

Component definitions and bounded recipes may add `behaviors`, an array of at most
four bindings. Example shape (replace the placeholder with the returned revision):

```json
{
  "key": "enlarge_photo",
  "id": "image-zoom",
  "revision": "<exact 64-character registry revision>",
  "target": "photo",
  "settings": { "enabled": true, "showCaption": true }
}
```

Targets are field keys, never arbitrary selectors. `image-zoom` requires an image
field outside an existing link; `fade-in` requires `root`. CSS durations are limited
to 100, 200, 250, 400 and 600 ms, without repeats or delayed hiding. Unknown settings,
type coercion, incompatible targets, unknown revisions and duplicate bindings fail.
Per-block `behaviorSettings` maps a binding key to its complete settings object.
It cannot add code, alter a target or select a new implementation. Repeated blocks
have separate DOM controls and settings, but load each asset only once.

## Versioning and Trust

WordPress snapshot v3 adds optional behavior bindings and settings. V1/v2 schemas,
component hashes, payloads and rendered digests remain unchanged. Definitions with
no behavior property retain their previous canonical shape. Composition uses v3
only when needed (or when already v3). The older browser prototype does not accept
v3, and older plugin validators cannot edit it: do not downgrade without a backup.

Behavior revisions cover the fixed contract, metadata and packaged asset checksums.
The build writes `behaviors/manifest.json`; runtime compares local assets with that
manifest and fails to static content on missing/tampered assets. These checks are
not a sandbox or proof that arbitrary JS is safe. Only reviewed plugin-shipped code
may execute. Keep shipped versioned files/contracts immutable in later releases;
add and retain separate versions rather than silently replacing pinned behavior.
General multi-version registry upgrades and third-party registration are not yet UI
features. This alpha ships only v1 of each behavior.

Administrators can stop/restore an exact shipped revision in Components > Behaviors
or POST `behaviors/{id}/availability` with revision, enabled and expectedVersion.
Author/designer component permissions do not grant this authority. Availability
writes use a DB lock and CAS version. No new role is granted executable-code rights.
Stopped behavior loses its runtime markers on the next authenticated preview load;
old content and saved snapshots are unchanged. An already-open tab is not remotely
revoked. Article reads report behaviorWarnings. Saving existing content is still
possible, but new component registrations cannot introduce stopped bindings.

V3 review manifests also pin the behavior availability policy counter and warnings.
A stop/restore change invalidates previous v3 reviews rather than silently restoring
approval; v2 review manifests are unchanged. This is account approval, not proof of
human presence or permission to publish. No form backend, public publishing,
external requests, remote scripts or arbitrary JS upload is added.

## Validation

Run `npm run test:wordpress-behaviors` plus the existing WordPress/AI-kit regression
suites. Browser checks must cover repeated instances, semantic image binding,
keyboard open/Escape/focus restoration, mobile layout, reduced motion, JS-off
readability, conditional/de-duplicated loading, manual settings persistence and
stop/restore handling. Build/package checks must include the manifest and v3 schema.

Verified locally on 2026-10-30: 252 existing checks plus 40 behavior checks passed.
The latest extracted ZIP's classes also passed 170 media/personal/structure/behavior/onboarding checks;
reflection confirmed that those tests loaded the packaged files. Fault injection
against the extracted copy verified static fallback for missing and changed JS.
The source tree and running installation were not replaced for this package check.
The onboarding follow-up added 19 checks for first visits, account/site separation,
download contracts and private-data exclusion; all passed, alongside the 33 base
integration checks. Browser checks confirmed a real Markdown download and creation,
import, save and preview of a proposal using only its exported shared contracts.
The notice appeared in Japanese/English, stayed absent on a second browser for
the same account, and remained available on the other account's first visit.

Chrome/Playwright passed the registration/import/manual-settings/preview loop at
1440x1100 and 390x844, including keyboard focus return, per-instance captions,
reduced motion, JavaScript disabled, no-JS CSS-only pages, and stop/restore review
invalidation. Screenshots were inspected outside the repository. No page errors;
favicon 404 and preview CSP blocks for Gravatar/WordPress emoji workers remain.
Browser plugin was unavailable, so regular Playwright was used. Other browsers,
minimum supported PHP/WordPress and multisite were not tested in this pass.

Build, PHP/JS syntax, formatting and diff checks passed. The 30-file ZIP rebuilt
with the same SHA-256. Temporary users/components were removed, both bundled
behaviors were enabled again, and the local credential file was unchanged.
