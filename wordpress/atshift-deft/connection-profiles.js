(() => {
  "use strict";
  const { __ } = window.wp.i18n;
  const profiles = [
    { id: "chatgpt", label: __("ChatGPT (web: Chat / Work)", "atshift-deft"), auth: "oauth", cloud: true,
      note: __("Use OAuth with a pre-registered client ID and PKCE S256. A fixed connection key is not used. Actual availability depends on your ChatGPT account and its custom MCP settings.", "atshift-deft"),
      steps: [__("Open Settings > Plugins > View agents, then Add > Add custom MCP server. The installed-plugin settings list is not the creation screen. Labels may vary by version.", "atshift-deft"), __("Ask the site administrator to register the callback URL from your AI's OAuth settings.", "atshift-deft"), __("Enter the MCP endpoint and client ID in the AI's custom MCP connection. Sign in to WordPress and approve only the access you need.", "atshift-deft"), __("Install the created plugin, select it in the intended Chat or Work conversation, and ask the AI to confirm live context. A visible add menu does not prove that DEFT OAuth works with this account.", "atshift-deft")],
      source: "https://developers.openai.com/plugins/build/auth" },
    { id: "claude-oauth", label: __("Claude (OAuth)", "atshift-deft"), auth: "oauth", cloud: true, provider: "claude",
      note: __("Use a pre-registered OAuth client and your own WordPress login. Confirm that your connector accepts a public client with PKCE S256 and no client secret.", "atshift-deft"),
      steps: [__("In Claude Desktop, open Settings > Connectors > Add to reach Add custom connector. The first screen asks for a name and MCP server URL; check the following authentication screen before choosing a route.", "atshift-deft"), __("Register the callback URL shown by Claude's custom connector settings.", "atshift-deft"), __("Enter the endpoint and client ID, then sign in to WordPress and review access.", "atshift-deft")],
      source: "https://claude.com/docs/connectors/custom/add-unlisted" },
    { id: "claude", label: __("Claude (private header, limited beta)", "atshift-deft"), auth: "bearer", cloud: true, confirm: true,
      note: __("Conditional: Request headers is a limited beta. Use only a connector private to your own account. A Team/Enterprise shared key acts as one WordPress owner for everyone; do not use that route for separate staff accounts.", "atshift-deft"),
      steps: [__("In Claude Desktop, open Settings > Connectors > Add. On the web, use the visible Connectors entry. Confirm Request headers is actually available before creating a key; the name and URL form alone does not prove header support.", "atshift-deft"), __("Enter the endpoint below. Select No sign-in for this fixed-key route, then add a required Authorization header with the full value Bearer <connection-key>. WordPress authentication remains required.", "atshift-deft"), __("Enable the connector in the conversation. Desktop remote connectors also run from Anthropic's cloud, not your computer.", "atshift-deft")],
      source: "https://claude.com/docs/connectors/custom/add-unlisted" },
    { id: "claude-code", label: "Claude Code", auth: "bearer", cloud: false, format: "claude",
      note: __("Direct HTTP route. The template references an environment variable, never your actual key. This is not the Claude Desktop local-server configuration.", "atshift-deft"),
      steps: [__("Merge the template's mcpServers entry into .mcp.json without replacing other servers.", "atshift-deft"), __("Set the named environment variable securely in the process that launches Claude Code. Do not put the key in chat, source control or shell history.", "atshift-deft"), __("Restart the session and check /mcp. Keep tool confirmations enabled.", "atshift-deft")],
      source: "https://code.claude.com/docs/en/mcp" },
    { id: "gemini", label: __("Gemini Apps (web / mobile)", "atshift-deft"), auth: "oauth", cloud: true,
      note: __("OAuth route for eligible accounts. Google's documented custom-app rollout is US-only, English, age 18+, personal accounts with Keep Activity on; check current availability. Gemini CLI is separate.", "atshift-deft"),
      steps: [__("Check eligibility and support for a pre-registered public OAuth client using PKCE S256.", "atshift-deft"), __("Register the exact callback URL, then enter the endpoint and client ID in the custom app settings and authorize your WordPress account.", "atshift-deft")],
      source: "https://support.google.com/gemini/answer/17209137?hl=en" },
    { id: "gemini-cli", label: "Gemini CLI", auth: "bearer", cloud: false, format: "gemini",
      note: __("Direct Streamable HTTP route via Gemini CLI, not the Gemini chat website. Templates keep tool confirmation on.", "atshift-deft"),
      steps: [__("Merge the template's mcpServers entry into your private ~/.gemini/settings.json without replacing other settings.", "atshift-deft"), __("Set the named environment variable securely before launching Gemini CLI. The settings loader expands it in Authorization; never substitute a literal key into a shared file.", "atshift-deft"), __("Restart Gemini CLI and check /mcp. Do not enable trust or automatic approval just to connect.", "atshift-deft")],
      source: "https://geminicli.com/docs/tools/mcp-server/" },
    { id: "codex-desktop", label: __("Codex (desktop app)", "atshift-deft"), auth: "bearer", cloud: false,
      note: __("Local desktop MCP setup, separate from ChatGPT web OAuth. The steps match the desktop screens checked on 2026-10-31; if labels differ, confirm the visible screen instead of guessing.", "atshift-deft"),
      steps: [
        __("Open Settings > Plugins > Add > Add MCP server.", "atshift-deft"),
        __("Enter a server name, select Streamable HTTP (not STDIO), and paste the full MCP endpoint into URL.", "atshift-deft"),
        __("For this header-based route, leave Bearer token environment variable empty. Under Headers, set the name to Authorization and the value to Bearer <connection-key>, with one space after Bearer. Never put the key in chat or screenshots.", "atshift-deft"),
        __("Save. To edit later, open the MCP tab in Plugins and select the gear beside the server name you chose. Keep that server enabled.", "atshift-deft"),
        __("Return to the same Codex conversation and ask for a read-only context check. If the tools are still missing, use Restart when offered or restart the app and resume. A saved setting alone is not a connection check.", "atshift-deft"),
      ], source: "https://learn.chatgpt.com/docs/extend/mcp" },
    { id: "codex", label: __("Codex (local CLI / IDE)", "atshift-deft"), auth: "bearer", cloud: false, format: "codex",
      note: __("Direct HTTP route using bearer_token_env_var. This does not enable the ChatGPT web connector or prove hosted Codex can reach this site.", "atshift-deft"),
      steps: [__("Merge the template into ~/.codex/config.toml, retaining existing servers and policies.", "atshift-deft"), __("Make the named environment variable available to the local Codex process through your secure launch environment.", "atshift-deft"), __("Start a new session and check codex mcp list. Confirm context before editing an article.", "atshift-deft")],
      source: "https://developers.openai.com/codex/mcp" },
    { id: "http", label: __("Other AI / custom MCP client", "atshift-deft"), auth: "bearer", cloud: false, confirm: true,
      note: __("Requires Streamable HTTP and a secure custom Authorization header. OAuth-only or chat-only clients cannot use this key. A cloud client also needs a reachable HTTPS site.", "atshift-deft"),
      steps: [__("Select HTTP / Streamable HTTP in the client's MCP settings, not legacy SSE or stdio.", "atshift-deft"), __("Enter the endpoint and store Authorization: Bearer <connection-key> in its secure settings. Do not put the key in a URL.", "atshift-deft"), __("Keep the credential private to one WordPress owner and confirm an authenticated request below. Client file transfer and preview display need separate verification.", "atshift-deft")],
      source: "https://modelcontextprotocol.io/specification/latest/basic/transports" },
    { id: "oauth", label: __("Other OAuth MCP client", "atshift-deft"), auth: "oauth", cloud: false, provider: "other",
      note: __("Pre-registered public OAuth client, authorization code with PKCE S256, exact resource indicator and rotating refresh tokens. No client secret or dynamic registration.", "atshift-deft"),
      steps: [__("Register this client's exact callback URL with the site administrator.", "atshift-deft"), __("Configure the client ID and endpoint, then authorize your own WordPress account.", "atshift-deft")], source: "https://modelcontextprotocol.io/specification/latest/basic/authorization" },
    { id: "json", label: __("AI without MCP (JSON proposal)", "atshift-deft"), auth: "none", cloud: false,
      note: __("No connection key needed. Give the AI the guide, receive an article JSON proposal, then import and save it in WordPress. This fallback does not automatically save or publish.", "atshift-deft"),
      steps: [__("Download the AI guide and ask the AI to read it before preparing an article.", "atshift-deft"), __("Import its article JSON with Import snapshot, then save and review the private preview. Use a fresh article handoff for later edits.", "atshift-deft")], source: null },
  ];

  function endpointInfo(value) {
    try {
      const url = new URL(value);
      if (!/^https?:$/.test(url.protocol) || url.username || url.password || url.hash || /[${}\r\n]/.test(value)) return null;
      if ([...url.searchParams.keys()].some(key => /token|secret|key|authorization/i.test(key))) return null;
      const host = url.hostname.replace(/^\[|\]$/g, "");
      const octets = host.split(".").map(Number);
      const ipv4 = octets.length === 4 && octets.every(n => Number.isInteger(n) && n >= 0 && n <= 255);
      const privateV4 = ipv4 && (octets[0] === 0 || octets[0] === 10 || octets[0] === 127 || (octets[0] === 169 && octets[1] === 254) || (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) || (octets[0] === 192 && octets[1] === 168));
      const local = privateV4 || (host.includes(":") && /^(::|f[cd]|fe[89ab])/i.test(host)) || /(^localhost$|\.(localhost|local|test|invalid)$)/.test(host) || (!host.includes(".") && !host.includes(":"));
      return { url: url.href, local, https: url.protocol === "https:" };
    } catch { return null; }
  }

  function route(id, endpoint, confirmed = false) {
    const profile = profiles.find(item => item.id === id);
    if (!profile) return { allowed: false, reason: __("Choose your AI and client first.", "atshift-deft") };
    if (profile.auth !== "bearer") return { allowed: false, reason: profile.note };
    const info = endpointInfo(endpoint);
    if (!info) return { allowed: false, reason: __("A valid credential-free HTTP(S) MCP endpoint is required.", "atshift-deft") };
    if (!info.https && !info.local) return { allowed: false, reason: __("HTTPS is required outside local development.", "atshift-deft") };
    if (profile.cloud && (info.local || !info.https)) return { allowed: false, reason: __("This cloud connector cannot reach a local/private address. Use an approved public HTTPS test site; no tunnel is opened automatically.", "atshift-deft") };
    if (profile.confirm && !confirmed) return { allowed: false, reason: __("Confirm that this client supports private, per-owner header credentials.", "atshift-deft") };
    return { allowed: true, reason: __("Client settings available. A successful request confirms authentication, not the complete article/file/preview workflow.", "atshift-deft") };
  }

  function template(id, endpoint, publishing = false) {
    const profile = profiles.find(item => item.id === id);
    const info = endpointInfo(endpoint);
    if (!profile?.format || !info || !route(id, endpoint, true).allowed) return null;
    // Endpoint-derived labels prevent accidentally reusing one site's credential for another.
    let hash = 2166136261;
    for (const char of info.url) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619) >>> 0;
    const suffix = hash.toString(16).padStart(8, "0");
    const name = `structured_fields_${publishing ? "publishing" : "drafts"}_${suffix}`;
    const env = `SF_${publishing ? "PUBLISHING" : "DRAFT"}_KEY_${suffix.toUpperCase()}`;
    if (profile.format === "codex") return { env, filename: `${name}.toml`, type: "text/plain", text: `[mcp_servers.${name}]\nurl = ${JSON.stringify(info.url)}\nbearer_token_env_var = "${env}"\ndefault_tools_approval_mode = "prompt"\n` };
    const server = profile.format === "gemini" ? { httpUrl: info.url, headers: { Authorization: `Bearer \${${env}}` }, trust: false }
      : { type: "http", url: info.url, headers: { Authorization: `Bearer \${${env}}` } };
    return { env, filename: `${name}.${id}.json`, type: "application/json", text: JSON.stringify({ mcpServers: { [name]: server } }, null, 2) + "\n" };
  }

  window.sfConnectionProfiles = Object.freeze({ profiles: Object.freeze(profiles.map(Object.freeze)), route, template, endpointInfo, checked: "2026-10-31" });
})();
