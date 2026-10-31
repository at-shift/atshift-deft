# Alpha.12: Per-User Quotas and Shared Transport Policy

Private GitHub code checkpoint. No tag, Release, production deployment or
WordPress.org update. This change addresses supplied findings 3 and 4 only.
CSS isolation, publication approval and scheduled-key-expiry policies are unchanged.

## Shared Catalog

Each creator can allocate 20 new shared component IDs by default. This includes
bounded structures, raw designs and explicit copies shared from personal recipes.
All writes use the existing global catalog lock. Equivalent shared structures are
reused before quota enforcement. Revisions, unchanged retries and retirement do
not allocate a new ID. Disabled IDs count; the 100-ID site cap still applies.
The personal 20-ID quota is unchanged. Catalog visibility stays shared, not personal.

Catalog schema 4 adds nullable `creator_id`. New records pin the authenticated
creator; later editors cannot change it. Actor zero is reserved for shipped seeds.
Legacy records remain NULL and are attributed using earliest revision writers.
Timestamp ties count the ID once against each implicated earliest writer. Missing
history conservatively counts the ID against each creator; it is not exempt zero.
No payload, historical revision, article ownership or existing rendering is rewritten.
Ambiguous histories can therefore limit new creation earlier than exact attribution.
The schema gate verifies the column and quota query failure fails closed.

## Private Staging

All staged files count toward 100 MiB per uploader across articles and purposes.
Bearer transfers use the grant issuer's ID, not the current browser user. The
check runs under the existing asset lock before retaining a new file. Verified
identical retries return the original asset without new usage. Another article or
purpose means another retained copy and consumes capacity. Per-file, article and
site caps remain in force. No automatic deletion or retention UI is introduced.

This is staged-asset uploader accounting, not an ownership rewrite or a quota for
ordinary WordPress media-library attachments. Frozen publication copies retain
their existing separate quota. Existing over-quota data remains readable; new
allocations are rejected. Site limits can still fill through aggregate team use.

## Operator Configuration

Trusted site code can adjust an individual limit. The client cannot send overrides.
The filters receive the default integer and effective creator/uploader user ID:

```php
add_filter( 'sf_shared_component_limit_per_user', function ( $limit, $user_id ) {
    return 42 === $user_id ? 30 : $limit;
}, 10, 2 );
add_filter( 'sf_private_media_limit_per_user', function ( $limit, $user_id ) {
    return 42 === $user_id ? 150 * 1024 * 1024 : $limit;
}, 10, 2 );
```

Valid nonnegative integers are capped at the corresponding site limit. Zero
blocks new allocation; malformed values use finite defaults. Administrators and
designers also receive the default user quotas unless trusted site code overrides
them. Raising a limit can reduce capacity reserved for others. Do not raise limits
automatically on a user's or AI's request. No settings page is added in this alpha.
AI context exposes configured limits; errors identify own usage and capacity only.

## Transport

`Access::secure_transport()` is shared by Connections and Transfer; Review inherits
it through Transfer and publication uses Connections. HTTPS stays allowed.
The HTTP exception now requires all three: WordPress environment type `local`,
HTTP home host `127.0.0.1`/`localhost`/`[::1]`, and loopback peer `127.0.0.1`/`::1`.
Forwarded headers do not enable the exception. Configure trusted HTTPS termination
in WordPress normally. Production/staging/development loopback HTTP is rejected.
The bundled Node transfer client remains narrower: HTTP only at 127.0.0.1.
Do not change a production site to `local` to bypass HTTPS.

## Verification

`npm run test:wordpress-quotas` checks creator quotas, reuse, revision attribution,
legacy ambiguity, an alpha.11-shaped table upgrade and blocked-ALTER recovery,
concurrent raw registration and real multipart direct/delegated
uploads. Capacity fixtures seed only isolated accounting metadata instead of
writing 100 MiB of test bytes; each actual upload and retry uses real file bytes.
`npm run test:wordpress-transport` tests both wrappers with a transport matrix.
Run the existing catalog, structure, asset, workflow, MCP and publication suites
as regressions. Tests use the isolated local site and remove disposable records.
