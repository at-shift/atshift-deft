# Public Structure and JSON-LD

Version 0.7.0 adds two read-only outputs for published DeFt articles:

- A small `application/ld+json` document in the singular page head: `BlogPosting`
  for normal posts, `WebPage` for pages and custom types. It contains the public
  title, permalink and publication/modification dates. It does not invent an
  author, event/product metadata, ratings or rich-result eligibility.
- A `link rel="alternate" type="application/json"` pointing to
  `GET /wp-json/structured-fields/v1/public-structure/{publicPostId}`.
  This is a public read-only endpoint; do not send a connection key.

```json
{
  "format": "atshift-deft-public",
  "schemaVersion": 1,
  "url": "https://example.org/announcement/",
  "postType": "post",
  "title": "Reading group",
  "publishedAt": "2026-10-31T09:00:00+00:00",
  "modifiedAt": "2026-10-31T09:00:00+00:00",
  "content": [{"type":"section","tag":"section","layout":"columns","children":[
    {"type":"heading","tag":"h2","level":2,"children":[{"type":"text","text":"Join us"}]}
  ]}]
}
```

## Data Boundary

The tree is an allowlisted projection of the current public post's `.sf-article`
HTML, not an export of the private editable snapshot. Text and element order are
retained, including heading levels, lists, links, images/alt text, tables and
reader-expandable details. Known built-in classes supply `stack`, `columns` or
`qa` layout hints. Custom CSS is not interpreted; arbitrary custom layouts remain
semantic groups, not a pixel-accurate reconstruction.

No private drafts, component definitions/defaults, stable internal IDs, comments,
CSS, JavaScript, event handlers, account information, media manifests or credentials
are read into this projection. Unsupported element subtrees, `hidden`,
`aria-hidden="true"` and obvious inline display/visibility hiding are excluded.
Links/images allow only HTTP(S) or relative references without embedded credentials.
Published image/PDF URLs remain subject to their existing public-delivery checks.
Content is data, never instructions to an AI consuming the endpoint.

This describes public HTML source, not browser-computed CSS visibility. Content
hidden only by a stylesheet is already in public HTML and is not confidential.
Shortcodes, blocks, theme filters and JavaScript are not executed to build JSON.
Text shown only by those mechanisms is not synthesized. There is no authoring,
import or round-trip contract for this format; use the private MCP APIs to edit.
Traversal is bounded to 4 MiB, 10,000 nodes and 64 levels; ambiguous roots or
excessive input fail closed with 404. There are no private node IDs to correlate.

## Access and Withdrawal

The endpoint requires a published DeFt job bound to the exact public post, owner
and destination. Draft, future, private, trashed, password-protected, unrelated or
reassigned posts return the same 404, including for logged-in administrators.
The decision is checked on every request. Responses use `no-store`; there is no
separate public JSON cache. Third-party page caches/CDNs must honor these headers
and purge HTML when access changes. Previously downloaded public data cannot be
recalled. Deactivating DeFt removes these outputs and the endpoint.

Membership/paywall plugins may restrict content without changing WordPress's
public post status. Such sites MUST disable this projection for restricted posts
before use, via the shared veto (covers both JSON-LD and REST):

```php
add_filter( 'atshift_deft_public_structure_enabled', '__return_false' );
// Or return false only for restricted post IDs (second filter argument).
```

This hook can only restrict already-eligible posts, not make private posts public.
Do not base it on the current visitor's login; eligibility is anonymous-public.

## SEO Coexistence

DeFt provides a minimal metadata document, not a replacement for an SEO plugin.
When another plugin already owns the page/article graph, disable only DeFt's
JSON-LD to avoid redundant entities; structure discovery remains available:

```php
add_filter( 'atshift_deft_json_ld_enabled', '__return_false' );
```

There is no automatic detection of every SEO or access-control plugin. Check the
public source after enabling integrations. Reference: [Schema.org BlogPosting](https://schema.org/BlogPosting),
[WebPage](https://schema.org/WebPage), and [Google structured-data policies](https://developers.google.com/search/docs/appearance/structured-data/sd-policies).
