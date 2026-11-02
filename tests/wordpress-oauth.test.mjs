import { test, before, after, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { randomUUID, randomBytes, createHash } from "node:crypto";
import { setTimeout as delay } from "node:timers/promises";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

// Run with: node --test tests/wordpress-oauth.test.mjs
// SF_OAUTH_WAIT_MS optionally waits for the concurrently implemented routes, never skips them.
const origin = "http://127.0.0.1:8128";
const issuer = `${origin}/structured-fields-oauth`;
const authorizeEndpoint = `${origin}/wp-admin/admin-post.php?action=atshde_oauth_authorize`;
const redirectUri = "https://oauth-client.example.invalid/callback?registered=1";
const run = randomUUID();
const prefix = `sf-oauth-test-${run}-`;
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
const fixture = (file, action, ...args) => execFileSync("php", [
  fileURLToPath(new URL(file, import.meta.url)), action, run, ...args.map(String),
], { encoding: "utf8", timeout: 30000 });
const usersFixture = (action, ...args) => fixture("./wordpress-users-fixture.php", action, ...args);
const oauthFixture = (action, ...args) => fixture("./wordpress-oauth-fixture.php", action, ...args);
const rest = (route) => {
  const url = new URL(origin);
  url.searchParams.set("rest_route", route);
  return url;
};
let tokenEndpoint = rest("/structured-fields-oauth/v1/token");
let revokeEndpoint = rest("/structured-fields-oauth/v1/revoke");
let data, a, b, admin, endpoints, sequence = 0, usersCreated = false, adminCreated = false;
let registered = [], mcpClients = [];

async function browser(user) {
  const jar = new CookieJar();
  const session = {
    async request(url, options = {}) {
      const target = new URL(url, origin);
      assert.equal(target.origin, origin, "Never follow an OAuth callback or send fixture cookies outside local WordPress");
      const response = await fetch(target, {
        redirect: "manual", signal: AbortSignal.timeout(15000), ...options,
        headers: { Cookie: await jar.getCookieString(target.href), ...options.headers },
      });
      for (const cookie of response.headers.getSetCookie()) await jar.setCookie(cookie, target.href);
      return response;
    },
    async login(account, redirectTo) {
      await session.request("/wp-login.php");
      const body = new URLSearchParams({ log: account.username, pwd: account.password, testcookie: "1" });
      if (redirectTo) body.set("redirect_to", redirectTo);
      const response = await session.request("/wp-login.php", { method: "POST", body });
      assert.equal(response.status, 302, "Disposable user must log in through WordPress HTTP");
      return response;
    },
    async config() {
      const response = await session.request("/wp-admin/admin.php?page=structured-fields-connections");
      assert.equal(response.status, 200);
      const dom = new JSDOM(await response.text());
      try {
        const line = [...dom.window.document.scripts].flatMap(s => s.textContent.split("\n"))
          .map(s => s.trim()).find(s => s.startsWith("window.atshiftDeftWordPress="));
        assert.ok(line, "Connections screen must expose its browser REST nonce");
        session.settings = JSON.parse(line.slice("window.atshiftDeftWordPress=".length, -1));
      } finally { dom.window.close(); }
    },
    async consentNonce(flow) {
      const cookie = (await jar.getCookies(origin)).find(item => item.key.startsWith("wordpress_logged_in_"));
      assert.ok(cookie); assert.ok(user);
      return execFileSync("php", [fileURLToPath(new URL("./wordpress-oauth-fixture.php", import.meta.url)), "session-nonce", run, String(user.uid)], {
        encoding: "utf8", timeout: 30000, input: JSON.stringify({ flow, cookie: decodeURIComponent(cookie.value) }),
      });
    },
    url(path) {
      const url = new URL(session.settings.root);
      if (url.searchParams.has("rest_route")) url.searchParams.set("rest_route", url.searchParams.get("rest_route") + path);
      else url.pathname += path;
      return url;
    },
    api(path, body) {
      return session.request(session.url(path), {
        method: body === undefined ? "GET" : "POST",
        headers: { "X-WP-Nonce": session.settings.nonce, "Content-Type": "application/json" },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
    },
  };
  if (user) { await session.login(user); await session.config(); }
  return session;
}

async function json(response, status = 200) {
  const text = await response.text();
  assert.equal(response.status, status, `Expected HTTP ${status}, got ${response.status}: ${text.slice(0, 500)}`);
  return JSON.parse(text);
}

function noCredentials(value, credentials = []) {
  const text = JSON.stringify(value);
  for (const secret of credentials) if (secret) assert.ok(!text.includes(secret), "Response must not expose an issued credential");
  assert.doesNotMatch(text, /"(?:access_token|refresh_token|client_secret|secret_hash|credential_hash|private_key|session_hash)"\s*:|sfc_[a-f0-9-]{36}_|sfo_eyJ|BEGIN (?:RSA )?PRIVATE KEY/);
}

async function register(options = {}) {
  const result = await json(await admin.api("oauth-clients", {
    name: `${prefix}${++sequence}`, provider: "other", redirectUri, authoring: false, publishing: false, ...options,
  }));
  assert.match(result.id, uuid);
  assert.equal(result.client_id, result.id);
  assert.equal(result.token_endpoint_auth_method, "none");
  assert.equal(result.redirect_uri, options.redirectUri ?? redirectUri);
  noCredentials(result);
  registered.push(result.id);
  return { ...result, redirectUri: options.redirectUri ?? redirectUri };
}

function authorization(client, overrides = {}) {
  const verifier = randomBytes(48).toString("base64url");
  const params = {
    response_type: "code", client_id: client.id, redirect_uri: client.redirectUri,
    resource: endpoints.endpoint, scope: "sf:drafts", state: `state-${randomUUID()}`,
    code_challenge: createHash("sha256").update(verifier).digest("base64url"), code_challenge_method: "S256", ...overrides,
  };
  const url = new URL(authorizeEndpoint);
  for (const [key, value] of Object.entries(params)) if (value !== undefined) url.searchParams.set(key, value);
  return { client, verifier, params, url };
}

async function consent(session, request) {
  const response = await session.request(request.url);
  const html = await response.text();
  assert.equal(response.status, 200, `Expected consent form: ${html.slice(0, 400)}`);
  const dom = new JSDOM(html, { url: request.url.href });
  try {
    const form = [...dom.window.document.forms].find(f => f.querySelector('[name="flow"]'));
    assert.ok(form, "Authorization must render a real consent form");
    assert.equal(form.method.toLowerCase(), "post");
    const values = Object.fromEntries([...form.querySelectorAll("input[name]")].map(input => [input.name, input.value]));
    assert.equal(values.action, "atshde_oauth_authorize");
    assert.ok(values.flow); assert.ok(values._wpnonce);
    const decisions = [...form.querySelectorAll('[name="decision"]')].map(input => input.value).sort();
    assert.deepEqual(decisions, ["approve", "deny"]);
    noCredentials({ html });
    return { ...request, formUrl: new URL(form.action, request.url), values };
  } finally { dom.window.close(); }
}

const decide = (session, flow, overrides = {}) => session.request(flow.formUrl, {
  method: "POST", body: new URLSearchParams({ ...flow.values, decision: "approve", ...overrides }),
});

function callback(response, request, error) {
  assert.ok([302, 303].includes(response.status), `Expected OAuth redirect, got ${response.status}`);
  const url = new URL(response.headers.get("location"));
  const expected = new URL(request.params.redirect_uri);
  assert.equal(url.origin, expected.origin); assert.equal(url.pathname, expected.pathname);
  for (const [key, value] of expected.searchParams) assert.equal(url.searchParams.get(key), value);
  assert.equal(url.searchParams.get("state"), request.params.state);
  assert.equal(url.searchParams.get("iss"), issuer);
  assert.equal(url.hash, "");
  if (error) {
    assert.equal(url.searchParams.get("error"), error);
    assert.equal(url.searchParams.has("code"), false);
  } else {
    assert.equal(url.searchParams.has("error"), false);
    assert.ok(url.searchParams.get("code"));
  }
  return url;
}

async function rejectedAuthorization(session, request, { noRedirect = false } = {}) {
  const response = await session.request(request.url);
  if ([302, 303].includes(response.status) && !noRedirect) {
    const target = new URL(response.headers.get("location"));
    assert.ok(target.searchParams.get("error"), "Rejected authorization must return an OAuth error, not login or consent");
    callback(response, request, target.searchParams.get("error"));
  } else {
    assert.ok(response.status >= 400 && response.status < 500, `Unsafe authorization accepted: ${response.status}`);
    assert.equal(response.headers.get("location"), null, "Never redirect to an unregistered callback");
  }
}

async function codeGrant(client, options = {}) {
  const session = options.session ?? a;
  const existing = new Set((await json(await session.api("connections"))).items.map(row => row.id));
  const request = authorization(client, options.params);
  const flow = await consent(session, request);
  const result = callback(await decide(session, flow), request);
  const list = await json(await session.api("connections"));
  const grant = list.items.find(row => !existing.has(row.id) && row.auth_type === "oauth" && row.oauth_client === client.id);
  assert.ok(grant, "Consent must create a listed OAuth Connections row");
  return { ...flow, code: result.searchParams.get("code"), grant, session };
}

const formPost = (url, body) => fetch(url, {
  method: "POST", redirect: "manual", signal: AbortSignal.timeout(15000),
  headers: { "Content-Type": "application/x-www-form-urlencoded" },
  body: new URLSearchParams(Object.entries(body).filter(([, value]) => value !== undefined)),
});
const rawFormPost = (url, body, contentType = "application/x-www-form-urlencoded") => fetch(url, {
  method: "POST", redirect: "manual", signal: AbortSignal.timeout(15000),
  headers: { "Content-Type": contentType }, body,
});
const exchange = (grant, changes = {}) => formPost(tokenEndpoint, {
  grant_type: "authorization_code", client_id: grant.client.id, code: grant.code,
  redirect_uri: grant.params.redirect_uri, code_verifier: grant.verifier, resource: grant.params.resource, ...changes,
});
const refresh = (grant, token, changes = {}) => formPost(tokenEndpoint, {
  grant_type: "refresh_token", client_id: grant.client.id, refresh_token: token, resource: grant.params.resource, ...changes,
});

async function tokenError(response, expected) {
  const value = await response.json();
  assert.ok(response.status >= 400 && response.status < 500, `Invalid token request accepted (${response.status})`);
  assert.equal(typeof value.error, "string", "Use OAuth error shape, not a WordPress success envelope");
  if (expected) assert.equal(value.error, expected);
  assert.equal(value.access_token, undefined); assert.equal(value.refresh_token, undefined);
  return value;
}

async function tokenResponse(response, grant) {
  assert.match(response.headers.get("cache-control") ?? "", /no-store/i);
  assert.match(response.headers.get("pragma") ?? "", /no-cache/i);
  const result = await json(response);
  assert.equal(result.token_type.toLowerCase(), "bearer");
  assert.match(result.access_token, /^sfo_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/);
  assert.equal(typeof result.refresh_token, "string"); assert.ok(result.refresh_token.length >= 32);
  assert.ok(result.expires_in > 0 && result.expires_in <= 600);
  const [header, payload] = result.access_token.slice(4).split(".").slice(0, 2).map(part => JSON.parse(Buffer.from(part, "base64url")));
  assert.equal(header.alg, "RS256");
  assert.equal(payload.iss, issuer);
  assert.ok((Array.isArray(payload.aud) ? payload.aud : [payload.aud]).includes(grant.params.resource));
  assert.ok(payload.exp > Date.now() / 1000 && payload.exp <= Date.now() / 1000 + 605);
  assert.deepEqual([...payload.scopes].sort(), grant.params.scope.split(" ").sort());
  assert.equal(payload.client_id, grant.client.id);
  if (result.scope !== undefined) assert.deepEqual(result.scope.split(" ").sort(), grant.params.scope.split(" ").sort());
  return { ...result, claims: payload };
}

async function issued(options = {}) {
  const client = await register(options.client);
  const grant = await codeGrant(client, options);
  const tokens = await tokenResponse(await exchange(grant), grant);
  return { ...grant, ...tokens };
}

async function connect(token, endpoint = endpoints.endpoint) {
  const client = new Client({ name: "structured-fields-oauth-integration-test", version: "1.0.0" });
  mcpClients.push(client);
  await client.connect(new StreamableHTTPClientTransport(new URL(endpoint), {
    requestInit: { headers: { Authorization: `Bearer ${token}` } },
  }));
  return client;
}
async function call(client, name, args = {}) {
  const raw = await client.callTool({ name: `structured-fields-${name}`, arguments: args });
  return { raw, data: raw.isError ? null : (raw.structuredContent ?? JSON.parse(raw.content[0].text)) };
}
async function good(client, name, args) {
  const result = await call(client, name, args);
  assert.ok(!result.raw.isError, JSON.stringify(result.raw));
  return result.data;
}
const unauthorized = async (token, endpoint = endpoints.endpoint) => {
  const response = await fetch(endpoint, { redirect: "manual", headers: { Authorization: `Bearer ${token}` } });
  assert.equal(response.status, 401);
};

before(async () => {
  const waitMs = Number(process.env.SF_OAUTH_WAIT_MS ?? 0);
  assert.ok(Number.isFinite(waitMs) && waitMs >= 0 && waitMs <= 600000);
  const deadline = Date.now() + waitMs;
  let response;
  do {
    response = await fetch(rest("/structured-fields/v1/oauth-clients"), { signal: AbortSignal.timeout(10000) });
    if (response.status !== 404 || Date.now() >= deadline) break;
    await delay(500);
  } while (true);
  assert.notEqual(response.status, 404, "OAuth routes are not installed yet; rerun after implementation, or set SF_OAUTH_WAIT_MS");
  usersCreated = true;
  data = JSON.parse(usersFixture("create"));
  adminCreated = true;
  const adminUser = JSON.parse(oauthFixture("create"));
  a = await browser(data.users[0]); b = await browser(data.users[1]); admin = await browser(adminUser);
  endpoints = await json(await a.api("connections"));
  const oauth = await json(await a.api("oauth-clients"));
  tokenEndpoint = new URL(oauth.tokenEndpoint);
  revokeEndpoint = new URL(tokenEndpoint);
  revokeEndpoint.searchParams.set("rest_route", "/structured-fields-oauth/v1/revoke");
  assert.equal(new URL(endpoints.endpoint).searchParams.get("rest_route"), "/structured-fields-mcp/v1/mcp");
  assert.equal(new URL(endpoints.publishingEndpoint).searchParams.get("rest_route"), "/structured-fields-mcp/v1/publishing");
});

beforeEach(() => { if (data) assert.match(usersFixture("author"), /fixture-ok/); });
afterEach(async () => {
  const failures = [];
  for (const client of mcpClients.splice(0)) { try { await client.close(); } catch (error) { failures.push(error); } }
  for (const session of [a, b]) if (session) {
    try {
      const list = await json(await session.api("connections"));
      for (const row of list.items) await json(await session.api(`connections/${row.id}/revoke`, {}));
    } catch (error) { failures.push(error); }
  }
  for (const id of registered.splice(0)) {
    try {
      const response = await admin.api(`oauth-clients/${id}/revoke`, {});
      assert.ok([200, 404].includes(response.status), `Client cleanup failed: ${response.status}`);
    } catch (error) { failures.push(error); }
  }
  if (data) {
    try { assert.match(oauthFixture("clear-flows"), /fixture-ok/); }
    catch (error) { failures.push(error); }
  }
  if (failures.length) throw new AggregateError(failures, "OAuth test cleanup failed");
});
after(() => {
  try { if (adminCreated) assert.match(oauthFixture("cleanup"), /fixture-ok/); }
  finally { if (usersCreated) assert.match(usersFixture("cleanup"), /fixture-ok/); }
});

test("OAuth clients are readable by nonce-authenticated workspace staff, but registration and revocation require an administrator", async () => {
  const url = admin.url("oauth-clients");
  assert.equal((await fetch(url)).status, 403);
  assert.equal((await admin.request(url)).status, 403);
  assert.equal((await admin.request(url, { headers: { "X-WP-Nonce": "invalid" } })).status, 403);
  assert.equal((await a.request(a.url("oauth-clients"))).status, 403);
  const staffList = await json(await a.api("oauth-clients"));
  assert.equal(staffList.canManage, false); noCredentials(staffList);
  try {
    usersFixture("revoke");
    assert.equal((await a.api("oauth-clients")).status, 403, "A subscriber session cannot list registered clients");
  } finally { usersFixture("restore"); }
  const body = { name: `${prefix}unauthorized`, provider: "other", redirectUri, authoring: false, publishing: false };
  assert.equal((await a.api("oauth-clients", body)).status, 403);
  assert.equal((await admin.request(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) })).status, 403);
  const client = await register();
  const shared = await json(await a.api("oauth-clients"));
  assert.ok(shared.items.some(item => item.id === client.id)); noCredentials(shared);
  assert.equal((await a.api(`oauth-clients/${client.id}/revoke`, {})).status, 403);
  assert.equal((await admin.request(admin.url(`oauth-clients/${client.id}/revoke`), { method: "POST" })).status, 403);
});

test("registered clients are public, provider labels are bounded, and lists contain no credentials", async () => {
  const expectedProviders = new Map();
  for (const provider of ["chatgpt", "claude", "gemini", "other"]) {
    const client = await register({ provider });
    expectedProviders.set(client.id, provider);
  }
  const list = await json(await admin.api("oauth-clients"));
  assert.ok(Array.isArray(list.items));
  for (const id of registered) assert.ok(list.items.some(client => client.id === id));
  for (const [id, provider] of expectedProviders) assert.equal(list.items.find(client => client.id === id).provider, provider);
  noCredentials(list);
  for (const changes of [{ provider: "unknown" }, { authoring: "true" }, { publishing: 1 }, { name: "" }, { client_secret: "not-supported" }]) {
    assert.equal((await admin.api("oauth-clients", { name: `${prefix}invalid`, provider: "other", redirectUri, authoring: false, publishing: false, ...changes })).status, 400);
  }
});

test("client registration ensures RSA signing keys without exposing plaintext private key material", async () => {
  const before = JSON.parse(oauthFixture("keys"));
  await register();
  const after = JSON.parse(oauthFixture("keys"));
  assert.equal(after.configured, true); assert.equal(after.type, 0); assert.ok(after.bits >= 2048);
  assert.equal(after.encrypted, true); assert.equal(after.hasPlaintextPrivateKey, false);
  if (before.configured) assert.equal(after.publicHash, before.publicHash, "Registering another client must not rotate existing signing keys");
});

test("redirect registration rejects insecure remote URLs, fragments, credentials and wildcard callbacks", async () => {
  for (const uri of ["http://remote.example.invalid/callback", "javascript:alert(1)", "//remote.example.invalid/callback",
    "https://oauth-client.example.invalid/callback#fragment", "https://user:password@oauth-client.example.invalid/callback",
    "https://*.example.invalid/callback", "http://127.0.0.1.evil.example.invalid/callback"]) {
    const response = await admin.api("oauth-clients", { name: `${prefix}bad-redirect`, provider: "other", redirectUri: uri, authoring: false, publishing: false });
    assert.equal(response.status, 400, uri);
  }
  const local = await register({ redirectUri: "http://127.0.0.1:39117/callback" });
  const grant = await codeGrant(local);
  await tokenResponse(await exchange(grant), grant);
});

test("authorization-server discovery advertises the issuer and public-client OAuth endpoints", async () => {
  await register();
  const discovery = await json(await fetch(`${origin}/.well-known/oauth-authorization-server/structured-fields-oauth`, { redirect: "manual" }));
  assert.equal(discovery.issuer, issuer);
  assert.equal(discovery.authorization_endpoint, authorizeEndpoint);
  for (const [actual, expected] of [[discovery.token_endpoint, tokenEndpoint], [discovery.revocation_endpoint, revokeEndpoint]]) {
    const url = new URL(actual);
    assert.equal(url.origin, origin); assert.equal(url.pathname, expected.pathname);
    assert.deepEqual([...url.searchParams], [...expected.searchParams]);
  }
  assert.deepEqual(discovery.response_types_supported, ["code"]);
  assert.deepEqual(discovery.token_endpoint_auth_methods_supported, ["none"]);
  assert.deepEqual(discovery.code_challenge_methods_supported, ["S256"]);
  assert.ok(discovery.grant_types_supported.includes("authorization_code"));
  assert.ok(discovery.grant_types_supported.includes("refresh_token"));
  for (const scope of ["sf:drafts", "sf:authoring", "sf:publish"]) assert.ok(discovery.scopes_supported.includes(scope));
  noCredentials(discovery);
});

test("401 challenges advertise protected-resource metadata preserving each exact query-route MCP endpoint", async () => {
  await register();
  for (const resource of [endpoints.endpoint, endpoints.publishingEndpoint]) {
    const response = await fetch(resource, { redirect: "manual" });
    assert.equal(response.status, 401);
    const challenge = response.headers.get("www-authenticate") ?? "";
    assert.match(challenge, /^Bearer\b/i);
    const metadataUrl = challenge.match(/resource_metadata="([^"]+)"/)?.[1];
    assert.ok(metadataUrl, "401 must advertise protected-resource metadata");
    const url = new URL(metadataUrl);
    assert.equal(url.origin, origin);
    assert.ok(url.searchParams.get("rest_route")?.startsWith("/structured-fields-oauth/v1/"));
    const metadata = await json(await fetch(url));
    assert.equal(metadata.resource, resource);
    assert.deepEqual(metadata.authorization_servers, [issuer]);
    noCredentials(metadata);
  }
});

