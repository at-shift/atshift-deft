# Alpha.11: User-Directed AI Publication

Status: private GitHub code checkpoint. No tag, Release, production deployment or
WordPress.org update is included.

## Flow and Authority

The human requests immediate publication or a desired date/time. The AI asks only
for missing timing/time-zone details, reads the latest draft and publication status,
then sets it. There is no required second approval in WordPress.

Existing keys retain exactly four draft tools. A new `sf_publisher` role (or the
administrator) may explicitly opt into a new publishing key in AI connections.
The separate `/structured-fields-mcp/v1/publishing` endpoint exposes the four tools
plus `get-publication`, `set-publication`, `cancel-publication`. Keys cannot cross
between endpoints or authenticate generic WordPress REST requests. No existing
account is automatically promoted. Article ownership remains immutable.

This is delegated account authority, not independently verified human presence or
proof that a chat user approved. Prompt-injection resistance includes bounded
schemas and owner isolation; a compromised authorized AI can still misuse its
publication grant for that owner's drafts. Keep draft-only keys unless needed.

## Reservations

Requests carry exact article/publication versions, a unique request UUID, timing,
and an explicit offset timestamp with matching IANA/fixed-offset time zone.
Immediate mode requires empty timestamp/time-zone strings. Schedules must be
between one minute and one year ahead. Invalid dates and DST mismatches fail.
An identical request-ID retry returns the current outcome, not a second post.

The job records the immutable draft digest and review manifest (media hashes and
v3 behavior-policy state). At execution, all are revalidated under the article
lock, along with current owner privileges and connection revocation. Changes stop
publication. Cancellation increments the publication version, so old cron events
cannot publish replacements. One ordinary native `post` is linked transactionally
before publication; retries reuse that ID. No published-post update is implemented.

Key expiry blocks new calls, not already authorized reservations. Explicit key
revocation cancels pending work; an already-admitted in-flight publication may
finish. Role loss blocks execution. Published posts are not withdrawn by revocation.
Reservation persistence and worker admission share the connection-management lock.
Lost delegation is rejected, never converted to browser-session authorization.
Worker status writes retain their expected version so cancellation is not overwritten.
Replacement writes also compare the stored publication version, including cancellations
caused by revoking a different key. New jobs explicitly record session/connection
authorization. Pre-fix jobs without an issuer or an explicit session marker are
blocked when due and must be set again; their missing provenance cannot be recovered.
Existing jobs with a retained issuer still require that connection at execution.

WP-Cron processes due jobs, possibly late on low-traffic sites. Use server cron
when timing matters. Jobs wait in the plugin, not as native `future` posts; the
native post is created at execution. Deactivation stops processing, not a durable
cancel; cancel pending reservations first when needed.
The local fixture has `DISABLE_WP_CRON=true`: tests invoke due workers explicitly.
Automatic local timing requires a separately configured runner before manual tests.

## Public Content and Media

Only pinned v2/v3 media can publish; untracked images and CSS resources fail.
Reference-only assets never qualify. Publication reads and hash-checks bounded
bytes, freezes unique copies outside the webroot, then exposes them through a
GET/HEAD handler only when the linked native post is published and not password
protected. 50 bindings / 100 MiB per publication and 500 MiB publication-copy site
quota are serialized separately from the private source quota. Existing public
WordPress attachments are not made private by this plugin.

Files are NOT promoted to the media library yet. The plugin must remain active
for media/style/behavior delivery. Public files return no-store/nosniff and PDFs
download as attachments. This is not antivirus or document sanitization. Previously
downloaded public copies cannot be recalled by making the post private.

Rendered HTML strips comments and encodes literal shortcode openers so article
data cannot activate WordPress dynamic blocks or shortcodes. Themes and trusted
WordPress filters can still affect output. Behavior execution stays in the bundled
registry. Recheck actual public rendering on the target theme before real use.

Retention/deletion UI and cleanup of interrupted unpublished artifacts remain
future work; quotas fail closed. This alpha is for an isolated test site.

Titles remain unchanged in canonical snapshots and are encoded as literal text at
the WordPress boundary. Title output for associated legacy previews/public posts
is protected without rewriting immutable snapshot data. Normal unrelated WordPress
post titles retain their existing behavior.

Draft validation, previews and reviews also enforce 50 media bindings / 100 MiB
of unique media per article. A validation operation verifies each authorized
source/hash/type once, with no cache across operations. Frozen/delivered bytes
still receive exact-buffer integrity checks.
Repeated preview bindings to the same source/hash/type share one authenticated URL,
avoiding duplicate resource transfers without caching authorization or file integrity.

## Verification

Run `npm run test:wordpress-publications` against the isolated local site. Tests
exercise real MCP SDK transport, role/key isolation, cancellation, version checks,
idempotency, due-event execution, media delivery, shortcode boundaries and key
expiry/revocation. Test time advancement edits only disposable fixture jobs.
The suite removes its users, jobs, files and native posts afterward.
`npm run test:wordpress-security` adds deterministic title, duplicate-media and
revocation interleaving regressions. The publication HTTP suite also exercises
administrator-issued draft keys, legacy title output and interrupted publication.

Local validation on 2026-10-30: 381 regression checks passed, including 20 security
regressions. The extracted 36-file plugin ZIP passed 207 checks. Real Chrome at
1440px and 390px verified literal titles and one authenticated image request for
50 repeated bindings. PHP lint and the build passed; the WordPress error log did
not grow during the full regression run. These results are local, not production
or automatic wall-clock scheduling verification.
