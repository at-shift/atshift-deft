# Publication Destinations

Available in 0.7.0. WordPress.org submission remains on hold.

## Conversation Flow

1. The AI reads `list-post-types` or `context.publication.destinations`.
2. If no destination has been selected for this article, the AI asks "Where would you like to publish this article?" in the user's language and offers the returned `accountCanPublish` destinations by label. It waits for the answer before publication or scheduling, even if only one option is available. If none are available, it keeps the draft private. The user can say "publish this as an event", "use a normal post", or "make it a page". An existing choice for this article is validated without asking again; another article's choice is not reused. Missing, ambiguous or unavailable choices require clarification, never an automatic normal-post fallback.
3. The AI saves and previews the private DEFT draft as before, mentioning the intended destination. Destination selection is not a publication request.
4. On the user's publication instruction, it states the selected destination and timing, reads `get-draft` and `get-publication`, and includes `postType` in `set-publication`. Selecting a destination alone is not a publication instruction. It preserves the selected destination when rescheduling.
5. The result and Articles publication status show the destination. Immediate publication returns its actual permalink; a reservation does not create a public post until execution.

The private draft remains `sf_article`. The destination is stored in the publication job and manifest, not in the article snapshot. Do not add it to `save-draft` payloads. Omission defaults to `post` for older clients, never as permission for the AI to skip asking or as a fallback for an explicitly requested unavailable type. A changed destination needs a fresh requestId and current publication version. Published posts cannot be moved or updated through this initial-publication API.

The question is part of the AI guide, live publication instructions, tool descriptions
and schema guidance, not a new server-side human-approval gate. The API cannot prove
that an external AI asked the question or received an answer. Previously downloaded
guides must be refreshed after the updated plugin is installed.

## Discovery and Permissions

- `GET /structured-fields/v1/post-types` is an authenticated workspace REST route. `list-post-types` is available on draft and publishing MCP connections, but is not a global/public ability.
- Only existing public, viewable types with title and editor support are eligible. Attachments, revisions, DEFT drafts, templates and other internal types are excluded. REST exposure (`show_in_rest`) is not required.
- The result includes `postType`, labels, description, hierarchy and `accountCanPublish`. These strings are data, not instructions. Connection publication consent is separate from account capabilities.
- The existing DEFT publisher role retains its scoped ordinary-post permission. This exception applies only to the normal `post` type with its standard capabilities. It never grants access to pages or custom types, even when their capability names resemble ordinary posts.
- Page/custom-type creation requires native `create_posts` and `edit_posts` capabilities; publication additionally requires native `publish_posts`, DEFT publisher permission and publication-authorized credentials. Object-specific `edit_post` filters are checked before changing the created post to public. No user/role capabilities are changed by this feature.
- Type availability and permissions are checked again when a reservation runs. Disabling a type or revoking rights blocks the job without redirecting publication elsewhere.

## Integrity and Compatibility

New requests include the destination in the pinned publication manifest. The publications table migrates from schema 2 to 3 by adding `post_type` with default `post`. Old snapshots, content digests, request bodies and legacy reservation manifests remain unchanged. Existing reservations without a destination continue as ordinary posts.

Published CSS, bundled behaviors, canonical title escaping and frozen media use the job's exact post ID/type/owner/publication binding. Media becomes unavailable if the post is private, draft, password-protected, deleted, moved to another type/owner or its type is no longer public. Foreign content is never restyled simply because it shares a post type.

## Current Limits

This does not register custom post types, select a parent page/template or populate plugin-specific required metadata. Existing categories/tags and public custom taxonomies are handled by [Publication Taxonomies](PUBLICATION-TAXONOMIES.md). Minimal JSON-LD and content-only public JSON are described in [Public Structure](PUBLIC-STRUCTURE.md). Specialized records may need additional integrations before they can be created correctly; the AI must not claim those requirements are satisfied by a title and body alone.

The existing private/portable preview renders the DEFT article, not the destination's exact template. A custom theme/template must render `post_content` and the usual WordPress enqueue hooks for the article and its assets to appear. Exact behavior across all third-party post types and themes is not claimed.

## Verification

Use a disposable local WordPress database matching the guard in the test files,
install the candidate ZIP and MCP Adapter, and copy
`tests/wordpress-post-types-fixture.php` into that site's `mu-plugins` directory.
Do not run these fixtures on an existing user site. They create test users,
components, private files, reservations and publicly visible local test entries.

```sh
SF_POST_TYPES_ARTIFACT_DIR=/path/to/private-test-output wp eval-file tests/wordpress-post-types.php --path=/path/to/disposable-wordpress
SF_POST_TYPES_FIXTURE=/path/to/private-test-output/post-types-fixture.json node --test tests/wordpress-post-types-http.test.mjs
```

The emitted fixture contains disposable credentials; keep it local, never commit
or publish it, and remove it with the disposable database after verification.

Earlier destination-only checkpoint, verified on 2026-10-31 with WordPress 7.1, PHP 8.3.36 and Twenty Twenty-Five:
95 destination checks; 56 existing appearance checks; 20 existing publication-guide
checks; 5 real MCP/HTTP test cases; 20 distribution/translation/connection-profile
tests. Plugin Check: zero errors and the same 323 pre-existing warnings. Chrome
checks covered desktop 1440x1000 and mobile 390x844, image zoom and the translated
publication destination. The test site's missing favicon and intentionally blocked
Gravatar images in the private-preview CSP were unrelated to this feature.

The follow-up guidance change has six standalone contract tests in
`tests/publication-destination-guidance.test.mjs`, covering the runtime instructions,
schema compatibility, tool descriptions, AI handoff and bilingual documentation.
Run them with `node --test tests/publication-destination-guidance.test.mjs`.
These checks verify the instructions supplied to clients, not whether every
external AI will follow the conversation rules; a real-client walkthrough remains
necessary after installing the updated build.