test("an anonymous authorization request logs in before consent and returns code, issuer and state only after approval", async () => {
  const client = await register();
  const request = authorization(client);
  const anonymous = await browser();
  const response = await anonymous.request(request.url);
  assert.equal(response.status, 302);
  const login = new URL(response.headers.get("location"));
  assert.equal(login.origin, origin); assert.equal(login.pathname, "/wp-login.php");
  const returnTo = new URL(login.searchParams.get("redirect_to"));
  assert.equal(returnTo.origin, origin); assert.equal(returnTo.pathname, request.url.pathname);
  assert.deepEqual([...returnTo.searchParams].sort(), [...request.url.searchParams].sort());
  const loggedIn = await anonymous.login(data.users[0], login.searchParams.get("redirect_to"));
  assert.equal(loggedIn.headers.get("location"), returnTo.href);
  const flow = await consent(anonymous, request);
  const approved = callback(await decide(anonymous, flow), request);
  const grant = { ...flow, code: approved.searchParams.get("code") };
  const token = await tokenResponse(await exchange(grant), grant);
  const mcp = await connect(token.access_token);
  assert.equal((await good(mcp, "context")).capabilities.publish, false);
});

test("denied consent redirects only to the registered callback, retaining state and issuer without creating a grant", async () => {
  const client = await register();
  const request = authorization(client, { state: "literal + & state / ? =" });
  const before = await json(await a.api("connections"));
  const flow = await consent(a, request);
  callback(await decide(a, flow, { decision: "deny" }), request, "access_denied");
  assert.deepEqual((await json(await a.api("connections"))).items, before.items);
  const replay = await decide(a, flow);
  assert.ok(replay.status >= 400 && replay.status < 500, "Denied flow cannot later be approved");
});

