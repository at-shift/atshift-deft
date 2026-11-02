import { test, after } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { randomUUID, createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";
import { transferFile } from "../scripts/transfer-wordpress.mjs";

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
const dom = new JSDOM(
  await (await request("/wp-admin/admin.php?page=structured-fields")).text(),
);
const line = [...dom.window.document.scripts]
  .flatMap((s) => s.textContent.split("\n"))
  .map((s) => s.trim())
  .find((s) => s.startsWith("window.atshiftDeftWordPress="));
const config = JSON.parse(line.slice("window.atshiftDeftWordPress=".length, -1));
dom.window.close();
const headers = {
  "X-WP-Nonce": config.nonce,
  "Content-Type": "application/json",
};
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
const dir = await mkdtemp(`${tmpdir()}/sf-transfer-`);
after(async () => {
  assert.match(fixture("cleanup"), /fixture-ok/);
  await rm(dir, { recursive: true, force: true });
});
const data = JSON.parse(fixture("create"));
const bytes = Buffer.from(
  "%PDF-1.4\n% delegated transfer fixture\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
);
const file = `${dir}/transfer.pdf`;
await writeFile(file, bytes, { mode: 0o600 });
const meta = {
  filename: "transfer.pdf",
  mime: "application/pdf",
  bytes: bytes.length,
  sha256: createHash("sha256").update(bytes).digest("hex"),
  purpose: "publication",
};
async function issue(expectedVersion = 1, overrides = {}) {
  return request(api(`articles/${id}/transfers`), {
    method: "POST",
    headers,
    body: JSON.stringify({ expectedVersion, ...meta, ...overrides }),
  });
}
async function upload(
  grant,
  payload = bytes,
  purpose = "publication",
  extra = {},
) {
  const body = new FormData();
  body.append("purpose", purpose);
  body.append("file", new Blob([payload], { type: meta.mime }), meta.filename);
  return request(
    grant.uploadUrl,
    {
      method: "POST",
      headers: { "X-SF-Transfer-Token": grant.token, ...extra },
      body,
    },
    false,
  );
}
async function save(snapshot, expectedVersion) {
  return request(api("articles"), {
    method: "POST",
    headers,
    body: JSON.stringify({ snapshot, expectedVersion }),
  });
}
async function review(version) {
  return request(api(`articles/${id}/reviews`), {
    method: "POST",
    headers,
    body: JSON.stringify({ expectedVersion: version }),
  });
}
async function state(record) {
  const r = await request(api(`reviews/${record.reviewId}`), { headers });
  assert.equal(r.status, 200);
  return r.json();
}
async function form(record) {
  const r = await request(record.reviewUrl);
  assert.equal(r.status, 200);
  const dom = new JSDOM(await r.text());
  const fields = new URLSearchParams();
  for (const input of dom.window.document.querySelectorAll("form input"))
    fields.set(input.name, input.value);
  dom.window.close();
  return fields;
}
async function decide(
  fields,
  decision = "approved",
  authenticated = true,
  overrides = {},
) {
  const body = new URLSearchParams(fields);
  body.set("decision", decision);
  return request(
    "/wp-admin/admin-post.php",
    { method: "POST", body, ...overrides },
    authenticated,
  );
}
let grant, receipt, snapshot, pending, fields;
test("grant issuance requires privileged authentication and strict fixed-file metadata", async () => {
  assert.equal(
    (
      await request(
        api(`articles/${id}/transfers`),
        { method: "POST", headers, body: "{}" },
        false,
      )
    ).status,
    403,
  );
  for (const override of [
    { purpose: "approve" },
    { bytes: "10" },
    { sha256: "bad" },
    { filename: "../x.pdf" },
    { mime: "text/html" },
    { approved: true },
  ])
    assert.equal((await issue(1, override)).status, 400);
  assert.equal((await issue(99)).status, 409);
  const r = await issue();
  assert.equal(r.status, 200);
  grant = await r.json();
  assert.equal(grant.publishAuthorized, false);
  assert.match(r.headers.get("cache-control"), /no-store/);
  assert.deepEqual(JSON.parse(fixture("grant-hashes")), [
    createHash("sha256").update(grant.token).digest("hex"),
  ]);
});
test("delegated upload checks bytes and purpose without a login or broader privileges", async () => {
  assert.equal((await upload({ ...grant, token: "0".repeat(64) })).status, 403);
  assert.equal(
    (await upload(grant, Buffer.concat([bytes, Buffer.from("changed")])))
      .status,
    400,
  );
  assert.equal((await upload(grant, bytes, "reference")).status, 400);
  const r = await upload(grant);
  assert.equal(r.status, 200);
  receipt = await r.json();
  assert.equal(receipt.sha256, meta.sha256);
  assert.equal(receipt.articleId, id);
  assert.equal(receipt.contentUrl, undefined);
  assert.equal(receipt.publishAuthorized, false);
  assert.equal(
    (
      await request(
        api(`articles/${id}/assets`),
        { headers: { "X-SF-Transfer-Token": grant.token } },
        false,
      )
    ).status,
    403,
  );
  assert.equal(
    (
      await request(
        api(`articles/${id}/reviews`),
        {
          method: "POST",
          headers: {
            "X-SF-Transfer-Token": grant.token,
            "Content-Type": "application/json",
          },
          body: '{"expectedVersion":1}',
        },
        false,
      )
    ).status,
    403,
  );
});
test("CLI transfer uses an explicit destination, verifies local bytes, and retries idempotently", async () => {
  await assert.rejects(
    transferFile(grant, file, "https://wrong.example"),
    /destination/,
  );
  await writeFile(
    `${dir}/changed.pdf`,
    Buffer.concat([bytes, Buffer.from("changed")]),
    { mode: 0o600 },
  );
  await assert.rejects(
    transferFile(grant, `${dir}/changed.pdf`, origin),
    /size/,
  );
  assert.equal(
    (await transferFile(grant, file, origin)).assetId,
    receipt.assetId,
  );
});
test("revocation and expiry reject uploads without deleting the retained file", async () => {
  const g = await (await issue()).json();
  assert.equal(
    (
      await request(api(`transfers/${g.transferId}/revoke`), {
        method: "POST",
        headers,
        body: "{}",
      })
    ).status,
    200,
  );
  assert.equal((await upload(g)).status, 403);
  fixture("expire-transfers");
  assert.equal((await upload(grant)).status, 403);
  const r = await request(api(`articles/${id}/assets`), { headers });
  assert.ok((await r.json()).assets.some((a) => a.assetId === receipt.assetId));
  grant = await (await issue()).json();
});
test("save binds transferred files; old-version transfer authority is invalidated", async () => {
  snapshot = structuredClone(data.snapshot);
  snapshot.article.revision = 2;
  snapshot.article.blocks[0].values.image = {
    value: "",
    alt: "Review image",
    assetRef: data.attachments.image,
  };
  snapshot.article.blocks[0].values.document = {
    value: "",
    alt: "Download reviewed PDF",
    assetRef: { kind: "staged", id: receipt.assetId, sha256: receipt.sha256 },
  };
  assert.equal((await save(snapshot, 1)).status, 200);
  assert.equal((await upload(grant)).status, 409);
});
test("review request pins the exact version and only managed output assets", async () => {
  assert.equal((await review(1)).status, 409);
  const r = await review(2);
  assert.equal(r.status, 200);
  pending = await r.json();
  assert.equal(pending.state, "pending");
  assert.equal(pending.manifest.files.length, 2);
  assert.ok(
    !JSON.stringify(pending.manifest).includes(data.staged.reference.id),
  );
  assert.equal((await (await review(2)).json()).reviewId, pending.reviewId);
  fields = await form(pending);
  assert.equal((await state(pending)).state, "pending");
  assert.equal(pending.humanPresenceVerified, false);
  assert.equal(pending.publishAuthorized, false);
});
test("malformed review form values cannot change the pending decision", async () => {
  for (const key of ["review", "decision", "_wpnonce"]) {
    for (const malformed of ["array", "markup", "missing"]) {
      const body = new URLSearchParams(fields);
      body.set("decision", "approved");
      const value = body.get(key);
      body.delete(key);
      if (malformed === "array") body.set(key + "[]", value);
      if (malformed === "markup") body.set(key, `<b>${value}</b>`);
      assert.equal((await request("/wp-admin/admin-post.php", { method: "POST", body })).status, 403);
      assert.equal((await state(pending)).state, "pending");
    }
  }
  const url = new URL(pending.reviewUrl);
  url.searchParams.delete("review");
  url.searchParams.set("review[]", pending.reviewId);
  assert.equal((await request(url)).status, 403);
});
test("read-only review pages require ownership without a decision nonce and send privacy headers", async () => {
  const before = await state(pending);
  const response = await request(pending.reviewUrl);
  assert.equal(response.status, 200);
  assert.match(response.headers.get("cache-control"), /no-store/);
  assert.equal(response.headers.get("referrer-policy"), "no-referrer");
  assert.equal(response.headers.get("x-robots-tag"), "noindex, nofollow, noarchive");
  const ordinary = await request("/wp-admin/admin.php?page=structured-fields");
  assert.equal(ordinary.headers.get("referrer-policy"), "strict-origin-when-cross-origin");
  for (const page of ["structured-fields-review.php", "structured-fields.php-review", "structured-fields-review.php.php"]) {
    const url = new URL(pending.reviewUrl); url.searchParams.set("page", page);
    const alias = await request(url);
    assert.equal(alias.status, 200, page);
    assert.equal(alias.headers.get("referrer-policy"), "no-referrer", page);
    assert.equal(alias.headers.get("x-robots-tag"), "noindex, nofollow, noarchive", page);
    assert.match(alias.headers.get("cache-control"), /no-store/);
    assert.ok((await alias.text()).includes(pending.manifestHash));
  }
  const dom = new JSDOM(await response.text());
  assert.equal(dom.window.document.querySelector("#sf-review-screen h1").textContent, pending.manifest.title);
  assert.ok(dom.window.document.querySelector('input[name="_wpnonce"]').value);
  dom.window.close();
  const anonymous = await request(pending.reviewUrl, {}, false);
  assert.equal(anonymous.status, 302);
  assert.match(anonymous.headers.get("location"), /wp-login\.php/);
  for (const value of ["", "missing", randomUUID(), `<b>${pending.reviewId}</b>`, `${pending.reviewId}\u0000`]) {
    const url = new URL(pending.reviewUrl);
    url.searchParams.set("review", value);
    const denied = await request(url);
    assert.equal(denied.status, 403);
    assert.ok(!(await denied.text()).includes(pending.manifestHash));
  }
  assert.deepEqual(await state(pending), before);
});
test("review decisions reject non-POST requests and credential headers without changing state", async () => {
  const before = await state(pending);
  const query = new URLSearchParams(fields);
  query.set("decision", "approved");
  const url = `/wp-admin/admin-post.php?${query}`;
  for (const method of ["GET", "HEAD", "PUT", "PATCH", "DELETE"]) {
    assert.equal((await request(url, { method })).status, 405, method);
  }
  // Core send_origin_headers() handles preflight before the plugin handler.
  assert.equal((await request(url, { method: "OPTIONS" })).status, 403);
  // Core's default allowed_origins list uses the host without the local test port.
  assert.equal((await request(url, { method: "OPTIONS", headers: { Origin: origin } })).status, 403);
  const allowedOrigin = new URL(origin); allowedOrigin.port = "";
  const preflight = await request(url, { method: "OPTIONS", headers: { Origin: allowedOrigin.origin } });
  assert.equal(preflight.status, 200); assert.equal(await preflight.text(), "");
  assert.equal((await request("/wp-admin/admin-post.php?action=sf_decide", {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(Object.fromEntries(query)),
  })).status, 403);
  for (const authorization of ["Bearer invalid", `Basic ${Buffer.from("unused:unused").toString("base64")}`]) {
    assert.equal((await decide(fields, "approved", true, { headers: { Authorization: authorization } })).status, 403);
  }
  assert.deepEqual(await state(pending), before);
});
test("approval requires a cookie session and exact review nonce, never a transfer token", async () => {
  assert.equal(
    (
      await decide(fields, "approved", false, {
        headers: { "X-SF-Transfer-Token": grant.token },
      })
    ).status,
    403,
  );
  const bad = new URLSearchParams(fields);
  bad.set("_wpnonce", "bad");
  assert.equal((await decide(bad)).status, 403);
  assert.equal(
    (
      await request(api(`reviews/${pending.reviewId}`), {
        method: "POST",
        headers,
        body: '{"decision":"approved"}',
      })
    ).status,
    404,
  );
  assert.equal((await state(pending)).state, "pending");
  assert.equal((await decide(fields)).status, 302);
  const approved = await state(pending);
  assert.equal(approved.state, "approved");
  assert.ok(approved.approvedBy > 0);
  assert.equal(approved.publishAuthorized, false);
  assert.equal(approved.humanPresenceVerified, false);
  assert.equal((await decide(fields)).status, 302);
  assert.equal((await (await review(2)).json()).reviewId, pending.reviewId);
});
test("review forms retain multipart support and POST values override conflicting query values", async () => {
  const before = await state(pending);
  assert.equal((await decide(fields, "revoked")).status, 302);
  pending = await (await review(2)).json();
  assert.notEqual(pending.reviewId, before.reviewId);
  assert.equal((await state(pending)).state, "pending");
  fields = await form(pending);
  const body = new FormData();
  for (const [key, value] of fields) if (key !== "action") body.set(key, value);
  body.set("decision", "approved");
  const query = new URLSearchParams({ action: "sf_decide", review: randomUUID(), decision: "revoked", _wpnonce: "invalid" });
  assert.equal((await request(`/wp-admin/admin-post.php?${query}`, { method: "POST", body })).status, 302);
  const approved = await state(pending);
  assert.equal(approved.state, "approved");
  assert.equal(approved.approvedBy, before.approvedBy);
  assert.equal(approved.manifestHash, before.manifestHash);
  assert.equal((await request(`/wp-admin/admin-post.php?${query}`, { method: "POST", body })).status, 302);
  assert.deepEqual(await state(pending), approved);
});
test("a new article revision invalidates approval and rejects the old confirmation form", async () => {
  snapshot.article.revision = 3;
  snapshot.article.blocks[0].values.image.alt = "Changed description";
  assert.equal((await save(snapshot, 2)).status, 200);
  assert.equal((await state(pending)).state, "invalidated");
  assert.equal((await decide(fields)).status, 409);
  pending = await (await review(3)).json();
  fields = await form(pending);
  fixture("expire-reviews");
  assert.equal((await state(pending)).state, "expired");
  assert.equal((await decide(fields)).status, 409);
});
test("approval can be withdrawn, and old approval forms cannot restore it", async () => {
  pending = await (await review(3)).json();
  fields = await form(pending);
  assert.equal((await decide(fields)).status, 302);
  const before = await state(pending);
  assert.equal((await decide(fields, "revoked")).status, 302);
  const withdrawn = await state(pending);
  assert.equal(withdrawn.state, "revoked");
  assert.equal(withdrawn.approvedBy, before.approvedBy);
  assert.equal(withdrawn.decidedAt, before.decidedAt);
  assert.ok(withdrawn.withdrawnBy > 0 && withdrawn.withdrawnAt > 0);
  assert.equal((await decide(fields)).status, 409);
});
test("insecure remote transport is rejected; only HTTPS or strict local HTTP qualifies", () => {
  const root = fileURLToPath(new URL('../.local/wordpress/wp-load.php', import.meta.url));
  const code = `require ${JSON.stringify(root)}; $_SERVER['REMOTE_ADDR']='192.0.2.1'; $a=Atshift\\Deft\\Transfer::secure_transport(); $_SERVER['REMOTE_ADDR']='127.0.0.1'; $b=Atshift\\Deft\\Transfer::secure_transport(); $_SERVER['REMOTE_ADDR']='192.0.2.1'; $_SERVER['HTTPS']='on'; echo json_encode(array($a,$b,Atshift\\Deft\\Transfer::secure_transport()));`;
  assert.deepEqual(JSON.parse(execFileSync('php', ['-r', code], {encoding:'utf8'})), [false,true,true]);
});
test("request changes is explicit; modified file bytes invalidate a fresh approval", async () => {
  pending = await (await review(3)).json();
  fields = await form(pending);
  assert.equal((await decide(fields, "rejected")).status, 302);
  assert.equal((await state(pending)).state, "rejected");
  pending = await (await review(3)).json();
  fields = await form(pending);
  assert.equal((await decide(fields)).status, 302);
  assert.equal((await state(pending)).state, "approved");
  fixture("tamper");
  assert.equal((await state(pending)).state, "invalidated");
  assert.equal((await review(3)).status, 400);
});
test("unmanaged URL images and CSS resources cannot receive byte-pinned approval", async () => {
  snapshot.article.revision = 4;
  snapshot.article.blocks[0].values.image = {
    value: "/assets/atelier.png",
    alt: "Unpinned image",
  };
  assert.equal((await save(snapshot, 3)).status, 200);
  assert.equal((await review(4)).status, 400);
  snapshot.article.revision = 5;
  snapshot.article.blocks[0].values.image = { value: "", alt: "" };
  const component = snapshot.components[0];
  delete component.revision;
  component.css += " .sf-resources{background-image:url(/assets/atelier.png)}";
  component.revision = createHash("sha256")
    .update(JSON.stringify(component))
    .digest("hex");
  snapshot.article.blocks[0].componentRevision = component.revision;
  assert.equal((await save(snapshot, 4)).status, 200);
  assert.equal((await review(5)).status, 400);
});
