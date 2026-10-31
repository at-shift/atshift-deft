# Alpha.7: Personal Components and AI Handoff

Included in the private alpha.8 code checkpoint, not a published release. The Japanese installation and
usage guide shipped in the ZIP is [GETTING-STARTED.ja.md](../wordpress/atshift-deft/GETTING-STARTED.ja.md).
The runtime AI instructions are [AI-GUIDE.md](../wordpress/atshift-deft/AI-GUIDE.md).

## Boundary

Alpha.7 extends the alpha.6 catalog without changing v1/v2 snapshot schemas or
rehashing existing definitions. Existing catalog entries remain shared (`owner_id=0`).
Personal definitions belong to their creator, invisible to other workspace users
including administrators through plugin APIs. Existing accounts gain no new assigned
roles or broad capabilities. Authors can create only server-generated recipes.

`sf_designer` is an opt-in role with author capabilities plus `sf_register_components`
and `sf_manage_components`. Registration and management are separately enforced.
These capabilities still require workspace access. No article owner bypass is added.
Raw shared HTML/CSS registration is trusted design work, not an author permission.

## API Additions

Paths are relative to `structured-fields/v1/`, with existing authentication/nonce rules.

- `GET catalog/context`: instructions, language preference, all active visible
  descriptors, lexical matches, schemas, personal example, composition template and
  a user/site/current-visible-catalog HMAC receipt. No credentials are included.
- Optional query: `query` (200 characters), `replyLanguage`, `conversationLanguage`,
  `contentLanguage` (language tags), `articleId` (owner-only saved article context).
  Reply precedence is explicit, conversation, WordPress user locale. Content language
  remains separate and unspecified unless supplied; no country/IP inference occurs.
- `POST catalog/personal`: `{recipe, expectedVersion, reuse}`. Recipe uses id, key,
  name, purpose, fields, layout and guidance. Reuse contains catalogToken, considered
  `{id, revision}` entries and a nonblank missing-capability reason (10-1000 characters).
- `POST catalog/{sourceId}/share`: `{id, key, expectedVersion}` where body id/key name
  a new shared copy, and expectedVersion belongs to the owned personal source.
  Requires registration capability. Source ownership/version is rechecked under lock.
- `GET catalog?scope=personal&includeDisabled=1` includes the owner's inactive items.
  `scope=all|shared` retains the existing restriction on viewing disabled shared items.
- Shared `POST catalog` optionally accepts guidance. Metadata stays outside immutable
  snapshot components; do not add it to article JSON. Schema remains strict.

Guidance is `{contentKind, suitableFor, avoidFor}`. Descriptor fields and named
presentations are exposed with disclosure hints: visible, expandable or unspecified.
Changing guidance advances catalog CAS version, not the rendering hash.

## Bounded Generation

Recipes accept 1-12 typed leaf fields, with the existing text/textarea/image/link
contracts. `stack` and `columns` generate fixed markup and minimal, class-scoped CSS.
`qa` requires question/text then answer/textarea; list and accordion are native
presentations of those same values. The `data-sf-layout="qa"` marker is constrained
to a section with exactly the question h3 and answer p bindings. The PHP renderer
derives details/summary for accordion after value binding; no JavaScript is needed.
This is a bounded Q&A adapter, not a general arbitrary presentation-tree engine.

Personal creation has no raw HTML/CSS, custom classes, scripts, forms or nested loops.
Rendering remains canonical and pinned in stored snapshots. Existing content is not
rewritten when a catalog entry, guidance or availability changes.

Creation/revision requires a fresh catalog receipt and valid considered references.
The global catalog lock serializes receipt checks, duplicate checks, quota checks and
writes. Fingerprints ignore IDs/names/labels and bound example values, but retain
typed field keys, structure, CSS and variant identities. This catches normalized
copies, not semantic equivalence. Reason text is an audit record, not proof that an
AI reasoned correctly. Matching is lexical, not a model invocation.

Personal limit: 20 IDs per user including inactive entries. Existing site limits
remain 100 IDs total, 30 immutable definitions per ID, 512 KiB registration requests,
32 MiB immutable payloads. Recipe/guidance/reuse records are additionally bounded by
their schema and request limit. Nothing is deleted automatically. Private duplicates
across different owners are permitted rather than revealing another person's data.
Sharing checks shared duplicates while retaining the private source.

## Manual Handoff

Articles and Components expose an AI handoff download. Articles include only the
selected owner's saved article; unsaved changes must be saved first. A context
without an article provides a fresh empty composition template. Descriptors contain
field contracts and presentation IDs; raw HTML/CSS is not needed to compose values.

`Import snapshot` also accepts the strict `{article, expectedVersion}` composition
envelope. The UI resolves catalog or pinned definitions, rejects a stale server
version, stages the candidate and saves through the composition endpoint. Canceling
an import does not alter the selected draft or save mode. Re-export after changes.
The v1 browser/local-server handoff and its permissions are unchanged.

## Not Implemented

No automatic AI/MCP connection, chat-side preview/approval, public media promotion,
publishing, retention/deletion UI, handover, semantic deduplication model or automatic
translation is added. Language instructions and validated context are delivered to
the external AI; its actual conversational compliance is not guaranteed. Minimum
versions, multisite, hosted TLS and other themes still require a compatibility matrix.