test("legacy authorization URLs remain authenticated aliases of the new action", async () => {
  const request = authorization(await register());
  request.url.searchParams.set("action", "sf_oauth_authorize");
  const flow = await consent(a, request);
  callback(await decide(a, flow, { decision: "deny" }), request, "access_denied");
});

test("authorization refuses missing PKCE, plain PKCE, malformed challenges and unsupported response types", async () => {
  const client = await register();
  for (const changes of [{ code_challenge: undefined }, { code_challenge_method: undefined },
    { code_challenge_method: "plain" }, { code_challenge_method: "s256" }, { code_challenge: "short" }, { response_type: "token" }]) {
    await rejectedAuthorization(a, authorization(client, changes));
  }
});

test("authorization rejects unknown clients and any non-exact redirect URI without redirecting", async () => {
  const client = await register();
  for (const changes of [{ client_id: randomUUID() }, { redirect_uri: "https://evil.example.invalid/callback" },
    { redirect_uri: `${redirectUri}&extra=1` }, { redirect_uri: redirectUri.replace("callback", "callback/") }, { redirect_uri: undefined }]) {
    await rejectedAuthorization(a, authorization(client, changes), { noRedirect: true });
  }
});

test("authorization rejects missing or different resources and unknown or unapproved scopes", async () => {
  const client = await register();
  for (const changes of [{ resource: undefined }, { resource: origin }, { resource: `${endpoints.endpoint}&extra=1` },
    { resource: `${origin}/wp-json/structured-fields-mcp/v1/mcp` }, { scope: "sf:drafts unknown:scope" },
    { scope: "sf:drafts sf:authoring" }, { scope: "sf:drafts sf:publish" }, { scope: "sf:authoring" }]) {
    await rejectedAuthorization(a, authorization(client, changes));
  }
});

