import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { createHash } from "node:crypto";
import { JSDOM } from "jsdom";
import { installI18n } from "./wp-i18n-fixture.mjs";

const source = readFileSync(new URL("../wordpress/atshift-deft/connection-profiles.js", import.meta.url), "utf8");
const window = {};
installI18n(window);
runInNewContext(source, { window, URL });
const api = window.atshiftDeftConnectionProfiles;
const endpoint = "https://example.org/site/?rest_route=%2Fstructured-fields%2Fmcp";

test("all clients have distinct, honest routes; OAuth and JSON never issue a key", () => {
  assert.equal(new Set(api.profiles.map(p => p.id)).size, 11);
  for (const id of ["chatgpt", "gemini", "claude-oauth", "oauth", "json", "unknown", ""]) {
    assert.equal(api.route(id, endpoint, true).allowed, false);
    assert.equal(api.template(id, endpoint), null);
  }
  assert.match(api.profiles.find(p => p.id === "gemini").note, /US-only/);
  assert.match(api.profiles.find(p => p.id === "claude").note, /limited beta/);
});

test("Claude and generic clients require explicit private-header capability", () => {
  for (const id of ["claude", "http"]) {
    assert.equal(api.route(id, endpoint).allowed, false);
    assert.equal(api.route(id, endpoint, true).allowed, true);
  }
});

test("cloud routes reject loopback, LAN, private IPv6 and non-HTTPS endpoints", () => {
  for (const host of ["127.0.0.1", "localhost", "x.localhost", "192.168.1.2", "10.1.2.3", "172.16.1.2", "169.254.1.1", "[::1]", "[fd00::1]", "wp.test"]) {
    assert.equal(api.route("claude", `https://${host}/mcp`, true).allowed, false, host);
  }
  assert.equal(api.route("claude", "http://example.org/mcp", true).allowed, false);
  assert.equal(api.route("claude-code", "http://127.0.0.1:8128/?rest_route=/mcp").allowed, true);
});

test("templates reject credentials, fragments, secret query parameters and unsafe URLs", () => {
  for (const value of ["javascript:alert(1)", "https://user:secret@example.org/mcp", `${endpoint}#token`, `${endpoint}&key=secret`, `${endpoint}&access_token=secret`, "https://example.org/${SECRET}", "not a url"]) {
    assert.equal(api.template("gemini-cli", value), null, value);
    assert.equal(api.route("http", value, true).allowed, false, value);
  }
});

test("Claude Code and Gemini CLI templates preserve query routes and use only environment references", () => {
  for (const id of ["claude-code", "gemini-cli"]) {
    const template = api.template(id, endpoint);
    const servers = JSON.parse(template.text).mcpServers;
    const [name] = Object.keys(servers);
    const server = servers[name];
    assert.match(name, /^structured_fields_drafts_[a-f0-9]{8}$/);
    assert.match(template.env, /^SF_DRAFT_KEY_[A-F0-9]{8}$/);
    assert.equal(server.url || server.httpUrl, endpoint);
    assert.equal(server.headers.Authorization, `Bearer \${${template.env}}`);
    if (id === "gemini-cli") assert.equal(server.trust, false);
    else assert.equal(server.type, "http");
    assert.ok(!template.text.includes("sfc_"));
  }
});

test("Codex TOML uses a dedicated environment name and retains approval prompts", () => {
  const template = api.template("codex", endpoint);
  assert.ok(template.text.includes(`url = ${JSON.stringify(endpoint)}`));
  assert.ok(template.text.includes(`bearer_token_env_var = "${template.env}"`));
  assert.match(template.text, /default_tools_approval_mode = "prompt"/);
  const publish = api.template("codex", endpoint + "-publishing", true);
  const other = api.template("codex", "https://other.example.org/mcp");
  assert.notEqual(template.env, publish.env);
  assert.notEqual(template.env, other.env);
  assert.match(publish.env, /^SF_PUBLISHING_KEY_/);
});

