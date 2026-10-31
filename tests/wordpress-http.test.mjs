import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";

const origin = "http://127.0.0.1:8128";
const jar = new CookieJar();
async function request(path, options = {}, authenticated = true) {
  const url = new URL(path, origin);
  assert.equal(url.origin, origin);
  const response = await fetch(url, {
    redirect: "manual",
    ...options,
    headers: {
      ...(authenticated ? { Cookie: await jar.getCookieString(url.href) } : {}),
      ...options.headers,
    },
  });
  if (authenticated)
    for (const cookie of response.headers.getSetCookie())
      await jar.setCookie(cookie, url.href);
  return response;
}
await request("/wp-login.php");
const credentials = JSON.parse(
  await readFile(
    new URL("../.local/wp-credentials.json", import.meta.url),
    "utf8",
  ),
);
const login = await request("/wp-login.php", {
  method: "POST",
  body: new URLSearchParams({
    log: credentials.username,
    pwd: credentials.password,
    testcookie: "1",
    "wp-submit": "Log In",
    redirect_to: `${origin}/wp-admin/`,
  }),
});
assert.equal(login.status, 302, "Local test login must succeed");
const admin = await request("/wp-admin/admin.php?page=structured-fields");
const dom = new JSDOM(await admin.text());
const script = [...dom.window.document.scripts].find((el) =>
  el.textContent.trim().startsWith("window.sfWordPress="),
);
assert.ok(script, "Expected isolated plugin screen");
const configLine = script.textContent
  .split("\n")
  .map((line) => line.trim())
  .find((line) => line.startsWith("window.sfWordPress="));
const config = JSON.parse(configLine.slice("window.sfWordPress=".length, -1));
dom.window.close();
function api(path) {
  const url = new URL(config.root);
  if (url.searchParams.has("rest_route"))
    url.searchParams.set(
      "rest_route",
      url.searchParams.get("rest_route") + path,
    );
  else url.pathname += path;
  return url.href;
}
const headers = {
  "X-WP-Nonce": config.nonce,
  "Content-Type": "application/json",
};

test("cookie authentication requires a REST nonce; unauthenticated reads and writes fail", async () => {
  for (const authenticated of [false, true]) {
    const get = await request(api("articles"), {}, authenticated);
    assert.equal(get.status, 403);
    const post = await request(
      api("articles"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
      },
      authenticated,
    );
    assert.equal(post.status, 403);
  }
  const bad = await request(api("articles"), {
    headers: { "X-WP-Nonce": "invalid" },
  });
  assert.equal(bad.status, 403);
});

test("authenticated response is private, revision-bound, and contains no approval authority", async () => {
  const response = await request(api("articles"), { headers });
  assert.equal(response.status, 200);
  assert.match(response.headers.get("cache-control"), /no-store/);
  const items = await response.json();
  assert.ok(
    items.length > 0,
    "Load and save the local sample before this HTTP test",
  );
  for (const item of items) {
    assert.equal(item.publishAuthorized, false);
    const preview = new URL(item.previewUrl);
    assert.equal(preview.origin, origin);
    assert.equal(preview.searchParams.get("sf_version"), String(item.version));
  }
});

test("real theme preview needs authenticated user, exact version and valid nonce", async () => {
  const [item] = await (await request(api("articles"), { headers })).json();
  const success = await request(item.previewUrl);
  assert.equal(success.status, 200);
  const html = await success.text();
  assert.match(html, /sf-article/);
  assert.match(html, /wp-block-post-title|entry-title/);
  const previewDocument = new JSDOM(html);
  assert.match(
    previewDocument.window.document.querySelector("#sf-preview-inline-css")
      ?.textContent ?? "",
    /\.sf-article\{overflow-wrap:anywhere\}/,
  );
  previewDocument.window.close();
  assert.match(success.headers.get("cache-control"), /no-store/);
  assert.match(success.headers.get("x-robots-tag"), /noindex/);
  assert.match(
    success.headers.get("content-security-policy"),
    /form-action 'none'/,
  );
  const publicResult = await request(item.previewUrl, {}, false);
  assert.ok([403, 404].includes(publicResult.status));
  assert.doesNotMatch(await publicResult.text(), /class="sf-article"/);
  for (const [key, value] of [
    ["_sf_nonce", "invalid"],
    ["sf_version", "999"],
  ]) {
    const bad = new URL(item.previewUrl);
    bad.searchParams.set(key, value);
    assert.equal((await request(bad)).status, 403);
  }
  const bare = new URL(item.previewUrl);
  bare.searchParams.delete("_sf_nonce");
  assert.equal((await request(bare)).status, 403);
});

test("malformed preview tokens and array REST versions fail without coercion", async () => {
  const [item] = await (await request(api("articles"), { headers })).json();
  for (const key of ["sf_version", "_sf_nonce"]) {
    for (const suffix of ["[]", "[nested][]"]) {
      const url = new URL(item.previewUrl);
      const value = url.searchParams.get(key);
      url.searchParams.delete(key);
      url.searchParams.set(key + suffix, value);
      assert.equal((await request(url)).status, 403);
    }
    const tagged = new URL(item.previewUrl);
    tagged.searchParams.set(key, `<b>${tagged.searchParams.get(key)}</b>`);
    assert.equal((await request(tagged)).status, 403);
  }
  const url = new URL(api(`articles/${item.articleId}`));
  url.searchParams.set("version[]", "1");
  assert.equal((await request(url, { headers })).status, 400);
});

test("write API rejects unknown authority, content type and coerced expectedVersion", async () => {
  const snapshot = JSON.parse(
    await readFile(
      new URL("../wordpress/atshift-deft/sample.json", import.meta.url),
      "utf8",
    ),
  );
  for (const body of [
    { snapshot, expectedVersion: "1" },
    { snapshot, expectedVersion: 1, publish: true },
  ]) {
    assert.equal(
      (
        await request(api("articles"), {
          method: "POST",
          headers,
          body: JSON.stringify(body),
        })
      ).status,
      400,
    );
  }
  assert.equal(
    (
      await request(api("articles"), {
        method: "POST",
        headers: { ...headers, "Content-Type": "text/plain" },
        body: "{}",
      })
    ).status,
    415,
  );
  assert.equal(
    (await request(api("publish"), { method: "POST", headers, body: "{}" }))
      .status,
    404,
  );
});
