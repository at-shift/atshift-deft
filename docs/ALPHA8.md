# Alpha.8: Global Structures and Private Usage

Private alpha.8 code checkpoint. No Release, deployment or publishing operation.
Existing atshift Fields and the browser prototype remain separate.

## Model

Reusable structures belong to the site catalog. A user's list records use of the
same component ID and exact revision, not a personal copy. Every workspace author
can discover active shared definitions and use them without a per-component approval.
Only component managers may revise/retire existing shared definitions. Article
values, assets and another user's usage remain private.

Existing shared designs retain their explicitly shared examples. Existing personal
definitions are NOT converted, enumerated to other users, or merged automatically.
The private recipe API remains a compatibility/exception path. Default new work
uses content-free global structures. AI metadata is shared configuration, so labels,
keys, purpose and guidance must be generic; the system cannot infer confidentiality
from arbitrary text. No private article is mined to produce shared metadata.

## Registration

`POST /structured-fields/v1/catalog/structures` accepts the strict
`{recipe, expectedVersion, reuse}` schema exposed as `schemas.structure`.
It retains typed fields, layout and guidance, but rejects field `value`, `alt`,
`assetRef`, HTML, CSS, JavaScript, behavior references and owner overrides.
The server builds fixed markup/CSS with empty slots. Required values are enforced
on article blocks, not on a definition's empty examples. The canonical v2 snapshot
shape and existing component hashes are unchanged; old article payloads are not rewritten.
Older plugin validators may reject empty required definition slots. This is not a
downgrade guarantee: restore a complete backup rather than replacing only the plugin ZIP.

Within the catalog lock, new registrations first check shared structural equivalents.
An active match returns its original ID/revision with `reused: true`, including when
two accounts submit concurrently. No record is copied or renamed. If matches exist
but none is active, registration is blocked; an active match takes precedence over
an inactive one. Private definitions are excluded from this
global duplicate resolution. For genuinely new definitions or revisions, fresh
catalog review, considered references and a gap reason are required. Returning an
already-readable duplicate does not require a fresh receipt because it writes nothing.
Always compose with the returned identity rather than the requested new ID.

Matching remains normalized structural equality, not semantic AI equivalence.
The 100 site IDs, 30 revisions per ID, 512 KiB request and 32 MiB definition limits
remain. There is no automatic pruning, deletion or merging of saved definitions.
Legacy private quotas and delegated raw-design capabilities remain unchanged.

## Usage

Schema version 3 adds `definition_kind` (old rows default to `design`) and the
`sf_component_usage` InnoDB table, keyed by user/component/revision. New article
versions record references in the same transaction as the snapshot. Rejected saves
cannot enroll components. No article content or asset information is stored there.
No bulk historical scan occurs; pre-alpha.8 articles acquire usage entries on their
next changed save. Exact idempotent retries do not rewrite a prior snapshot or usage.
Deleting a WordPress user removes only that user's usage links, not article data.

`GET catalog?scope=used` filters the current available catalog by the caller's usage.
Descriptors include only the caller's `usedByMe` and `usedRevisions`; no API accepts
another user ID to inspect usage. Retired definitions do not appear as new choices,
but their already-saved article pins remain usable. Usage of an old revision is not
authority to introduce it into a new article after the catalog has moved on.

`catalog/context` adds `usedComponents`, `sharedComponents`, `structureExample` and
`selectionOrder`: own usage, whole shared catalog, then creation. It retains the full
visible catalog for compatibility. The manual selector groups used and other available
parts. AI handoff language, stale proposal checks and exact-version previews remain.

## Scripted Behavior Direction

Discussed, not implemented: a component may eventually reference a registered
behavior ID/version with typed settings; trusted executable JS stays in a separate
registry rather than being copied into each part/article. New executable capability
would need separate authorization and validation. No inline JS, remote script loading,
working forms or behavior API is added in alpha.8. Q&A uses native HTML disclosure.

## Verification

Run `npm run test:wordpress-structures` for global reuse, privacy, empty-slot/required
value separation, manager-only updates, immutable pins, retirement and independent
worker races. Also run the existing personal/catalog/users/media/workflow/integration
tests. Minimum PHP/WordPress versions, multisite and real external AI connections
still require separate verification.