test("ambiguous authorization names are rejected before creating a consent flow", async () => {
  const client = await register();
  const request = authorization(client);
  const before = oauthFixture("request-counts", data.users[0].uid);
  for (const extra of ["client.id=x", "client+id=x", "client%20id=x", "client_id%00=x",
    "state[]=x", "state[0]=x", "state[x=x", "state%00=x", "st%61te=x", "state=x", "action[]=x", "action%00=x", "unknown=x"]) {
    const url = new URL(request.url);
    url.search = `${extra}&${url.search.slice(1)}`;
    await rejectedAuthorization(a, { ...request, url }, { noRedirect: true });
  }
  assert.equal(oauthFixture("request-counts", data.users[0].uid), before);
  const flow = await consent(a, request);
  callback(await decide(a, flow, { decision: "deny" }), request, "access_denied");
});

test("OAuth form decoding preserves opaque state, exact redirects and encoded parameter names", async () => {
  const client = await register({ redirectUri: `${redirectUri}&literal=a%2Bb%26c%25` });
  const request = authorization(client, { state: "  literal + & / ? = % ; \\ \" ' <tag>  " });
  request.url.search = request.url.search.slice(1).replace("client_id=", "cl%69ent_id=");
  const flow = await consent(a, request);
  const result = callback(await decide(a, flow), request);
  const grant = { ...flow, code: result.searchParams.get("code") };
  const body = new URLSearchParams({ grant_type: "authorization_code", client_id: client.id, code: grant.code,
    redirect_uri: grant.params.redirect_uri, code_verifier: grant.verifier, resource: grant.params.resource });
  await tokenResponse(await rawFormPost(tokenEndpoint, body.toString().replace("client_id=", "cl%69ent_id="),
    "Application/X-WWW-Form-Urlencoded; charset=UTF-8"), grant);
});

test("omitted OAuth scope keeps its default while a bare scope is rejected", async () => {
  const request = authorization(await register(), { scope: undefined });
  const bare = new URL(request.url); bare.search += "&scope";
  await rejectedAuthorization(a, { ...request, url: bare }, { noRedirect: true });
  const flow = await consent(a, request);
  callback(await decide(a, flow, { decision: "deny" }), request, "access_denied");
});

