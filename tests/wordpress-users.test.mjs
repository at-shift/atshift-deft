import { test, after } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { execFile, execFileSync } from "node:child_process";
import { promisify } from "node:util";
import { fileURLToPath } from "node:url";
import { randomUUID, createHash } from "node:crypto";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";

const origin = "http://127.0.0.1:8128";
const run = randomUUID();
const fixture = (action) =>
  execFileSync(
    "php",
    [
      fileURLToPath(new URL("./wordpress-users-fixture.php", import.meta.url)),
      action,
      run,
    ],
    { encoding: "utf8" },
  );
after(() => assert.match(fixture("cleanup"), /fixture-ok/));
const data = JSON.parse(fixture("create"));
async function client(credentials) {
  const jar = new CookieJar();
  const request = async (path, options = {}) => {
    const url = new URL(path, origin);
    assert.equal(url.origin, origin);
    const r = await fetch(url, {
      ...options,
      redirect: "manual",
      headers: {
        Cookie: await jar.getCookieString(url.href),
        ...options.headers,
      },
    });
    for (const c of r.headers.getSetCookie()) await jar.setCookie(c, url.href);
    return r;
  };
  await request("/wp-login.php");
  assert.equal(
    (
      await request("/wp-login.php", {
        method: "POST",
        body: new URLSearchParams({
          log: credentials.username,
          pwd: credentials.password,
          testcookie: "1",
          redirect_to: `${origin}/wp-admin/`,
        }),
      })
    ).status,
    302,
  );
  const r = await request("/wp-admin/admin.php?page=structured-fields");
  assert.equal(r.status, 200);
  const dom = new JSDOM(await r.text());
  const line = [...dom.window.document.scripts]
    .flatMap((s) => s.textContent.split("\n"))
    .map((s) => s.trim())
    .find((s) => s.startsWith("window.sfWordPress="));
  assert.ok(line, "Workspace loads for this role");
  const config = JSON.parse(line.slice("window.sfWordPress=".length, -1));
  dom.window.close();
  const api = async (
    path,
    body,
    method = body === undefined ? "GET" : "POST",
  ) => {
    const url = new URL(config.root);
    const [route, query] = path.split("?");
    if (url.searchParams.has("rest_route"))
      url.searchParams.set(
        "rest_route",
        url.searchParams.get("rest_route") + route,
      );
    else url.pathname += route;
    for (const [key, value] of new URLSearchParams(query))
      url.searchParams.set(key, value);
    return request(url, {
      method,
      headers: {
        "X-WP-Nonce": config.nonce,
        ...(body !== undefined && !(body instanceof FormData)
          ? { "Content-Type": "application/json" }
          : {}),
      },
      ...(body === undefined
        ? {}
        : { body: body instanceof FormData ? body : JSON.stringify(body) }),
    });
  };
  return { request, api };
}
const [a, b] = [await client(data.users[0]), await client(data.users[1])];
const admin = await client(
  JSON.parse(
    await readFile(new URL("../.local/wp-credentials.json", import.meta.url)),
  ),
);
const [aid, bid] = data.users.map((u) => u.id);
const save = (c, snapshot, expectedVersion) =>
  c.api("articles", { snapshot, expectedVersion });
const json = async (r) => {
  const result = await r.json();
  assert.equal(r.status, 200, result.message);
  return result;
};
const bytes = Buffer.from(
  "%PDF-1.4\n% multiuser exact file\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
);
const meta = {
  filename: "own.pdf",
  mime: "application/pdf",
  bytes: bytes.length,
  sha256: createHash("sha256").update(bytes).digest("hex"),
  purpose: "publication",
};
function uploadBody() {
  const form = new FormData();
  form.set("purpose", "publication");
  form.set("file", new Blob([bytes], { type: meta.mime }), meta.filename);
  return form;
}
let own, receipt, grant, review;

