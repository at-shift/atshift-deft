# Alpha.14: Individual OAuth Connections

Private GitHub code checkpoint only. No tag, release, public tunnel, provider-account
setup, WordPress.org publication or production deployment is included.

## Runtime

The complete plugin now requires PHP 8.2. `runtime.php` uses older-compatible
syntax and is loaded before implementation classes or dependencies. Unsupported
PHP returns early, registers admin/network-admin warnings and leaves data intact.
The warning includes required and actual versions in Japanese or English. Existing
installations are protected as well as new installations checked by WordPress's
`Requires PHP` header. Upgrade PHP before updating: stopped plugins cannot run jobs.

The local engine is PHP 8.3.36. Version-boundary tests inject older version IDs;
they are not proof of running the full bootstrap under an actual PHP 8.1 engine.

## Authorization Contract

- Administrator pre-registration only: exact callback, public client, no secret.
- Authorization code, mandatory S256 PKCE, exact resource including query route.
- Logged-in WordPress session, per-flow nonce, single-use session-bound consent.
- `sf:drafts` always includes read/write. `sf:authoring` is optional.
  `sf:publish` requires the publishing endpoint and current publisher authority.
- Code lifetime five minutes; access ten minutes; grant/rotating refresh up to
  30 days. Absolute grant expiry caps every token. No scope growth on refresh.
- Refresh-token reuse revokes the stable grant and pending reservations.
- Typed OAuth connection rows have no usable fixed key. Existing `sfc_` keys
  retain their previous authority. `sfo_` tokens are accepted only at the two MCP
  resources, not general WordPress REST APIs, approval pages or media routes.
- Explicit connection/application revocation cancels pending reservations;
  token or grant expiry alone preserves previously authorized reservations.
- Transactions and per-owner locks serialize code consumption/refresh. Credential
  storage contains hashes/IDs; encrypted signing material depends on WordPress salts.

MCP uses the official WordPress adapter. OAuth uses League OAuth2 Server 9.4.1,
Nyholm PSR-7 1.8.2 and namespaced runtime dependencies, not custom crypto.

## Build

Run `npm run build:php-deps`, then `npm run pack:wordpress`. Source and scoped
vendor directories are ignored; the ZIP includes runtime dependencies, licenses
and a hash manifest. Packaging refuses a stale composer lock/scoper config or
modified dependency bytes. PHP-Scoper is build-only, not shipped.

## Operational Limits

Provider UI/account capabilities need real HTTPS-site verification. Protocol tests
do not establish ChatGPT/Claude/Gemini account support, attachment access or preview
rendering. Public clients with PKCE S256 are the supported registration mode; no
client_secret flow, automatic DCR or remote CIMD fetch exists in this alpha.

The canonical authorization-server metadata URL must reach WordPress. Root server
rewrites may need configuration on subdirectory/plain-permalink installations.
The settings screen checks same-origin metadata and does not change server rules.
Changing site URLs or authentication salts requires controlled reconnection/key
recovery, not silent replacement of existing keys.

## Local Validation

- 22 regression scripts, 511 checks passed, including 37 OAuth HTTP/SDK tests and
  16 PHP-runtime guard assertions. Final full run added no PHP debug-log output.
- 50 first-party PHP/test/router files passed lint on PHP 8.3.36.
- Real authorization-code exchange, signed wrong-claim JWT rejection, scope
  narrowing, refresh reuse, owner separation and four revocation paths passed.
- A deterministic database barrier cancelled a reservation at processing admission;
  no post was published. This is not a multi-process stress test.
- Expired OAuth grants still execute previously authorized reservations, as specified.
- Plugin Check 2.1.0, all 34 checks against the installed distribution ZIP:
  0 errors, 322 warnings. The added warnings concern prepared direct queries and
  uncached grant checks; revocation must not rely on stale cached authorization.
  Existing warning categories remain documented by the earlier warning review.
- Composer audit reported no advisories or abandoned packages. The package retains
  runtime licenses, locked source manifests and per-file dependency hashes.
- Playwright/installed Chrome (Browser plugin unavailable): 1440x1100 and 390x844,
  application registration -> discovery -> consent -> exact-state redirect passed.
  Meaningful page content, one consent form, no relevant page errors, no overflow.
- Actual provider accounts, minimum-version engines, multisite and real subdirectory
  web-server rewrites remain unverified. No production proof is implied.

Local PHP development command (not for production):

```sh
php -S 127.0.0.1:8128 -t .local/wordpress scripts/wordpress-local-router.php
```

The router only forwards the two local OAuth metadata paths. All other requests
retain PHP's normal local-server behavior, including nonexistent asset 404s.