test("consent rejects missing or wrong nonce, changed account, and a different session for the same owner", async () => {
  const client = await register();
  for (const nonce of ["", "invalid-nonce"]) {
    const flow = await consent(a, authorization(client));
    const response = await decide(a, flow, { _wpnonce: nonce });
    assert.ok(response.status >= 400 && response.status < 500);
    assert.equal(response.headers.get("location"), null);
  }
  const switched = await consent(a, authorization(client));
  assert.ok([400, 403].includes((await decide(b, switched, { _wpnonce: await b.consentNonce(switched.values.flow) })).status));
  const secondSession = await browser(data.users[0]);
  const firstFlow = await consent(a, authorization(client));
  assert.ok([400, 403].includes((await decide(secondSession, firstFlow, { _wpnonce: await secondSession.consentNonce(firstFlow.values.flow) })).status),
    "A valid nonce for the same owner in another session cannot adopt a consent flow");
  assert.equal((await json(await a.api("connections"))).items.length, 0);
  assert.equal((await json(await b.api("connections"))).items.length, 0);
});

test("malformed consent nonces cannot be repaired into valid credentials or consume the flow", async () => {
  const client = await register();
  const request = authorization(client);
  const flow = await consent(a, request);
  const nonce = flow.values._wpnonce;
  const before = await json(await a.api("connections"));
  for (const invalid of [`<b>${nonce}</b>`, ` ${nonce}`, `${nonce}\n`, `${nonce}%00`]) {
    assert.equal((await decide(a, flow, { _wpnonce: invalid })).status, 400);
  }
  const body = new URLSearchParams({ ...flow.values, decision: "approve" });
  body.delete("_wpnonce"); body.set("_wpnonce[]", nonce);
  assert.equal((await a.request(flow.formUrl, { method: "POST", body })).status, 400);
  const after = await json(await a.api("connections"));
  assert.deepEqual(after.items, before.items, "Rejected nonces do not create or modify a grant");
  callback(await decide(a, flow, { decision: "deny" }), request, "access_denied");
});

test("ambiguous consent names are rejected without consuming the original flow", async () => {
  const request = authorization(await register());
  const flow = await consent(a, request);
  const before = oauthFixture("request-counts", data.users[0].uid);
  const body = new URLSearchParams({ ...flow.values, decision: "approve" }).toString();
  for (const extra of ["decision[]=deny", "decision%00=deny", "decision=deny", "decisi%6Fn=deny",
    "flow[]=discard", "_wpnonce[]=discard", "_wpnonce%00=discard", "action[]=discard", "action%00=discard", "unknown=discard"]) {
    const response = await a.request(flow.formUrl, { method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: `${extra}&${body}` });
    assert.equal(response.status, 400);
    assert.equal(response.headers.get("location"), null);
  }
  const multipart = new FormData();
  for (const [name, value] of new URLSearchParams(body)) multipart.set(name, value);
  assert.equal((await a.request(flow.formUrl, { method: "POST", body: multipart })).status, 400);
  const formUrl = new URL(flow.formUrl); formUrl.searchParams.set("action", "sf_oauth_authorize");
  assert.equal((await a.request(formUrl, { method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...flow.values, decision: "approve" }) })).status, 400);
  assert.equal(oauthFixture("request-counts", data.users[0].uid), before);
  callback(await decide(a, flow, { decision: "deny" }), request, "access_denied");
});

test("consent flow expires, cannot be replayed, and does not accept an unknown decision", async () => {
  const client = await register();
  const invalid = await consent(a, authorization(client));
  const invalidResponse = await decide(a, invalid, { decision: "yes" });
  assert.ok(invalidResponse.status >= 400 && invalidResponse.status < 500);
  const expired = await consent(a, authorization(client));
  assert.match(oauthFixture("expire-flow", expired.values.flow), /fixture-ok/);
  const expiredResponse = await decide(a, expired);
  assert.ok(expiredResponse.status >= 400 && expiredResponse.status < 500);
  const approved = await codeGrant(client);
  const replay = await decide(a, approved);
  assert.ok(replay.status >= 400 && replay.status < 500);
  assert.equal((await json(await a.api("connections"))).items.length, 1);
});

test("public-client code exchange issues a ten-minute RSA JWT and a credential-free stable 30-day Connections grant", async () => {
  const token = await issued();
  const stored = JSON.parse(oauthFixture("grant", token.grant.id));
  assert.equal(stored.grant.auth_type, "oauth"); assert.equal(stored.grant.oauth_client, token.client.id);
  assert.equal(Number(stored.grant.owner_id), data.users[0].uid);
  assert.match(stored.grant.secret_hash, /^(?:0{64})?$/, "OAuth must not issue an sfc secret; only an empty or inert sentinel is allowed");
  await unauthorized(`sfc_${token.grant.id}_${"0".repeat(64)}`);
  assert.ok(Math.abs(Number(stored.grant.expires) - Number(stored.grant.created) - 30 * 86400) <= 1);
  assert.ok(stored.tokens.some(row => row.kind === "access"));
  assert.ok(stored.tokens.some(row => row.kind === "refresh"));
  for (const row of stored.tokens) {
    assert.equal(row.connection_id, token.grant.id); assert.equal(row.resource, endpoints.endpoint);
    assert.ok(Number(row.expires) <= Number(stored.grant.expires));
  }
  for (const credential of [token.code, token.access_token, token.refresh_token]) assert.ok(!JSON.stringify(stored).includes(credential));
  const list = await json(await a.api("connections"));
  noCredentials(list, [token.code, token.access_token, token.refresh_token]);
  noCredentials(await json(await admin.api("oauth-clients")), [token.access_token, token.refresh_token]);
  assert.equal((await json(await b.api("connections"))).items.length, 0);
  assert.equal((await b.api(`connections/${token.grant.id}/revoke`, {})).status, 404);
});

test("code exchange enforces verifier, client, redirect and full resource binding and requires every parameter", async () => {
  const client = await register();
  const other = await register();
  for (const changes of [{ code_verifier: undefined }, { code_verifier: randomBytes(48).toString("base64url") },
    { code: undefined }, { client_id: undefined }, { redirect_uri: undefined },
    { client_id: other.id }, { redirect_uri: `${redirectUri}&extra=1` }, { resource: undefined },
    { resource: endpoints.publishingEndpoint }, { resource: origin }]) {
    const grant = await codeGrant(client);
    await tokenError(await exchange(grant, changes));
    await json(await a.api(`connections/${grant.grant.id}/revoke`, {}));
  }
});

test("token requests reject JSON, duplicate credentials, array parameters and unsupported grants", async () => {
  const client = await register();
  const grant = await codeGrant(client);
  await tokenError(await fetch(tokenEndpoint, {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ grant_type: "authorization_code", code: grant.code }),
  }));
  const valid = new URLSearchParams({ grant_type: "authorization_code", client_id: client.id, code: grant.code,
    redirect_uri: grant.params.redirect_uri, code_verifier: grant.verifier, resource: grant.params.resource });
  for (const extra of [`&client_id=${client.id}`, "&resource[]=unexpected", "&client_secret=not-a-confidential-client"]) {
    await tokenError(await fetch(tokenEndpoint, {
      method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: valid.toString() + extra,
    }));
  }
  await tokenError(await formPost(tokenEndpoint, { grant_type: "client_credentials", client_id: client.id }), "unsupported_grant_type");
  await tokenResponse(await exchange(grant), grant);
});

