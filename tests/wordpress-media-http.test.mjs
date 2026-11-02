import { test, after } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { randomUUID, createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";

const origin = "http://127.0.0.1:8128";
const jar = new CookieJar();
async function request(path, options = {}, authenticated = true) {
  const url = new URL(path, origin);
  assert.equal(url.origin, origin);
  const response = await fetch(url, {
    ...options,
    redirect: "manual",
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
  await readFile(new URL("../.local/wp-credentials.json", import.meta.url)),
);
assert.equal(
  (
    await request("/wp-login.php", {
      method: "POST",
      body: new URLSearchParams({
        log: credentials.username,
        pwd: credentials.password,
        testcookie: "1",
        "wp-submit": "Log In",
        redirect_to: `${origin}/wp-admin/`,
      }),
    })
  ).status,
  302,
);
const admin = new JSDOM(
  await (await request("/wp-admin/admin.php?page=structured-fields")).text(),
);
const line = [...admin.window.document.scripts]
  .flatMap((s) => s.textContent.split("\n"))
  .map((s) => s.trim())
  .find((s) => s.startsWith("window.atshiftDeftWordPress="));
const config = JSON.parse(line.slice("window.atshiftDeftWordPress=".length, -1));
admin.window.close();
const headers = {
  "X-WP-Nonce": config.nonce,
  "Content-Type": "application/json",
};
function api(path, query = {}) {
  const url = new URL(config.root);
  if (url.searchParams.has("rest_route"))
    url.searchParams.set(
      "rest_route",
      url.searchParams.get("rest_route") + path,
    );
  else url.pathname += path;
  for (const [key, value] of Object.entries(query))
    url.searchParams.set(key, value);
  return url;
}
const id = `media-test-${randomUUID()}`;
function fixture(action) {
  return execFileSync(
    "php",
    [
      fileURLToPath(new URL("./wordpress-media-fixture.php", import.meta.url)),
      action,
      id,
    ],
    { encoding: "utf8" },
  );
}
after(() => assert.match(fixture("cleanup"), /fixture-ok/));
const data = JSON.parse(fixture("create"));
const snapshot = data.snapshot;
const values = snapshot.article.blocks[0].values;
for (const key of ["image", "document"])
  values[key] = {
    value: "",
    alt:
      key === "image"
        ? "Article-specific description"
        : "Download application PDF",
    assetRef: data.staged[key],
  };
snapshot.article.revision = 2;
async function save(value, expectedVersion) {
  return request(api("articles"), {
    method: "POST",
    headers,
    body: JSON.stringify({ snapshot: value, expectedVersion }),
  });
}
async function mediaUrls(saved) {
  const response = await request(saved.previewUrl);
  assert.equal(response.status, 200);
  const dom = new JSDOM(await response.text());
  const image = dom.window.document.querySelector(".sf-article img");
  const link = dom.window.document.querySelector(".sf-article a");
  assert.equal(image.alt, "Article-specific description");
  assert.equal(link.textContent, "Download application PDF");
  assert.equal(link.hasAttribute("download"), true);
  const result = {
    image: image.getAttribute("src"),
    document: link.getAttribute("href"),
  };
  dom.window.close();
  return result;
}
let saved, urls;
test("media inventory requires session and nonce; IDs are read-only", async () => {
  assert.equal((await request(api("media"))).status, 403);
  assert.equal((await request(api("media"), { headers }, false)).status, 403);
  assert.equal(
    (await request(api("media"), { headers, method: "POST", body: "{}" }))
      .status,
    404,
  );
  const response = await request(api("media", { search: id }), { headers });
  assert.equal(response.status, 200);
  assert.match(response.headers.get("cache-control"), /no-store/);
  const items = (await response.json()).items;
  assert.equal(items.length, 2);
  assert.ok(items.every((item) => item.existingWordPressMedia && !item.path));
  const read = await request(api(`media/${data.attachments.image.id}`), {
    headers,
  });
  assert.deepEqual((await read.json()).assetRef, data.attachments.image);
});
test("save and render real image/PDF references without storing transient URLs", async () => {
  const response = await save(snapshot, 1);
  assert.equal(response.status, 200);
  saved = await response.json();
  assert.equal(saved.publishAuthorized, false);
  assert.deepEqual(
    saved.snapshot.article.blocks[0].values.image.assetRef,
    data.staged.image,
  );
  assert.doesNotMatch(
    JSON.stringify(saved.snapshot),
    /admin-post|_sf_nonce|contentUrl/,
  );
  urls = await mediaUrls(saved);
  for (const key of ["image", "document"]) {
    const file = await request(urls[key]);
    assert.equal(file.status, 200);
    const bytes = Buffer.from(await file.arrayBuffer());
    assert.equal(
      createHash("sha256").update(bytes).digest("hex"),
      data.staged[key].sha256,
    );
    assert.match(file.headers.get("cache-control"), /no-store/);
    assert.equal(file.headers.get("x-content-type-options"), "nosniff");
    assert.match(
      file.headers.get("content-disposition"),
      key === "image" ? /^inline/ : /^attachment/,
    );
  }
});
test("copied URLs and forged snapshot/version/key/nonce cannot read media", async () => {
  for (const url of Object.values(urls))
    assert.equal((await request(url, {}, false)).status, 403);
  for (const [key, value] of [
    ["version", "1"],
    ["article", "another-article"],
    ["key", "0".repeat(64)],
    ["_sf_nonce", "bad"],
  ]) {
    const url = new URL(urls.image);
    url.searchParams.set(key, value);
    assert.equal((await request(url)).status, 403);
  }
  assert.equal((await request(saved.previewUrl, {}, false)).status, 403);
});
test("media parameters reject arrays and altered tokens without repairing them", async () => {
  for (const key of ["article", "version", "key", "_sf_nonce"]) {
    const array = new URL(urls.image);
    const value = array.searchParams.get(key);
    array.searchParams.delete(key);
    array.searchParams.set(key + "[nested][]", value);
    assert.equal((await request(array)).status, 403);
    for (const altered of ["", `<b>${value}</b>`, ` ${value}`, `${value}\n`, `${value}%00`, `${value}\0`, `${value}\\`]) {
      const bad = new URL(urls.image);
      bad.searchParams.set(key, altered);
      assert.equal((await request(bad)).status, 403, `${key}: ${JSON.stringify(altered)}`);
    }
  }
  assert.equal((await request(urls.image)).status, 200);
});
test("reference-only files and cross-article references never enter rendered output", async () => {
  const bad = structuredClone(snapshot);
  bad.article.revision = 3;
  bad.article.blocks[0].values.document.assetRef = data.staged.reference;
  assert.equal((await save(bad, 2)).status, 400);
  bad.article.blocks[0].values.document.assetRef = data.staged.document;
  bad.article.id = "different-article";
  assert.equal((await save(bad, 0)).status, 400);
  const current = await (
    await request(api(`articles/${id}`), { headers })
  ).json();
  assert.equal(current.version, 2);
});
test("existing WordPress media reuses IDs; old snapshot links still resolve", async () => {
  snapshot.article.revision = 3;
  for (const key of ["image", "document"])
    values[key].assetRef = data.attachments[key];
  const response = await save(snapshot, 2);
  assert.equal(response.status, 200);
  const next = await response.json();
  assert.equal(next.version, 3);
  const existingUrls = await mediaUrls(next);
  for (const key of ["image", "document"]) {
    const bytes = Buffer.from(
      await (await request(existingUrls[key])).arrayBuffer(),
    );
    assert.equal(
      createHash("sha256").update(bytes).digest("hex"),
      data.attachments[key].sha256,
    );
    assert.equal((await request(urls[key])).status, 200);
  }
  assert.match(fixture("tamper"), /fixture-ok/);
  assert.equal((await request(next.previewUrl)).status, 503);
  assert.equal((await request(existingUrls.image)).status, 503);
  assert.equal((await request(existingUrls.document)).status, 503);
  assert.equal((await request(saved.previewUrl)).status, 200);
});
