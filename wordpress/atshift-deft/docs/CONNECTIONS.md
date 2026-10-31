# AI Client Connection Routes

Checked against official documentation on 2026-10-31. Client availability and UI
can change. These are configuration routes, not a claim that every provider has
passed a real end-to-end conversation test. Choose the exact client in WordPress
**AI connections** before creating credentials.

The user starts by giving the downloaded guide to their usual AI. The AI then
guides the applicable connection route, verifies a live read-only context response,
and offers the included optional sample before continuing the user's article.
Do not require the user to understand every route or prepare a separate sample file.

| Client | Route | Current implementation |
| --- | --- | --- |
| ChatGPT | Remote MCP + individual OAuth consent | Pre-registered public client; provider account flow not yet verified |
| Claude OAuth | Individual OAuth consent | Pre-registered public client; check PKCE S256/no-secret support |
| Claude web / Desktop remote connector | Request headers where the limited beta is available | Conditional, private single-owner use only; public HTTPS needed |
| Claude Code | Streamable HTTP + environment-backed Bearer header | Configuration template available |
| Gemini Apps web / mobile | Custom Connected App + OAuth | Pre-registration; account/region eligibility and real flow need verification |
| Gemini CLI | Streamable HTTP + environment-backed Bearer header | Configuration template available; separate from Gemini Apps |
| Codex local CLI / IDE | Streamable HTTP + bearer_token_env_var | Configuration template available; separate from ChatGPT web |
| Other MCP clients / custom API hosts | Streamable HTTP + securely stored Bearer credential | Check the host's authentication and network capabilities |
| AI without suitable MCP support | Guide -> JSON proposal -> WordPress import | Manual fallback; no automatic save/publication |

## Per-Person Credentials

One connection belongs to one WordPress owner. Never put one person's key in a
shared organization connector. That would give every user of the connector the
same owner's access, regardless of which AI account sends a request. Separate
connection names alone do not solve this. Use isolated personal credentials, or
individual OAuth authorization. Provider identity is not inferred from
the selected label or a caller-supplied user ID.

Authoring and publication are separate opt-in permissions. Existing connections
are not upgraded when the selected client changes. Revocation and key expiry
still apply. A provider connection never implies permission to publish an article.
Do not disable authentication or place credentials in an endpoint URL, Markdown,
source control, a shared JSON export, chat or a shell command history.

## Claude

In a private account connector, open Customize > Connectors > Add custom
connector. If **Request headers** is present, enter the endpoint, choose the
fixed-credential **No sign-in** route, and add a required `Authorization` header.
Its value is `Bearer ` followed by the connection key, including the space.
This label does not mean the WordPress endpoint is anonymous. If the header
section is absent, do not use the key as an OAuth client ID/secret or put it in
the URL. Use the separate OAuth setup, Claude Code or the JSON fallback.

Request headers is a limited beta. Team/Enterprise fixed credentials are shared;
they are unsuitable for this plugin's individual staff workspaces. Remote
connectors, including those configured in Claude Desktop, connect from Anthropic's
cloud. Local Desktop stdio servers/extensions are a different mechanism; this
version does not ship a local bridge or an MCPB extension.

## CLI Clients

Select Claude Code, Gemini CLI or Codex in AI connections. Download the generated
configuration template and merge it into the respective settings file. The exact
endpoint is included, including WordPress `rest_route` query routing when needed.
Templates reference a site/endpoint-specific environment variable, not the key.
Provide that variable privately to the process launching the client. Use separate
environments for separate staff accounts. Never overwrite unrelated client settings.
The templates retain tool approval prompts; they do not enable blanket trust.

Check `/mcp` in Claude Code/Gemini CLI, or `codex mcp list` for local Codex. A key
can be tested from a local client against a loopback site. A cloud process cannot
reach that address simply because the settings were made on the same computer.

## ChatGPT And Gemini Apps

Alpha.14 implements a common OAuth path using League OAuth2 Server 9.4.1, privately
namespaced with its dependencies. Site administrators pre-register a public client
using the exact callback URL from the AI service. Select OAuth, enter the client ID
and MCP endpoint, leave client secret empty, then follow WordPress sign-in and
individual consent. PKCE S256 and the exact resource URI are mandatory. Client
registration is shared; grants are per WordPress owner and never shared credentials.

Authorization codes last five minutes and are one-use; access tokens last ten
minutes. Refresh tokens rotate within a 30-day grant and cannot increase scopes.
Refresh reuse revokes the grant and pending reservations. Existing keys still work.
Scopes are `sf:drafts` (read/write), optional `sf:authoring`, and `sf:publish` only
with the separate publishing endpoint and current publisher authority. Explicit
revocation cancels reservations; token/grant expiry alone does not cancel jobs
already authorized. Disabling an application affects all its staff grants.

No dynamic registration or remote client-metadata fetching is offered in this
version. A client requiring client_secret authentication is not supported. This is
protocol support, not proof of successful provider-account integration. Verify the
actual account with an authorized public HTTPS test site before claiming support.

Discovery uses `/.well-known/oauth-authorization-server` plus the issuer path.
Subdirectory installs or plain server routing may need a server-side route to
WordPress for that URL. The settings screen checks same-origin discovery. No
server rewrites or public tunnels are created automatically. Keep the site URL and
WordPress authentication salts stable: changing them invalidates OAuth identities
or encrypted keys; do not silently regenerate keys over existing consent.

Google's current custom-app requirements include US location, English availability,
age 18+, a personal Google account and Keep Activity enabled. Work/school accounts
are currently excluded. Check the official page again before planning rollout in
Japan or a company workspace. Gemini CLI does not imply Gemini Apps eligibility.

## Files, Previews And Connection Checks

For initial user setup, verify a successful live context response from this AI and
the intended endpoint. No draft, upload or publication is needed for this read-only
check. Then offer the optional fictional draft/preview sample once, or continue the
user's article. These are separate from developer acceptance testing below.

For developer acceptance testing, an authenticated request proves only that the
endpoint accepted a credential. Test owned draft save/resume, rejection of another
owner's draft, an intentionally selected file transfer, preview and revocation
with disposable fixtures. Record the actual client and version. SDK tests do not prove
that a provider chat can access attachment bytes or display portable HTML.

No generic remote-URL file fetcher exists. Use exact-byte authorized transfers only
where the client can securely send bytes; otherwise keep materials in chat and
explain the limitation. Never make a private preview public to bypass a client
display limitation. API-backed hosts require their own implementation and may
have separate billing; they are not an invisible fallback for a chat subscription.

No tunnel, public deployment, provider account configuration or cloud storage is
created by choosing a client. Obtain explicit permission before exposing a local
site or installing an external connection service.

## Official References

- ChatGPT authentication: https://developers.openai.com/plugins/build/auth
- Claude connectors and header availability: https://claude.com/docs/connectors/custom/add-unlisted
- Claude network and shared credentials: https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp
- Claude Code configuration: https://code.claude.com/docs/en/mcp
- Gemini Apps availability: https://support.google.com/gemini/answer/17209137?hl=en
- Gemini authentication: https://developers.google.com/connected-apps/mcp/dev-mode/usage
- Gemini CLI transport: https://geminicli.com/docs/tools/mcp-server/
- Gemini CLI variable expansion: https://geminicli.com/docs/reference/configuration/
- Codex MCP settings: https://developers.openai.com/codex/mcp

Recheck these sources before deploying provider-specific settings.