test("ambiguous token and refresh names cannot consume or rotate credentials", async () => {
  const grant = await codeGrant(await register());
  const before = oauthFixture("grant", grant.grant.id);
  const body = new URLSearchParams({ grant_type: "authorization_code", client_id: grant.client.id, code: grant.code,
    redirect_uri: grant.params.redirect_uri, code_verifier: grant.verifier, resource: grant.params.resource }).toString();
  for (const extra of ["client.id=x", "client+id=x", "client_id%00=x", "code[]=x", "code%00=x", "co%64e=x",
    "redirect.uri=x", "code_verifier[]=x", "resource[]=x"]) {
    await tokenError(await rawFormPost(tokenEndpoint, `${extra}&${body}`), "invalid_request");
  }
  assert.equal(oauthFixture("grant", grant.grant.id), before);
  const token = await tokenResponse(await exchange(grant), grant);
  const stored = oauthFixture("grant", grant.grant.id);
  const refreshBody = new URLSearchParams({ grant_type: "refresh_token", client_id: grant.client.id,
    refresh_token: token.refresh_token, resource: grant.params.resource }).toString();
  for (const extra of ["refresh.token=x", "refresh_token[]=x", "refresh_token%00=x", "refresh%5Ftoken=x"]) {
    await tokenError(await rawFormPost(tokenEndpoint, `${extra}&${refreshBody}`), "invalid_request");
  }
  assert.equal(oauthFixture("grant", grant.grant.id), stored);
  await tokenResponse(await refresh(grant, token.refresh_token), grant);
});

test("ambiguous revocation names cannot revoke a valid connection", async () => {
  const token = await issued();
  const before = oauthFixture("grant", token.grant.id);
  const body = new URLSearchParams({ token: token.refresh_token, client_id: token.client.id }).toString();
  for (const extra of ["client.id=x", "client+id=x", "client_id%00=x", "token[]=x", "token%00=x", "tok%65n=x"]) {
    await tokenError(await rawFormPost(revokeEndpoint, `${extra}&${body}`), "invalid_request");
  }
  assert.equal(oauthFixture("grant", token.grant.id), before);
  assert.equal((await formPost(revokeEndpoint, { token: token.refresh_token, client_id: token.client.id })).status, 200);
  await unauthorized(token.access_token);
  assert.equal((await rawFormPost(revokeEndpoint, "")).status, 200);
});

test("authorization codes are one-use and expired codes cannot issue credentials", async () => {
  const token = await issued();
  await tokenError(await exchange(token), "invalid_grant");
  const expired = await codeGrant(token.client);
  assert.match(oauthFixture("expire-code", expired.grant.id), /fixture-ok/);
  await tokenError(await exchange(expired), "invalid_grant");
});

test("OAuth MCP draft reads and writes isolate owners and never authorize unrelated WordPress REST routes", async () => {
  const token = await issued();
  const client = await connect(token.access_token);
  const context = await good(client, "context");
  assert.equal(context.capabilities.publish, false); assert.equal(context.capabilities.createStructure, false);
  const list = await good(client, "list-drafts");
  assert.deepEqual(list.drafts.map(row => row.articleId), [data.users[0].id]);
  const own = await good(client, "get-draft", { articleId: data.users[0].id });
  own.composeTemplate.article.title = "Saved through OAuth HTTP";
  const saved = await good(client, "save-draft", own.composeTemplate);
  assert.equal(saved.version, own.version + 1);
  assert.equal((await call(client, "save-draft", own.composeTemplate)).raw.isError, true);
  assert.equal((await call(client, "get-draft", { articleId: data.users[1].id })).raw.isError, true);
  assert.equal((await call(client, "context", { articleId: data.users[1].id })).raw.isError, true);
  const other = structuredClone(own.composeTemplate); other.article.id = data.users[1].id; other.expectedVersion = 1;
  assert.equal((await call(client, "save-draft", other)).raw.isError, true);
  const bGrant = await codeGrant(token.client, { session: b });
  const bTokens = await tokenResponse(await exchange(bGrant), bGrant);
  const bClient = await connect(bTokens.access_token);
  assert.deepEqual((await good(bClient, "list-drafts")).drafts.map(row => row.articleId), [data.users[1].id]);
  assert.equal((await call(bClient, "get-draft", { articleId: data.users[0].id })).raw.isError, true);
  for (const route of ["/wp/v2/users", "/structured-fields/v1/articles", "/structured-fields/v1/connections",
    "/structured-fields/v1/oauth-clients", "/mcp/mcp-adapter-default-server", "/wp-abilities/v1/abilities/structured-fields/save-draft/run"]) {
    assert.equal((await fetch(rest(route), { headers: { Authorization: `Bearer ${token.access_token}` } })).status, 403, route);
  }
});

test("authoring is an optional client-approved and user-consented scope, not an automatic client capability", async () => {
  const client = await register({ authoring: true });
  const draft = await codeGrant(client);
  const draftTokens = await tokenResponse(await exchange(draft), draft);
  const draftMcp = await connect(draftTokens.access_token);
  assert.equal((await good(draftMcp, "context")).capabilities.createStructure, false);
  const draftState = await good(draftMcp, "get-draft", { articleId: data.users[0].id });
  assert.equal((await call(draftMcp, "get-preview", { articleId: data.users[0].id, expectedVersion: draftState.version })).raw.isError, true);
  const author = await codeGrant(client, { params: { scope: "sf:drafts sf:authoring" } });
  const authorTokens = await tokenResponse(await exchange(author), author);
  const authorMcp = await connect(authorTokens.access_token);
  const context = await good(authorMcp, "context");
  assert.equal(context.capabilities.createStructure, true); assert.equal(context.capabilities.transferFiles, true);
  assert.equal(context.capabilities.publish, false);
  const own = await good(authorMcp, "get-draft", { articleId: data.users[0].id });
  assert.equal((await good(authorMcp, "get-preview", { articleId: data.users[0].id, expectedVersion: own.version })).version, own.version);
});

test("access tokens reject signature, issuer, audience and scope tampering and cannot cross MCP resources", async () => {
  const token = await issued();
  await unauthorized(token.access_token, endpoints.publishingEndpoint);
  const parts = token.access_token.slice(4).split(".");
  for (const changes of [{ iss: `${issuer}/other` }, { aud: endpoints.publishingEndpoint }, { scopes: ["sf:drafts", "sf:publish"] }]) {
    const payload = Buffer.from(JSON.stringify({ ...token.claims, ...changes })).toString("base64url");
    await unauthorized(`sfo_${parts[0]}.${payload}.${parts[2]}`);
  }
  await unauthorized(`sfo_${parts[0]}.${parts[1]}.${parts[2].startsWith("A") ? "B" : "A"}${parts[2].slice(1)}`);
  await unauthorized("sfo_not-a-jwt");
});

