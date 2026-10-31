(() => {
  "use strict";
  const { __, sprintf } = window.wp.i18n;
  const $ = (id) => document.getElementById(`sf-${id}`);
  const config = window.sfWordPress;
  const profiles = window.sfConnectionProfiles;
  const status = (text) => { $("connection-status").textContent = text; };
  let busy = false;
  let endpoints = {};
  let pendingId = null;
  let serverReady = false;
  let oauth = { items: [] };
  let oauthDiscovery = false;
  function endpoint() { $("endpoint").value = ($("connection-publishing")?.checked ? endpoints.publishing : endpoints.drafts) || ""; renderClient(); }
  function renderClient() {
    const id = $("connection-client").value;
    const profile = profiles.profiles.find(item => item.id === id);
    const route = profiles.route(id, $("endpoint").value, $("client-confirm").checked);
    $("client-note").textContent = profile?.note || "";
    $("client-help").hidden = !profile;
    $("client-readiness").textContent = route.reason;
    $("client-readiness").hidden = !!profile && route.reason === profile.note;
    $("key-setup").hidden = profile?.auth !== "bearer";
    $("oauth-setup").hidden = profile?.auth !== "oauth";
    $("client-confirm-row").hidden = !profile?.confirm;
    $("connection-create").disabled = !serverReady || !route.allowed || !$("connection-secret").hidden;
    $("client-steps").replaceChildren();
    for (const step of profile?.steps || []) { const li = document.createElement("li"); li.textContent = step; $("client-steps").append(li); }
    $("client-source").hidden = !profile?.source;
    if (profile?.source) $("client-source").href = profile.source; else $("client-source").removeAttribute("href");
    const template = profiles.template(id, $("endpoint").value, !!$("connection-publishing")?.checked);
    $("client-template-section").hidden = !template;
    $("client-template").value = template?.text || "";
    $("client-environment").textContent = template ? /* translators: 1: template.env. */ sprintf(__("Store this connection's key in %1$s for this client process. Keep other staff accounts in separate environments. Merge the template; do not replace existing settings.", "atshift-deft"), template.env) : "";
    renderOAuth();
  }
  function renderOAuth() {
    const profile = profiles.profiles.find(item => item.id === $("connection-client").value);
    const old = $("oauth-client").value;
    const provider = profile?.provider || profile?.id;
    const clients = oauth.items.filter(item => item.provider === provider || provider === "other");
    $("oauth-client").replaceChildren();
    for (const item of clients) { const option = document.createElement("option"); option.value = item.id; option.textContent = item.name; $("oauth-client").append(option); }
    if (clients.some(item => item.id === old)) $("oauth-client").value = old;
    const item = clients.find(item => item.id === $("oauth-client").value);
    $("oauth-publishing").disabled = !oauth.canPublish || !Number(item?.publishing);
    if ($("oauth-publishing").disabled) $("oauth-publishing").checked = false;
    const endpoint = ($("oauth-publishing").checked ? oauth.publishingEndpoint : oauth.draftEndpoint) || "";
    $("oauth-endpoint").value = endpoint;
    $("oauth-client-id").value = item?.id || "";
    const info = profiles.endpointInfo(endpoint);
    $("oauth-status").textContent = !oauth.available ? __("OAuth dependencies are unavailable. Install the complete plugin ZIP and enable OpenSSL, Sodium and mbstring.", "atshift-deft")
      : !oauth.secure ? __("HTTPS is required.", "atshift-deft")
      : !oauth.configured ? __("An administrator must register an application first.", "atshift-deft")
      : !item ? __("No application is registered for this service. Ask the site administrator to add its exact callback URL.", "atshift-deft")
      : !oauthDiscovery ? __("OAuth discovery could not be verified. The server must route the metadata URL below to WordPress before a client can connect.", "atshift-deft")
      : profile?.cloud && (!info || info.local || !info.https) ? __("This cloud service cannot reach the local site. Use an approved public HTTPS site for a real account test.", "atshift-deft")
      : __("OAuth is configured. Complete sign-in from your AI's connection settings. A provider account connection has not been verified by this screen.", "atshift-deft");
    $("oauth-details").replaceChildren();
    for (const [label, value] of [[__("Metadata", "atshift-deft"), oauth.metadataUrl], [__("Authorization", "atshift-deft"), oauth.authorizationEndpoint], [__("Token", "atshift-deft"), oauth.tokenEndpoint], [__("Scope", "atshift-deft"), `sf:drafts${Number(item?.authoring) ? " sf:authoring" : ""}${$("oauth-publishing").checked ? " sf:publish" : ""}`], [__("Client authentication", "atshift-deft"), __("none; PKCE S256 required", "atshift-deft")]]) {
      const dt = document.createElement("dt"), dd = document.createElement("dd"); dt.textContent = label; dd.textContent = value || ""; dd.style.overflowWrap = "anywhere"; $("oauth-details").append(dt, dd);
    }
  }
  async function refreshOAuth() {
    oauth = await request("oauth-clients"); oauth.items ||= [];
    oauthDiscovery = false;
    if (oauth.configured && oauth.metadataUrl) {
      try {
        const url = new URL(oauth.metadataUrl);
        if (url.origin === location.origin) {
          const response = await fetch(url, { credentials: "omit", cache: "no-store", redirect: "error" });
          const metadata = await response.json();
          oauthDiscovery = response.ok && metadata.issuer === oauth.issuer && metadata.token_endpoint === oauth.tokenEndpoint;
        }
      } catch { /* Server routing must be fixed before external clients can discover OAuth. */ }
    }
    if ($("oauth-applications")) {
      $("oauth-applications").replaceChildren();
      for (const client of oauth.items) {
        const li = document.createElement("li"), name = document.createElement("span"), button = document.createElement("button");
        name.textContent = `${client.name} (${client.provider}) `; button.type = "button"; button.className = "button"; button.textContent = __("Disable application", "atshift-deft");
        button.onclick = () => run(async () => {
          if (!window.confirm(/* translators: 1: client.name. */ sprintf(__("Disable %1$s for all staff and cancel its pending reservations?", "atshift-deft"), client.name))) return;
          await request(`oauth-clients/${client.id}/revoke`, {}); await refreshOAuth(); await refresh();
        });
        li.append(name, button); $("oauth-applications").append(li);
      }
    }
    renderOAuth();
  }
  async function request(path, body) {
    const url = new URL(config.root);
    if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
    else url.pathname += path;
    const response = await fetch(url, { method: body ? "POST" : "GET", credentials: "same-origin", cache: "no-store",
      headers: { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" }, ...(body ? {body: JSON.stringify(body)} : {}) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || data.error || __("Connection request failed.", "atshift-deft"));
    return data;
  }
  function clear() {
    $("connection-token").value = ""; $("connection-secret").hidden = true;
    for (const id of ["connection-client", "connection-authoring", "connection-publishing"]) if ($(id)) $(id).disabled = false;
    renderClient();
  }
  async function refresh() {
    const data = await request("connections");
    serverReady = data.ready && data.secure;
    endpoints = {drafts: data.endpoint, publishing: data.publishingEndpoint}; endpoint();
    if (!data.ready) status(__("Activate the official MCP Adapter plugin (0.7.1 or newer) to enable connections.", "atshift-deft"));
    else if (!data.secure) status(__("HTTPS is required to create and use connections.", "atshift-deft"));
    else if (new URL(data.endpoint).hostname === "127.0.0.1" || new URL(data.endpoint).hostname === "localhost") status(__("Local site: connect from this computer. Cloud-hosted AI services cannot reach this address.", "atshift-deft"));
    else status(__("Ready.", "atshift-deft"));
    $("connections").replaceChildren();
    for (const item of data.items) {
      const li = document.createElement("li"); li.className = "sf-toolbar";
      const name = document.createElement("span"); name.textContent = /* translators: 1: item.name; 2: Number(item.authoring) ? "Layouts, files and previews" : "Draft editing"; 3: Number(item.publishing) ? "Publishing allowed" : "No publishing"; 4: new Date(Number(item.expires) * 1000).toLocaleString(); 5: Number(item.last_used) ? `Last connected ${new Date(Number(item.last_used) * 1000).toLocaleString()}` : "Not c. */ sprintf(__("%1$s · %2$s · %3$s · Expires %4$s · %5$s", "atshift-deft"), item.name, Number(item.authoring) ? __("Layouts, files and previews", "atshift-deft") : __("Draft editing", "atshift-deft"), Number(item.publishing) ? __("Publishing allowed", "atshift-deft") : __("No publishing", "atshift-deft"), new Date(Number(item.expires) * 1000).toLocaleString(), Number(item.last_used) ? /* translators: 1: new Date(Number(item.last_used) * 1000).toLocaleString(). */ sprintf(__("Last connected %1$s", "atshift-deft"), new Date(Number(item.last_used) * 1000).toLocaleString()) : __("Not connected yet", "atshift-deft"));
      if (item.id === pendingId && Number(item.last_used)) { $("connection-check").textContent = __("Authenticated request received. Return to the same AI conversation: it should confirm live context, then continue your article. This timestamp alone does not verify the AI's tools, file transfer or preview rendering.", "atshift-deft"); pendingId = null; }
      const revoke = document.createElement("button"); revoke.type = "button"; revoke.className = "button"; revoke.textContent = __("Revoke", "atshift-deft");
      revoke.onclick = () => run(async () => { await request(`connections/${item.id}/revoke`, {}); clear(); await refresh(); status(__("Connection revoked. Pending reservations cancelled. Published posts and saved drafts are unchanged.", "atshift-deft")); });
      li.append(name, revoke); $("connections").append(li);
    }
    if (!data.items.length) $("connections").textContent = __("No connections.", "atshift-deft");
  }
  async function run(fn) {
    if (busy) return;
    busy = true;
    try { await fn(); } catch (e) { status(e.message); } finally { busy = false; }
  }
  $("connection-form").onsubmit = (event) => { event.preventDefault(); run(async () => {
    if (!serverReady || !profiles.route($("connection-client").value, $("endpoint").value, $("client-confirm").checked).allowed || !$("connection-secret").hidden) return;
    clear(); const data = await request("connections", {name: $("connection-name").value.trim(), authoring: $("connection-authoring").checked, publishing: !!$("connection-publishing")?.checked});
    pendingId = data.id; $("connection-check").textContent = __("Waiting for your AI to connect. Add the endpoint and key in its connection settings.", "atshift-deft");
    $("connection-token").value = data.token; $("connection-secret").hidden = false;
    for (const id of ["connection-client", "connection-authoring", "connection-publishing"]) if ($(id)) $(id).disabled = true;
    $("endpoint").value = data.endpoint;
    $("connection-name").value = ""; $("connection-copy").focus();
    await refresh();
  }); };
  for (const profile of profiles.profiles) { const option = document.createElement("option"); option.value = profile.id; option.textContent = profile.label; $("connection-client").append(option); }
  $("connection-client").onchange = () => { $("client-confirm").checked = false; renderClient(); };
  $("oauth-client").onchange = renderOAuth;
  $("oauth-publishing").onchange = renderOAuth;
  if ($("oauth-register")) $("oauth-register").onsubmit = (event) => { event.preventDefault(); run(async () => {
    await request("oauth-clients", { name: $("oauth-name").value.trim(), provider: $("oauth-provider").value,
      redirectUri: $("oauth-redirect").value.trim(), authoring: $("oauth-authoring-allowed").checked, publishing: $("oauth-publishing-allowed").checked });
    $("oauth-register").reset(); await refreshOAuth(); status(__("Application registered. Each staff member must authorize their own account from the AI service.", "atshift-deft"));
  }); };
  $("client-confirm").onchange = renderClient;
  $("client-template-download").onclick = () => {
    const template = profiles.template($("connection-client").value, $("endpoint").value, !!$("connection-publishing")?.checked);
    if (!template) return;
    const url = URL.createObjectURL(new Blob([template.text], { type: template.type }));
    const link = document.createElement("a"); link.href = url; link.download = template.filename;
    document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  if ($("connection-publishing")) $("connection-publishing").onchange = () => { clear(); endpoint(); };
  $("connection-copy").onclick = () => run(async () => { await navigator.clipboard.writeText($("connection-token").value); status(__("Key copied. Keep it in your AI app's secure connection settings.", "atshift-deft")); });
  $("endpoint-copy").onclick = () => run(async () => { await navigator.clipboard.writeText($("endpoint").value); status(__("Endpoint copied.", "atshift-deft")); });
  $("connection-refresh").onclick = () => run(async () => { await refresh(); await refreshOAuth(); });
  const poll = setInterval(() => { if (pendingId && document.visibilityState === "visible") run(refresh); }, 5000);
  $("connection-hide").onclick = () => { clear(); $("connection-name").focus(); };
  window.addEventListener("pagehide", () => { clear(); clearInterval(poll); });
  run(async () => { await refresh(); await refreshOAuth(); });
})();