test("desktop and web instructions use distinct observed menus and authentication", () => {
  const desktop = api.profiles.find(p => p.id === "codex-desktop");
  assert.equal(api.route(desktop.id, endpoint).allowed, true);
  assert.equal(api.route(desktop.id, "http://example.org/mcp").allowed, false);
  assert.equal(api.template(desktop.id, endpoint), null);
  assert.match(desktop.steps.join("\n"), /Settings > Plugins > Add > Add MCP server/);
  assert.match(desktop.steps.join("\n"), /Streamable HTTP \(not STDIO\)/);
  assert.match(desktop.steps.join("\n"), /leave Bearer token environment variable empty/);
  assert.match(desktop.steps.join("\n"), /name to Authorization.*value to Bearer <connection-key>/);
  assert.match(desktop.steps.join("\n"), /MCP tab.*gear beside the server name/);
  assert.match(desktop.steps.join("\n"), /read-only context check/);
  const web = api.profiles.find(p => p.id === "chatgpt");
  assert.equal(web.auth, "oauth");
  assert.equal(api.route(web.id, endpoint).allowed, false);
  assert.match(web.steps.join("\n"), /View agents, then Add > Add custom MCP server/);
  assert.match(web.steps.join("\n"), /does not prove that DeFt OAuth works/);
  const claudeOAuth = api.profiles.find(p => p.id === "claude-oauth");
  const claudeHeader = api.profiles.find(p => p.id === "claude");
  assert.match(claudeOAuth.steps.join("\n"), /Settings > Connectors > Add/);
  assert.match(claudeOAuth.steps.join("\n"), /check the following authentication screen/);
  assert.match(claudeHeader.steps.join("\n"), /form alone does not prove header support/);
  assert.equal(api.route(claudeHeader.id, endpoint).allowed, false);
});