test("validly signed fixture tokens still enforce issuer, audience, client, owner, expiry and scopes", async () => {
  for (const claims of [{ iss: `${issuer}/other` }, { aud: [endpoints.publishingEndpoint] },
    { scopes: ["sf:drafts", "sf:publish"] }, { client_id: randomUUID() }, { sub: String(data.users[1].uid) }, { exp: Math.floor(Date.now() / 1000) - 60 }]) {
    const token = await issued();
    const altered = execFileSync("php", [fileURLToPath(new URL("./wordpress-oauth-fixture.php", import.meta.url)), "sign-access", run, token.grant.id], {
      encoding: "utf8", timeout: 30000, input: JSON.stringify({ token: token.access_token, claims }),
    });
    await unauthorized(altered);
    await json(await a.api(`connections/${token.grant.id}/revoke`, {}));
  }
});

test("refresh rotates tokens on the same grant without extending its absolute 30-day expiry", async () => {
  const first = await issued({ client: { authoring: true }, params: { scope: "sf:drafts sf:authoring" } });
  const before = JSON.parse(oauthFixture("grant", first.grant.id));
  const rotated = await tokenResponse(await refresh(first, first.refresh_token), first);
  assert.notEqual(rotated.access_token, first.access_token); assert.notEqual(rotated.refresh_token, first.refresh_token);
  const after = JSON.parse(oauthFixture("grant", first.grant.id));
  assert.equal(after.grant.id, before.grant.id); assert.equal(after.grant.expires, before.grant.expires);
  assert.equal(after.grant.created, before.grant.created);
  assert.ok(after.tokens.every(row => Number(row.expires) <= Number(before.grant.expires)));
  assert.equal((await json(await a.api("connections"))).items.length, 1);
  const mcp = await connect(rotated.access_token);
  assert.equal((await good(mcp, "context")).capabilities.createStructure, true);
});

test("refresh rejects wrong client, resource, missing resource and unknown scopes without issuing tokens", async () => {
  const other = await register();
  for (const changes of [{ client_id: other.id }, { resource: undefined }, { resource: origin },
    { resource: endpoints.publishingEndpoint }, { scope: "sf:drafts unknown:scope" }, { scope: "sf:drafts sf:authoring" }]) {
    const first = await issued();
    await tokenError(await refresh(first, first.refresh_token, changes));
    await json(await a.api(`connections/${first.grant.id}/revoke`, {}));
  }
});

test("refresh may narrow scopes but must not restore authoring authority on later rotations", async () => {
  const first = await issued({ client: { authoring: true }, params: { scope: "sf:drafts sf:authoring" } });
  const narrowedGrant = { ...first, params: { ...first.params, scope: "sf:drafts" } };
  const narrowed = await tokenResponse(await refresh(first, first.refresh_token, { scope: "sf:drafts" }), narrowedGrant);
  const client = await connect(narrowed.access_token);
  assert.equal((await good(client, "context")).capabilities.createStructure, false);
  const next = await tokenResponse(await refresh(first, narrowed.refresh_token), narrowedGrant);
  const nextClient = await connect(next.access_token);
  assert.equal((await good(nextClient, "context")).capabilities.createStructure, false);
  await tokenError(await refresh(first, next.refresh_token, { scope: "sf:drafts sf:authoring" }), "invalid_scope");
});

test("reuse of a rotated refresh token revokes the entire grant including newly issued access and refresh tokens", async () => {
  const first = await issued();
  const second = await tokenResponse(await refresh(first, first.refresh_token), first);
  const active = await connect(second.access_token);
  await good(active, "context");
  await tokenError(await refresh(first, first.refresh_token), "invalid_grant");
  await unauthorized(first.access_token); await unauthorized(second.access_token);
  await assert.rejects(() => active.listTools());
  await tokenError(await refresh(first, second.refresh_token), "invalid_grant");
});

test("expired access and absolute grant expiry deny requests and cannot be renewed past the grant", async () => {
  const first = await issued();
  assert.match(oauthFixture("expire-access", first.grant.id), /fixture-ok/);
  await unauthorized(first.access_token);
  const next = await tokenResponse(await refresh(first, first.refresh_token), first);
  await connect(next.access_token);
  assert.match(oauthFixture("expire-grant", first.grant.id), /fixture-ok/);
  await unauthorized(next.access_token);
  await tokenError(await refresh(first, next.refresh_token), "invalid_grant");
});

test("connection revocation immediately blocks OAuth access and refresh while preserving the owner's draft", async () => {
  const token = await issued();
  const client = await connect(token.access_token);
  const before = await good(client, "get-draft", { articleId: data.users[0].id });
  assert.equal((await json(await a.api(`connections/${token.grant.id}/revoke`, {}))).revoked, true);
  await unauthorized(token.access_token); await assert.rejects(() => client.listTools());
  await tokenError(await refresh(token, token.refresh_token), "invalid_grant");
  assert.equal((await json(await a.api(`articles/${data.users[0].id}`))).version, before.version);
});

test("OAuth revocation is client-bound, idempotent, and invalidates access or refresh authority", async () => {
  const token = await issued();
  const other = await register();
  const wrong = await formPost(revokeEndpoint, { token: token.refresh_token, client_id: other.id });
  assert.ok([200, 400, 401].includes(wrong.status));
  const client = await connect(token.access_token);
  await good(client, "context");
  assert.equal((await formPost(revokeEndpoint, { token: token.refresh_token, client_id: token.client.id })).status, 200);
  await unauthorized(token.access_token);
  await tokenError(await refresh(token, token.refresh_token), "invalid_grant");
  assert.equal((await formPost(revokeEndpoint, { token: token.refresh_token, client_id: token.client.id })).status, 200);
  assert.equal((await formPost(revokeEndpoint, { token: "unknown-token", client_id: token.client.id })).status, 200);
  const access = await issued();
  assert.equal((await formPost(revokeEndpoint, { token: access.access_token, client_id: access.client.id })).status, 200);
  await unauthorized(access.access_token);
});

test("revoked clients cannot authorize or exchange outstanding codes and invalidate existing grants", async () => {
  const token = await issued();
  const pending = await codeGrant(token.client);
  await json(await admin.api(`oauth-clients/${token.client.id}/revoke`, {}));
  await rejectedAuthorization(a, authorization(token.client), { noRedirect: true });
  await tokenError(await exchange(pending));
  await unauthorized(token.access_token);
  await tokenError(await refresh(token, token.refresh_token));
});

