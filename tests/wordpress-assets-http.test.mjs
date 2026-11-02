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
  await readFile(
    new URL("../.local/wp-credentials.json", import.meta.url),
    "utf8",
  ),
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
const dom = new JSDOM(
  await (await request("/wp-admin/admin.php?page=structured-fields")).text(),
);
const configLine = [...dom.window.document.scripts]
  .flatMap((el) => el.textContent.split("\n"))
  .map((line) => line.trim())
  .find((line) => line.startsWith("window.atshiftDeftWordPress="));
const config = JSON.parse(configLine.slice("window.atshiftDeftWordPress=".length, -1));
dom.window.close();
function api(path) {
  const url = new URL(config.root);
  if (url.searchParams.has("rest_route"))
    url.searchParams.set(
      "rest_route",
      url.searchParams.get("rest_route") + path,
    );
  else url.pathname += path;
  return url;
}
const headers = { "X-WP-Nonce": config.nonce };
const id = `asset-test-${randomUUID()}`;
const snapshot = JSON.parse(
  await readFile(
    new URL("../wordpress/atshift-deft/sample.json", import.meta.url),
    "utf8",
  ),
);
snapshot.article.id = id;
snapshot.article.title = "Private asset test";
const route = api(`articles/${id}/assets`);
async function attachmentCount() {
  const response = await request(
    `/index.php?rest_route=/wp/v2/media&context=edit&per_page=1&_fields=id`,
    { headers },
  );
  assert.equal(response.status, 200);
  return Number(response.headers.get("x-wp-total"));
}
const originalAttachmentCount = await attachmentCount();
function fixture(action) {
  const output = execFileSync(
    "php",
    [
      fileURLToPath(new URL("./wordpress-assets-fixture.php", import.meta.url)),
      action,
      id,
    ],
    { encoding: "utf8" },
  );
  assert.match(
    output,
    /fixture-ok/,
    "Isolated fixture action must actually complete",
  );
}
after(() => fixture("cleanup"));
assert.equal(
  (
    await request(api("articles"), {
      method: "POST",
      headers: { ...headers, "Content-Type": "application/json" },
      body: JSON.stringify({ snapshot, expectedVersion: 0 }),
    })
  ).status,
  200,
);
const png = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=",
  "base64",
);
const pdf = Buffer.from(
  "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
);
function form(bytes = png, filename = "photo.png", purpose) {
  const body = new FormData();
  body.set("file", new Blob([bytes]), filename);
  if (purpose !== undefined) body.set("purpose", purpose);
  return body;
}
async function upload(bytes, filename, purpose) {
  return request(route, {
    method: "POST",
    headers,
    body: form(bytes, filename, purpose),
  });
}
async function listing() {
  return (await request(route, { headers })).json();
}
let reference, publication, document;

