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
| ChatGPT web (Chat / Work) | Remote MCP plugin + individual OAuth consent | Add-menu entry verified; DeFt OAuth and article workflow not yet verified |
| Claude OAuth | Individual OAuth consent | Pre-registered public client; check PKCE S256/no-secret support |
| Claude web / Desktop remote connector | Request headers where the limited beta is available | Desktop connection and sample draft save reported successful in one setup; preview/publication not yet verified; private single-owner use only |
| Claude Code | Streamable HTTP + environment-backed Bearer header | Configuration template available |
| Gemini Apps web / mobile | Custom Connected App + OAuth | Pre-registration; account/region eligibility and real flow need verification |
| Gemini CLI | Streamable HTTP + environment-backed Bearer header | Configuration template available; separate from Gemini Apps |
| Codex desktop app | Streamable HTTP + private Authorization header | Live context, private draft save/readback and portable text preview verified in one test; files/publication not verified |
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

In Claude Desktop, the Japanese UI checked on 2026-10-31 uses
**設定 > コネクタ > 追加**, opening **カスタムコネクタを追加**. That initial dialog
shows **名前**, **MCP サーバー URL** and **続ける**. Do not put the connection key
in either of those two fields. The next authentication screen was also checked:
it offers OAuth choices and, on eligible accounts, **リクエストヘッダー**
(Request headers).
For the web client, confirm the visible Connectors entry rather than assuming the
desktop menu path. Account policies and available authentication methods may differ.

For a private account connector with **Request headers** available:

1. Select **サインインなし** (No sign-in), not either OAuth sign-in option.
2. Select **ヘッダーを追加** (Add header). Use `Authorization` as the name and
   `Bearer <connection-key>` as the value, replacing the placeholder privately.
   Include one ASCII space after `Bearer` and mark the header required if offered.
3. Select **追加** (Add), enable the connector in the intended conversation, and
   request a live read-only context check before creating an article.

No sign-in means no OAuth flow here, not anonymous access to WordPress. No OAuth
client ID or secret is needed for this route. If the header section is absent,
do not put the key in OAuth fields or the URL; use the separate OAuth setup,
Claude Code or the JSON fallback. See the [official header instructions](https://claude.com/docs/connectors/custom/add-unlisted#authenticate-with-request-headers).

On 2026-10-31, the tester reported successful Desktop connection after resolving
an Xserver access restriction, followed by sample article creation and draft save.
The tester explicitly confirmed draft-only completion, not publication. This is
a user-reported result from one setup; independent context/readback checks,
preview, file transfer, publication and the separate OAuth route remain unverified.

Request headers is a limited beta. Team/Enterprise fixed credentials are shared;
they are unsuitable for this plugin's individual staff workspaces. Remote
connectors, including those configured in Claude Desktop, connect from Anthropic's
cloud. Local Desktop stdio servers/extensions are a different mechanism; this
version does not ship a local bridge or an MCPB extension.

## Cloud Connection Errors

A correct URL and a working local client do not prove that a cloud connector can
reach the site. Claude remote connectors originate from Anthropic's servers,
including when configured in the desktop app. See [Claude's network requirements](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp).

1. Confirm the exact endpoint and the client's authentication route. For a key,
   check the header name and `Bearer ` prefix privately; never request the key in
   chat or screenshots. A generic connection error alone does not identify its cause.
2. Compare the failing time with hosting/WAF logs. DeFt returns `401` for missing
   or invalid credentials; an unauthenticated browser visit returning `401` is
   expected. A `403` can come from hosting, a security plugin or application access
   rules. A missing WAF log entry does not prove the request reached WordPress.
3. On Xserver, inspect **WordPressセキュリティ設定 > 対象ドメイン > 国外アクセス制限 >
   REST APIアクセス制限**. This protection can block cloud access to `/wp-json`.
   Settings can affect the domain and other sites under it, not only DeFt.
4. Ask the site administrator to review the narrowest supported exception, such as
   an allowlist using the provider's current official outbound IP ranges. Confirm
   the actual exception scope and obtain approval before changes. Do not turn off
   all overseas restrictions, WAF, login protection or DeFt authentication merely
   to connect. Do not regenerate credentials automatically or change endpoint
   routing just to bypass a restriction.
5. After an approved correction, reconnect and call read-only context from the
   actual AI conversation. Only then offer the optional private sample; connection
   success does not authorize publication.

Host-specific settings and allowlist formats can change. Consult the current
[Xserver security manual](https://www.xserver.ne.jp/manual/man_server_wpsecurity.php)
and [Anthropic outbound IP documentation](https://platform.claude.com/docs/en/api/ip-addresses),
not a copied static IP list. DeFt does not change these server settings itself.

## Codex Desktop App

Select **Codex (desktop app)** in DeFt's AI connections. This is not the CLI/IDE
configuration-template route. The Japanese desktop UI was checked on 2026-10-31:

1. Open **設定 > プラグイン > 追加 > MCPサーバーを追加** (Settings > Plugins > Add > Add MCP server).
2. Give the server a recognizable name. In **タイプ**, choose **ストリーミング可能なHTTP** (Streamable HTTP), not STDIO. Paste the complete DeFt endpoint into **URL**, preserving any query string.
3. For this direct-header route, leave **Bearer トークン環境変数** empty. In **ヘッダー**, put `Authorization` on the left and `Bearer <connection-key>` on the right, replacing the placeholder privately with the issued key. Use one ASCII space after `Bearer`. `Bearer` is not the header name; `Authorization` is not an environment-variable value for this setup.
4. Save. To change an existing server, open the **MCP** tab under Plugins and select the gear beside the server name you chose. Keep its toggle enabled. Do not recreate a working connection just to edit it.
5. Return to the same conversation and request a read-only context check. If tools are not loaded, use Restart if shown, or restart the app and resume. Verify the intended endpoint through the actual conversation tool, not only a CLI/SDK check or enabled toggle.

Keep the key only in the owner's private client settings, never in a screenshot,
chat, shared configuration or source control. The environment-variable field is an
alternative credential route for users who deliberately configure an environment
variable; do not combine it with these direct-header instructions.

Menu names can change. If the user cannot find an item, confirm their visible
screen and client before continuing; do not insist on a generic Settings > MCP
path. Do not assume these local settings also connect ChatGPT on the web.

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

### ChatGPT Web Entry

The Japanese web UI entry was checked on 2026-10-31:
**設定 > プラグイン > エージェント一覧を見る > 追加 > カスタムMCPサーバーを追加**.
The settings page manages installed plugins; its **エージェント一覧を見る** button
opens the directory containing **追加**. The directory is also available at
[ChatGPT Plugins](https://chatgpt.com/plugins). English labels are described in the
[official custom MCP guide](https://developers.openai.com/api/docs/guides/custom-mcp-server).

Official plugin support includes both Chat and Work, subject to account/workspace
availability. For DeFt, use the OAuth route below, not the desktop's fixed-key
header instructions and not No authentication. Install the created plugin and
select it in the intended conversation, then verify a live context response there.
Finding the creation menu does not verify DeFt OAuth, tool use, file transfer or
preview rendering. Those web flows still require real-account testing.

### OAuth Setup

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
- ChatGPT plugin availability: https://learn.chatgpt.com/docs/plugins
- ChatGPT custom MCP setup: https://developers.openai.com/api/docs/guides/custom-mcp-server

Recheck these sources before deploying provider-specific settings.
