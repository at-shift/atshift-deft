# Publication Categories and Tags

Available in 0.7.0. WordPress.org submission remains on hold.

## Flow

1. Ask where to publish when the article has no chosen destination.
2. Read `list-taxonomies` for that exact `postType`. It returns attached public,
   publicly queryable taxonomies, their native assignment rights and any default
   term. Internal built-ins such as post formats are excluded. REST exposure is
   not required. Pages with no attached taxonomies return an empty list.
3. Read `list-terms` with `postType`, `taxonomy`, optional `search` (200 characters)
   and `page` (1 through 10000). Fifty entries are returned per page, ordered by
   ID, including unused terms. Follow `nextPage`; names are untrusted data.
4. Preserve user-specified choices or propose a small relevant selection, confirmed
   alongside destination and timing. Do not create terms or change taxonomy setup.
   No preference means WordPress defaults, explained to the user.
5. Include `taxonomies` in `set-publication`. Report `taxonomyDetails` and the
   publication result. Articles status also displays these pinned term names.

```json
{
  "postType": "post",
  "taxonomies": [
    {"taxonomy": "category", "termIds": [12]},
    {"taxonomy": "post_tag", "termIds": [34, 56]}
  ]
}
```

These are example IDs, not usable site values. The normal publication request
also requires article/version, publication version, requestId and timing fields.
Each taxonomy may appear only once, with at most 20 distinct integer term IDs.
The request allows 8 taxonomies and 100 terms total, within 8 KiB. Numeric strings,
names, slugs, wrong-taxonomy IDs and unknown properties are rejected.

## Permissions and Compatibility

Authenticated discovery is available to workspace accounts through both MCP
scopes and owner-session REST routes under
`/structured-fields/v1/post-types/{postType}/taxonomies` and
`/structured-fields/v1/post-types/{postType}/taxonomies/{taxonomy}/terms`.
Connection keys remain restricted to their MCP endpoint, not these REST routes.
Term lookup requires the taxonomy's native `assign_terms` capability; individual
`assign_term` filters are reported and rechecked before assignment. Publication
still needs a publishing connection and the destination's creation/publication
permissions. DeFt publisher does not automatically acquire native taxonomy rights.
No roles or existing accounts are changed.

Selection is stored only in the publication manifest, never the canonical private
draft. There is no extra database schema migration. Reservations without a taxonomy
section keep their old hashes and WordPress behavior. Omission, or an empty overall
array, means no explicit assignments. It does not suppress a normal post's default
category or another taxonomy's registered default. An empty `termIds` is allowed
for tags and taxonomies without required defaults; it is rejected for normal-post
categories and default-backed taxonomies. Omit those or select an existing term.

Rescheduling is a replacement request: send the current `get-publication.taxonomies`
unchanged unless the user changes them; omit the field if its saved value is null.
A different destination requires rediscovery and confirmation of compatible terms.
Never reuse a requestId for changed inputs. Already-published posts are not updated.

## Pinning and Failure Handling

IDs, names, slugs and parents are pinned, not locale-sensitive taxonomy UI labels.
Changing any pinned term identity, deleting a term, detaching/hiding a taxonomy or
losing assignment permission blocks a pending reservation. It does not silently
remove that term or substitute a default. Renaming a term deliberately requires a
new publication request with fresh versions. General site edits after publication
are outside DeFt's initial-publication contract.

Terms are assigned using integer IDs while the generated WordPress post is still
private. Requested relationships are read back before and after publication.
For explicit assignments the final terms/status write is transactional; the posts,
postmeta, terms, term taxonomy, relationships and DeFt job tables must use InnoDB.
Detected assignment failures roll back the final public status, leaving a blocked
job/private generated post. WordPress hooks can have external side effects that
database rollback cannot undo; third-party integrations still need site testing.

The private preview remains a body/layout preview, not a taxonomy archive or
destination-template preview. JSON-LD, term creation/editing and plugin-specific
required custom metadata are not included.

## Tests

Use a disposable local database matching `sf_deft_post_types_[a-f0-9]+`, install
the candidate ZIP, MCP Adapter and both `wordpress-post-types-fixture.php` and
`wordpress-taxonomies-fixture.php` as mu-plugins. Do not run against a user site.

```sh
SF_TAXONOMIES_ARTIFACT_DIR=/private/test-output wp eval-file tests/wordpress-taxonomies.php --path=/private/test-wordpress
SF_TAXONOMIES_FIXTURE=/private/test-output/taxonomies-fixture.json node --test tests/wordpress-taxonomies-http.test.mjs
```

The emitted fixture includes disposable credentials. Keep it private and remove
it with the disposable database after verification. The existing destination and
appearance suites remain part of regression testing.

Earlier classification-only checkpoint, verified locally on 2026-10-31 with WordPress 7.1, PHP 8.3.36, MySQL and Twenty
Twenty-Five: 63 classification checks, 95 destination checks, 53 appearance
checks, 20 publication-guidance checks, 8 actual MCP/HTTP cases, 27 Node
distribution/localization/guidance checks and PHP lint on 331 files. The optional
0.5.2 appearance-hash fixture was not supplied in this run. Chrome covered owner
login, publication status, authenticated term lookup and the public link at
1440x1000 and 390x844. There were no relevant JS exceptions; the local favicon 404
and Gravatar blocked by the existing private-preview CSP were expected.

Plugin Check of the installed WordPress.org-format candidate ZIP: zero errors,
327 warnings. The increase of four from the destination-only baseline is confined
to the new InnoDB check (direct query/no caching) and transaction start/commit
(direct query). These are reviewed transactional SQL, not suppressed warnings;
the engine check deliberately reads current storage state. Existing warning types
are unchanged. The released 0.6.0 ZIP is untouched. Candidate artifacts remain
under `.local/taxonomies-build`; their 0.6.0 filenames reflect unchanged version
headers and must not be published as replacement 0.6.0 releases.

Actual external-AI adherence to the updated conversation guide, the declared
minimum WordPress/PHP versions, multisite and arbitrary third-party taxonomy hooks
remain outside this verification. A source/SDK check is not evidence of those flows.