test("separate staff and admin lists only show the current WordPress owner's drafts", async () => {
  assert.deepEqual(
    (await json(await a.api("articles"))).map((x) => x.articleId),
    [aid],
  );
  assert.deepEqual(
    (await json(await b.api("articles"))).map((x) => x.articleId),
    [bid],
  );
  const rows = await json(await admin.api("articles"));
  assert.ok(!rows.some((x) => [aid, bid].includes(x.articleId)));
  own = await json(await a.api(`articles/${aid}`));
  assert.equal(own.snapshot.article.id, aid);
});
test("cross-owner reads, historical exports and writes fail, including for admins", async () => {
  for (const other of [b, admin]) {
    assert.equal((await other.api(`articles/${aid}`)).status, 404);
    assert.equal((await other.api(`articles/${aid}?version=1`)).status, 404);
    assert.equal((await save(other, own.snapshot, 1)).status, 403);
  }
  assert.equal((await a.api(`articles/${bid}`)).status, 404);
  const spoof = structuredClone(own.snapshot);
  spoof.ownerId = data.users[1].uid;
  assert.equal((await save(a, spoof, 1)).status, 400);
});
test("private files cannot be listed, uploaded or served across owners", async () => {
  receipt = await json(await a.api(`articles/${aid}/assets`, uploadBody()));
  assert.equal((await a.request(receipt.contentUrl)).status, 200);
  for (const other of [b, admin]) {
    assert.equal((await other.api(`articles/${aid}/assets`)).status, 403);
    assert.equal(
      (await other.api(`articles/${aid}/assets`, uploadBody())).status,
      403,
    );
    assert.equal((await other.request(receipt.contentUrl)).status, 403);
  }
});
test("WordPress media discovery and attachment references are uploader-scoped", async () => {
  const media = data.users[0].data.attachments.document;
  assert.equal((await a.api(`media/${media.id}`)).status, 200);
  for (const other of [b, admin]) {
    assert.equal((await other.api(`media/${media.id}`)).status, 404);
    assert.equal(
      (await json(await other.api(`media?search=${aid}`))).items.length,
      0,
    );
  }
});
test("authors can save pinned components but cannot introduce arbitrary designs", async () => {
  const bad = structuredClone(own.snapshot);
  const c = bad.components[0];
  c.css += "\n.custom{color:red}";
  const canonical = Object.fromEntries(
    ["id", "key", "name", "purpose", "html", "css", "fields", "variants"].map(
      (k) => [k, c[k]],
    ),
  );
  c.revision = createHash("sha256")
    .update(JSON.stringify(canonical))
    .digest("hex");
  for (const block of bad.article.blocks)
    if (block.componentId === c.id) block.componentRevision = c.revision;
  bad.article.revision++;
  const r = await save(a, bad, 1);
  assert.equal(r.status, 403);
  assert.equal((await r.json()).code, "sf_design_forbidden");
  const next = structuredClone(own.snapshot);
  next.article.revision++;
  next.article.blocks[0].values.document.assetRef = {
    kind: "staged",
    id: receipt.assetId,
    sha256: receipt.sha256,
  };
  next.article.blocks[0].values.document.value = "";
  next.article.blocks[0].values.document.alt = "Download the document";
  own = await json(await save(a, next, 1));
  assert.equal(own.version, 2);
});
test("exact-version theme previews and resolved PDFs work only for the owner", async () => {
  const r = await a.request(own.previewUrl);
  assert.equal(r.status, 200);
  const dom = new JSDOM(await r.text());
  const pdf = [...dom.window.document.querySelectorAll("a")].find((x) =>
    x.href.includes("sf_media"),
  )?.href;
  assert.ok(pdf);
  dom.window.close();
  assert.deepEqual(
    Buffer.from(await (await a.request(pdf)).arrayBuffer()),
    bytes,
  );
  for (const other of [b, admin]) {
    assert.equal((await other.request(own.previewUrl)).status, 403);
    assert.equal((await other.request(pdf)).status, 403);
  }
});
test("transfer grants stay bound to issuer, article, version and exact bytes", async () => {
  grant = await json(
    await a.api(`articles/${aid}/transfers`, { expectedVersion: 2, ...meta }),
  );
  for (const other of [b, admin]) {
    assert.equal(
      (
        await other.api(`articles/${aid}/transfers`, {
          expectedVersion: 2,
          ...meta,
        })
      ).status,
      403,
    );
    assert.equal(
      (await other.api(`transfers/${grant.transferId}/revoke`, {})).status,
      403,
    );
  }
  const r = await fetch(grant.uploadUrl, {
    method: "POST",
    headers: { "X-SF-Transfer-Token": grant.token },
    body: uploadBody(),
  });
  assert.equal(r.status, 200);
  const sent = await r.json();
  assert.equal(sent.articleId, aid);
  assert.ok(!sent.contentUrl);
});
test("review and approval screens do not cross owners or administrator accounts", async () => {
  review = await json(
    await a.api(`articles/${aid}/reviews`, { expectedVersion: 2 }),
  );
  const page = await a.request(review.reviewUrl);
  assert.equal(page.status, 200);
  const dom = new JSDOM(await page.text());
  const fields = new URLSearchParams();
  for (const input of dom.window.document.querySelectorAll("form input"))
    fields.set(input.name, input.value);
  fields.set("decision", "approved");
  dom.window.close();
  for (const other of [b, admin]) {
    assert.equal(
      (await other.api(`articles/${aid}/reviews`, { expectedVersion: 2 }))
        .status,
      403,
    );
    assert.equal((await other.api(`reviews/${review.reviewId}`)).status, 404);
    assert.equal((await other.request(review.reviewUrl)).status, 403);
    assert.equal(
      (
        await other.request("/wp-admin/admin-post.php", {
          method: "POST",
          body: fields,
        })
      ).status,
      403,
    );
  }
  assert.equal(
    (
      await a.request("/wp-admin/admin-post.php", {
        method: "POST",
        body: fields,
      })
    ).status,
    302,
  );
  const approved = await json(await a.api(`reviews/${review.reviewId}`));
  assert.equal(approved.state, "approved");
  assert.equal(approved.approvedBy, data.users[0].uid);
  assert.equal(approved.publishAuthorized, false);
});
test("revoking reviewer capability invalidates approval without deleting the draft", async () => {
  assert.match(fixture("revoke-review"), /fixture-ok/);
  assert.equal((await a.api(`reviews/${review.reviewId}`)).status, 403);
  assert.equal(fixture("review-state"), "invalidated");
  assert.equal((await a.api(`articles/${aid}`)).status, 200);
  assert.match(fixture("restore-review"), /fixture-ok/);
});
test("disabling an issuer stops delegated uploads and reads without transferring ownership", async () => {
  assert.match(fixture("revoke"), /fixture-ok/);
  assert.equal(
    (
      await fetch(grant.uploadUrl, {
        method: "POST",
        headers: { "X-SF-Transfer-Token": grant.token },
        body: uploadBody(),
      })
    ).status,
    403,
  );
  assert.equal((await a.api(`articles/${aid}`)).status, 403);
  assert.equal((await b.api(`articles/${aid}`)).status, 404);
  assert.match(fixture("restore"), /fixture-ok/);
  assert.equal((await a.api(`articles/${aid}`)).status, 200);
});
test("same-owner stale writes conflict while different article saves stay independent", async () => {
  const next = structuredClone(own.snapshot);
  next.article.revision++;
  const other = structuredClone(data.users[1].data.snapshot);
  other.article.revision++;
  const saved = await Promise.all([save(a, next, 2), save(b, other, 1)]);
  assert.deepEqual(
    saved.map((r) => r.status),
    [200, 200],
  );
  own = await saved[0].json();
  assert.equal((await save(a, next, 2)).status, 409);
  assert.equal(
    (await json(await a.api(`reviews/${review.reviewId}`))).state,
    "invalidated",
  );
  assert.match(fixture("assert"), /fixture-ok/);
});
test("simultaneous first saves with the same article ID establish only one owner", async () => {
  const initial = structuredClone(data.users[0].data.snapshot);
  initial.article.id = data.race;
  const responses = await Promise.all([
    save(a, initial, 0),
    save(b, initial, 0),
  ]);
  assert.deepEqual(responses.map((r) => r.status).sort(), [200, 403]);
  const winner = responses[0].status === 200 ? a : b;
  const loser = winner === a ? b : a;
  assert.equal((await winner.api(`articles/${data.race}`)).status, 200);
  assert.equal((await loser.api(`articles/${data.race}`)).status, 404);
});
test("a new login resumes the owner's saved version, independently of AI session", async () => {
  const resumed = await client(data.users[0]);
  const loaded = await json(await resumed.api(`articles/${aid}`));
  assert.equal(loaded.digest, own.digest);
  assert.equal(loaded.version, own.version);
});
test("independent PHP workers racing on a first save establish a single database owner", async () => {
  const start = String(Date.now() / 1000 + 2);
  const results = await Promise.all(
    ["race-a", "race-b"].map((action) =>
      promisify(execFile)(
        "php",
        [
          fileURLToPath(
            new URL("./wordpress-users-fixture.php", import.meta.url),
          ),
          action,
          run,
          start,
        ],
        { encoding: "utf8" },
      ),
    ),
  );
  assert.deepEqual(results.map((r) => r.stdout).sort(), [
    "saved",
    "sf_forbidden",
  ]);
  assert.deepEqual(
    (
      await Promise.all([
        a.api(`articles/${data.dbRace}`),
        b.api(`articles/${data.dbRace}`),
      ])
    )
      .map((r) => r.status)
      .sort(),
    [200, 404],
  );
});