test("private intake requires login and REST nonce for reads and uploads", async () => {
  for (const authenticated of [false, true]) {
    assert.equal((await request(route, {}, authenticated)).status, 403);
    assert.equal(
      (await request(route, { method: "POST", body: form() }, authenticated))
        .status,
      403,
    );
  }
  assert.equal(
    (
      await request(route, {
        method: "POST",
        headers: { "X-WP-Nonce": "bad" },
        body: form(),
      })
    ).status,
    403,
  );
  assert.equal((await listing()).assets.length, 0);
});
test("omitted purpose defaults to reference; identical retry retains stable asset ID", async () => {
  const response = await upload();
  assert.equal(response.status, 200);
  reference = await response.json();
  assert.equal(reference.purpose, "reference");
  assert.equal(reference.state, "private-staged");
  assert.equal(reference.publishAuthorized, false);
  assert.equal(
    reference.sha256,
    createHash("sha256").update(png).digest("hex"),
  );
  const retry = await upload(png, "renamed.png");
  assert.equal((await retry.json()).assetId, reference.assetId);
  assert.equal((await listing()).assets.length, 1);
});
test("publication intent stays private and never promotes a reference asset", async () => {
  const before = await listing();
  publication = await (await upload(png, "photo.png", "publication")).json();
  assert.notEqual(publication.assetId, reference.assetId);
  assert.equal(publication.publishAuthorized, false);
  assert.equal(publication.state, "private-staged");
  const after = await listing();
  assert.notEqual(after.manifestDigest, before.manifestDigest);
  assert.equal(after.articleDigest, before.articleDigest);
  assert.equal(after.articleVersion, 1);
  assert.equal(
    after.assets.find((x) => x.assetId === reference.assetId).purpose,
    "reference",
  );
});
test("file bytes require authorized session plus exact nonce and stay noncacheable", async () => {
  const good = await request(reference.contentUrl);
  assert.equal(good.status, 200);
  assert.deepEqual(Buffer.from(await good.arrayBuffer()), png);
  assert.match(good.headers.get("cache-control"), /no-store/);
  assert.equal(good.headers.get("x-content-type-options"), "nosniff");
  assert.match(good.headers.get("content-security-policy"), /sandbox/);
  assert.match(good.headers.get("content-disposition"), /^inline;/);
  assert.equal((await request(reference.contentUrl, {}, false)).status, 403);
  for (const [key, value] of [
    ["_sf_nonce", "bad"],
    ["asset", publication.assetId],
    ["asset", "../../wp-config.php"],
  ]) {
    const bad = new URL(reference.contentUrl);
    bad.searchParams.set(key, value);
    assert.equal((await request(bad)).status, 403);
  }
  const missing = new URL(reference.contentUrl);
  missing.searchParams.delete("_sf_nonce");
  assert.equal((await request(missing)).status, 403);
});
test("PDF is a private download, never inline executable preview", async () => {
  const response = await upload(pdf, "brief.pdf");
  assert.equal(response.status, 200);
  document = await response.json();
  const download = await request(document.contentUrl);
  assert.equal(download.status, 200);
  assert.equal(download.headers.get("content-type"), "application/pdf");
  assert.match(download.headers.get("content-disposition"), /^attachment;/);
  assert.deepEqual(Buffer.from(await download.arrayBuffer()), pdf);
  const head = await request(document.contentUrl, { method: "HEAD" });
  assert.equal(head.status, 200);
  assert.equal(head.headers.get("content-type"), "application/pdf");
  assert.equal(head.headers.get("content-length"), String(pdf.length));
  assert.equal((await head.arrayBuffer()).byteLength, 0);
});
test("asset endpoints reject arrays and altered tokens instead of normalizing them", async () => {
  for (const key of ["asset", "_sf_nonce"]) {
    const array = new URL(reference.contentUrl);
    const value = array.searchParams.get(key);
    array.searchParams.delete(key);
    array.searchParams.set(key + "[]", value);
    assert.equal((await request(array)).status, 403);
    for (const altered of ["", `<b>${value}</b>`, ` ${value}`, `${value}\n`, `${value}%00`, `${value}\0`, `${value}\\`]) {
      const bad = new URL(reference.contentUrl);
      bad.searchParams.set(key, altered);
      assert.equal((await request(bad)).status, 403, `${key}: ${JSON.stringify(altered)}`);
    }
  }
  assert.equal((await request(reference.contentUrl)).status, 200);
});
test("spoofed extensions, active types, empty files and malformed envelopes leave no assets", async () => {
  const before = await listing();
  for (const [bytes, name, purpose] of [
    [Buffer.from("<script>x</script>"), "fake.png"],
    [png, "fake.pdf"],
    [pdf, "fake.jpg"],
    [png, "photo.svg"],
    [Buffer.alloc(0), "empty.pdf"],
    [png, "photo.png", "publish-now"],
  ]) {
    assert.equal((await upload(bytes, name, purpose)).status, 400, name);
  }
  const extra = form();
  extra.set("publish", "true");
  assert.equal(
    (await request(route, { method: "POST", headers, body: extra })).status,
    400,
  );
  const multi = form();
  multi.set("other", new Blob([png]), "other.png");
  assert.equal(
    (await request(route, { method: "POST", headers, body: multi })).status,
    400,
  );
  assert.equal(
    (
      await request(api("articles/unsaved-asset-test/assets"), {
        method: "POST",
        headers,
        body: form(),
      })
    ).status,
    404,
  );
  assert.equal((await listing()).manifestDigest, before.manifestDigest);
});
test("attachment library remains unchanged and assets are not served at public upload paths", async () => {
  assert.equal(await attachmentCount(), originalAttachmentCount);
  for (const item of [reference, publication, document]) {
    assert.equal(item.attachmentId, undefined);
    assert.equal(item.url, undefined);
    assert.equal(
      (await request(`/wp-content/uploads/${item.assetId}.bin`, {}, false))
        .status,
      404,
    );
  }
});
test("tampered storage fails closed; quotas reject new files without changing manifest", async () => {
  fixture("tamper");
  const list = await listing();
  const results = await Promise.all(
    list.assets.map(async (item) => (await request(item.contentUrl)).status),
  );
  assert.ok(results.includes(503));
  fixture("quota");
  const response = await upload(
    Buffer.concat([pdf, Buffer.from("\n%another\n")]),
    "another.pdf",
  );
  assert.equal(response.status, 413);
  assert.equal((await listing()).manifestDigest, list.manifestDigest);
});