test("publishing requires a client opt-in, a publisher owner, explicit scope and the publishing endpoint", async () => {
  const optedIn = await register({ publishing: true });
  await rejectedAuthorization(a, authorization(optedIn, { resource: endpoints.publishingEndpoint, scope: "sf:drafts sf:publish" }));
  usersFixture("publisher");
  const draftOnly = await register();
  await rejectedAuthorization(a, authorization(draftOnly, { resource: endpoints.publishingEndpoint, scope: "sf:drafts sf:publish" }));
  await rejectedAuthorization(a, authorization(optedIn, { scope: "sf:drafts sf:publish" }));
  await rejectedAuthorization(a, authorization(optedIn, { resource: endpoints.publishingEndpoint, scope: "sf:drafts" }));
  const grant = await codeGrant(optedIn, { params: { resource: endpoints.publishingEndpoint, scope: "sf:drafts sf:publish" } });
  const token = await tokenResponse(await exchange(grant), grant);
  await unauthorized(token.access_token, endpoints.endpoint);
  const client = await connect(token.access_token, endpoints.publishingEndpoint);
  assert.equal((await good(client, "context")).capabilities.publish, true);
  assert.ok((await client.listTools()).tools.some(tool => tool.name === "structured-fields-set-publication"));
  assert.equal((await call(client, "get-publication", { articleId: data.users[1].id })).raw.isError, true);
  usersFixture("author");
  await unauthorized(token.access_token, endpoints.publishingEndpoint);
  await tokenError(await refresh(grant, token.refresh_token));
});

for (const revoke of ["refresh-reuse", "connection", "oauth-token", "oauth-client"]) {
  test(`${revoke} revocation cancels a pending OAuth publication before a due worker can publish it`, async () => {
    usersFixture("publisher");
    const token = await issued({ client: { publishing: true }, params: { resource: endpoints.publishingEndpoint, scope: "sf:drafts sf:publish" } });
    const client = await connect(token.access_token, endpoints.publishingEndpoint);
    const own = await good(client, "get-draft", { articleId: data.users[0].id });
    const previous = await good(client, "get-publication", { articleId: data.users[0].id });
    const schedule = await good(client, "set-publication", {
      articleId: data.users[0].id, expectedVersion: own.version, expectedPublicationVersion: previous.version,
      requestId: randomUUID(), timing: "scheduled", publishAt: new Date(Date.now() + 3600000).toISOString().replace(/\.\d{3}Z$/, "+00:00"), timeZone: "UTC",
    });
    assert.equal(schedule.state, "scheduled"); assert.ok(schedule.postId > 0);
    assert.deepEqual(JSON.parse(oauthFixture("publication-reservation", data.users[0].id)), {
      postId: schedule.postId, status: "atshde_reserved", public: false, empty: true,
    });
    if (revoke === "refresh-reuse") {
      const next = await tokenResponse(await refresh(token, token.refresh_token), token);
      await tokenError(await refresh(token, token.refresh_token), "invalid_grant");
      await unauthorized(next.access_token, endpoints.publishingEndpoint);
      await tokenError(await refresh(token, next.refresh_token), "invalid_grant");
    } else if (revoke === "connection") await json(await a.api(`connections/${token.grant.id}/revoke`, {}));
    else if (revoke === "oauth-client") await json(await admin.api(`oauth-clients/${token.client.id}/revoke`, {}));
    else assert.equal((await formPost(revokeEndpoint, { token: token.refresh_token, client_id: token.client.id })).status, 200);
    const cancelled = await json(await a.api(`publications/${data.users[0].id}`));
    assert.equal(cancelled.state, "cancelled"); assert.equal(cancelled.postId, schedule.postId);
    usersFixture("publication-due", data.users[0].id);
    usersFixture("publication-run", data.users[0].id);
    const after = await json(await a.api(`publications/${data.users[0].id}`));
    assert.equal(after.state, "cancelled"); assert.equal(after.postId, schedule.postId);
    assert.deepEqual(JSON.parse(oauthFixture("publication-reservation", data.users[0].id)), {
      postId: schedule.postId, status: "atshde_reserve_hold", public: false, empty: true,
    });
    await unauthorized(token.access_token, endpoints.publishingEndpoint);
  });
}

test("legacy sfc keys retain their exact MCP endpoints, scopes and usability across OAuth grant revocation", async () => {
  const legacy = await json(await a.api("connections", { name: `${prefix}legacy` }));
  assert.match(legacy.token, /^sfc_[a-f0-9-]{36}_[a-f0-9]{64}$/);
  assert.equal(legacy.endpoint, endpoints.endpoint);
  const client = await connect(legacy.token, legacy.endpoint);
  const before = (await client.listTools()).tools.map(tool => tool.name).sort();
  const oauth = await issued();
  await json(await a.api(`connections/${oauth.grant.id}/revoke`, {}));
  assert.deepEqual((await client.listTools()).tools.map(tool => tool.name).sort(), before);
  const context = await good(client, "context");
  assert.equal(context.capabilities.createStructure, false); assert.equal(context.capabilities.publish, false);
  assert.equal((await call(client, "get-draft", { articleId: data.users[1].id })).raw.isError, true);
  noCredentials(await json(await a.api("connections")), [legacy.token, oauth.access_token, oauth.refresh_token]);
  usersFixture("publisher");
  const publishing = await json(await a.api("connections", { name: `${prefix}legacy-publisher`, publishing: true }));
  assert.equal(publishing.endpoint, endpoints.publishingEndpoint);
  const publisher = await connect(publishing.token, publishing.endpoint);
  assert.equal((await good(publisher, "context")).capabilities.publish, true);
});

for (const mode of ["cancel-at-admission", "grant-expiry"]) {
  test(`previously authorized OAuth publication preserves the ${mode} boundary`, async () => {
    usersFixture("publisher");
    const token = await issued({ client: { publishing: true }, params: { resource: endpoints.publishingEndpoint, scope: "sf:drafts sf:publish" } });
    const client = await connect(token.access_token, endpoints.publishingEndpoint);
    const own = await good(client, "get-draft", { articleId: data.users[0].id });
    const previous = await good(client, "get-publication", { articleId: data.users[0].id });
    await good(client, "set-publication", {
      articleId: data.users[0].id, expectedVersion: own.version, expectedPublicationVersion: previous.version,
      requestId: randomUUID(), timing: "scheduled", publishAt: new Date(Date.now() + 3600000).toISOString().replace(/\.\d{3}Z$/, "+00:00"), timeZone: "UTC",
    });
    usersFixture("publication-due", data.users[0].id);
    if (mode === "cancel-at-admission") {
      // Deterministic database barrier, not a claim of a multi-process stress test.
      assert.match(oauthFixture("publication-cas-cancel", data.users[0].id), /fixture-ok/);
      const result = await json(await a.api(`publications/${data.users[0].id}`));
      assert.equal(result.state, "cancelled"); assert.ok(result.postId > 0);
      assert.deepEqual(JSON.parse(oauthFixture("publication-reservation", data.users[0].id)), {
        postId: result.postId, status: "atshde_reserve_hold", public: false, empty: true,
      });
    } else {
      assert.match(oauthFixture("expire-grant", token.grant.id), /fixture-ok/);
      await unauthorized(token.access_token, endpoints.publishingEndpoint);
      usersFixture("publication-run", data.users[0].id);
      const result = await json(await a.api(`publications/${data.users[0].id}`));
      assert.equal(result.state, "published"); assert.ok(result.postId > 0);
    }
  });
}