test("downloaded guide documents private Claude headers and scoped cloud troubleshooting", () => {
  const read = path => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
  const connections = read("wordpress/atshift-deft/docs/CONNECTIONS.md");
  const onboarding = read("wordpress/atshift-deft/includes/class-onboarding.php");
  const guide = read("wordpress/atshift-deft/AI-GUIDE.md");
  const english = read("docs/GETTING-STARTED.md");
  const japanese = read("wordpress/atshift-deft/GETTING-STARTED.ja.md");
  assert.match(onboarding, /file_get_contents\( __DIR__ \. '\/\.\.\/docs\/CONNECTIONS\.md' \)/);
  assert.match(connections, /No sign-in means no OAuth flow here, not anonymous access/);
  assert.match(connections, /Bearer <connection-key>/);
  assert.match(connections, /sample article creation and draft save/);
  assert.match(connections, /draft-only completion, not publication/);
  assert.match(connections, /user-reported result from one setup/);
  assert.match(connections, /preview, file transfer, publication and the separate OAuth route remain unverified/);
  assert.match(japanese, /サンプル記事の作成・下書き保存/);
  assert.match(japanese, /公開はしていません/);
  assert.match(connections, /## Cloud Connection Errors/);
  assert.match(connections, /obtain approval before changes/);
  assert.match(connections, /Do not turn off\s+all overseas restrictions, WAF, login protection or DeFt authentication/);
  assert.match(connections, /DeFt does not change these server settings itself/);
  assert.match(guide, /Do not automatically recreate keys or disable protections/);
  for (const document of [english, japanese]) {
    assert.match(document, /CONNECTIONS\.md#cloud-connection-errors/);
    assert.match(document, /Xserver/);
  }
});

test("Japanese client guidance resolves from the compiled path-specific catalog", () => {
  const hash = createHash("md5").update("connection-profiles.js").digest("hex");
  const catalog = JSON.parse(readFileSync(new URL(`../wordpress/atshift-deft/languages/atshift-deft-ja-${hash}.json`, import.meta.url), "utf8"));
  const translated = {};
  installI18n(translated).setLocaleData(catalog.locale_data.messages, "atshift-deft");
  runInNewContext(source, { window: translated, URL });
  const profiles = translated.atshiftDeftConnectionProfiles.profiles;
  const desktop = profiles.find(p => p.id === "codex-desktop");
  assert.equal(desktop.label, "Codex（デスクトップ版）");
  assert.match(desktop.steps.join("\n"), /Bearer トークン環境変数.*空欄/);
  assert.match(desktop.steps.join("\n"), /サーバー名の横にある歯車/);
  assert.match(profiles.find(p => p.id === "chatgpt").steps.join("\n"), /エージェント一覧を見る/);
  assert.match(profiles.find(p => p.id === "claude-oauth").steps.join("\n"), /設定 → コネクタ → 追加/);
});

test("connection UI gates key creation, exports no secrets and keeps issued scope fixed", async () => {
  const php = readFileSync(new URL("../wordpress/atshift-deft/includes/connections-page.php", import.meta.url), "utf8");
  const dom = new JSDOM(php.replace(/<\?php[\s\S]*?\?>/g, ""), { runScripts: "outside-only", url: "https://example.org/wp-admin/" });
  const w = dom.window, $ = id => w.document.getElementById(`sf-${id}`);
  const requests = [], token = "sfc_disposable_secret_DO_NOT_EXPORT";
  let connected = false;
  w.atshiftDeftWordPress = { root: "https://example.org/wp-json/structured-fields/v1/", nonce: "test" };
  w.fetch = async (url, options) => {
    requests.push({ url: String(url), options });
    const data = options.method === "POST" ? { id: "fixture", token, endpoint } : { ready: true, secure: true, endpoint, publishingEndpoint: endpoint + "-publishing", items: connected && !String(url).includes("oauth-clients") ? [{id:"fixture",name:"UI test",last_used:1,expires:9999999999}] : [] };
    return { ok: true, json: async () => data };
  };
  const downloads = [];
  w.URL.createObjectURL = blob => { downloads.push(blob); return "blob:test"; };
  w.URL.revokeObjectURL = () => {};
  w.HTMLAnchorElement.prototype.click = function () {};
  installI18n(w);
  w.eval(source);
  w.eval(readFileSync(new URL("../wordpress/atshift-deft/connections.js", import.meta.url), "utf8"));
  const settle = () => new Promise(resolve => setTimeout(resolve, 0));
  const select = id => { $("connection-client").value = id; $("connection-client").dispatchEvent(new w.Event("change")); };
  try {
    await settle();
    assert.equal($("connection-create").disabled, true);
    for (const id of ["chatgpt", "gemini", "json"]) {
      select(id); assert.equal($("key-setup").hidden, true);
      $("connection-form").dispatchEvent(new w.Event("submit", { cancelable: true })); await settle();
    }
    assert.equal(requests.filter(r => r.options.method === "POST").length, 0);
    select("codex-desktop");
    assert.equal($("connection-create").disabled, false);
    assert.equal($("client-template-section").hidden, true);
    assert.match($("client-steps").textContent, /leave Bearer token environment variable empty/);
    assert.equal(requests.filter(r => r.options.method === "POST").length, 0);
    select("claude"); assert.equal($("connection-create").disabled, true);
    $("client-confirm").checked = true; $("client-confirm").dispatchEvent(new w.Event("change"));
    assert.equal($("connection-create").disabled, false);
    select("claude-code"); assert.equal($("client-confirm").checked, false);
    $("connection-publishing").checked = true; $("connection-publishing").dispatchEvent(new w.Event("change"));
    assert.match($("client-template").value, /SF_PUBLISHING_KEY_/);
    $("connection-publishing").checked = false; $("connection-publishing").dispatchEvent(new w.Event("change"));
    $("connection-name").value = "UI test";
    $("connection-form").dispatchEvent(new w.Event("submit", { cancelable: true })); await settle();
    assert.equal($("connection-token").value, token);
    assert.equal($("connection-client").disabled, true);
    assert.equal($("connection-publishing").disabled, true);
    assert.equal($("connection-create").disabled, true);
    assert.equal(JSON.parse(requests.find(r => r.options.method === "POST").options.body).publishing, false);
    $("client-template-download").click(); assert.equal(downloads.length, 1);
    assert.ok(!$("client-template").value.includes(token));
    connected = true; $("connection-refresh").click(); await settle();
    assert.match($("connection-check").textContent,/Return to the same AI conversation/);
    assert.match($("connection-check").textContent,/confirm live context/);
    assert.doesNotMatch($("connection-check").textContent,/read context and the guide/);
    $("connection-hide").click();
    assert.equal($("connection-token").value, "");
    assert.equal($("connection-client").disabled, false);
    assert.equal(w.localStorage.length, 0);
  } finally { w.dispatchEvent(new w.Event("pagehide")); dom.window.close(); }
});
