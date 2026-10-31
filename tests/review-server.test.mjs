import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, rm, stat } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createServer, request } from "node:http";
import {
  createReviewStore,
  hash,
  validateSnapshot,
} from "../server/review-store.mjs";
import { reviewMiddleware } from "../server/review-http.mjs";
import { snapshotFor } from "../src/review-snapshot.js";

const origin = "http://127.0.0.1:5178";
function fixture(revision = 1, title = "Server review test") {
  const component = {
    id: "section",
    key: "section",
    name: "Section",
    purpose: "Body",
    html: '<section class="section"><h2 data-field="heading">Heading</h2></section>',
    css: ".section{padding:20px}",
    fields: [
      {
        id: "heading",
        key: "heading",
        label: "Heading",
        type: "text",
        required: true,
        value: "Heading",
        alt: "",
      },
    ],
    variants: [{ id: "default", name: "Default", classes: "" }],
  };
  component.revision = hash(component);
  return {
    article: {
      id: "test-article",
      revision,
      title,
      blocks: [
        {
          id: "one",
          componentId: component.id,
          componentRevision: component.revision,
          variantId: "default",
          values: { heading: { value: "Heading", alt: "" } },
        },
      ],
    },
    components: [component],
  };
}
async function setup(t, options) {
  const dir = await mkdtemp(join(tmpdir(), "sf-review-"));
  const store = createReviewStore(dir, options);
  t.after(async () => {
    store.close();
    await rm(dir, { recursive: true, force: true });
  });
  return { store, dir };
}
const rejects = (fn, code) => assert.rejects(fn, (e) => e.code === code);

test("server uses the browser validator and renderer without executing markup", async () => {
  const result = await validateSnapshot(fixture(), origin);
  assert.match(result.html, /Server review test/);
  assert.match(result.html, /default-src 'none'/);
  assert.deepEqual(result.snapshot, snapshotFor(fixture()));
});
test("normalization excludes browser history and approval claims", () => {
  const input = {
    ...fixture(),
    review: { publishAuthorized: true },
    history: ["private"],
  };
  assert.deepEqual(snapshotFor(input), fixture());
});
test("strict boundary rejects forged flags and invalid markup", async () => {
  await rejects(
    () => validateSnapshot({ ...fixture(), review: true }, origin),
    "INVALID_SNAPSHOT",
  );
  const value = fixture();
  value.components[0].html = "<section><script>alert(1)</script></section>";
  const { revision, ...data } = value.components[0];
  value.components[0].revision = hash(data);
  value.article.blocks[0].componentRevision = value.components[0].revision;
  await rejects(() => validateSnapshot(value, origin), "INVALID_SNAPSHOT");
});
test("rejects tampered component hashes and unsafe field URLs", async () => {
  const value = fixture();
  value.components[0].css += "p{color:red}";
  await rejects(() => validateSnapshot(value, origin), "INVALID_SNAPSHOT");
  const invalid = fixture();
  invalid.article.blocks[0].variantId = "missing";
  await rejects(() => validateSnapshot(invalid, origin), "INVALID_SNAPSHOT");
});
test("first save persists exact content with restrictive filesystem modes", async (t) => {
  const { store, dir } = await setup(t);
  const entry = await store.save(fixture(), 0, origin);
  assert.equal(entry.version, 1);
  assert.equal(entry.receipt, null);
  assert.deepEqual(store.get("test-article"), entry);
  assert.equal((await stat(dir)).mode & 0o777, 0o700);
  assert.equal((await stat(join(dir, "reviews.sqlite"))).mode & 0o777, 0o600);
});
test("identical saves are idempotent; stale writes and same-revision changes fail", async (t) => {
  const { store } = await setup(t);
  const first = await store.save(fixture(), 0, origin);
  assert.deepEqual(await store.save(fixture(), 1, origin), first);
  await rejects(
    () => store.save(fixture(2, "New"), 0, origin),
    "SERVER_CONFLICT",
  );
  await rejects(
    () => store.save(fixture(1, "Changed"), 1, origin),
    "REVISION_CONFLICT",
  );
  assert.equal(store.get("test-article").digest, first.digest);
});
test("confirmation binds revision, digest and session and cannot be replayed", async (t) => {
  const { store } = await setup(t);
  await store.save(fixture(), 0, origin);
  const preview = store.preview("test-article", 1, "session-a");
  assert.throws(() => store.confirm(preview, "session-b"), {
    code: "INVALID_TICKET",
  });
  assert.throws(
    () => store.confirm({ ...preview, digest: "forged" }, "session-a"),
    { code: "PREVIEW_CHANGED" },
  );
  const receipt = store.confirm(preview, "session-a");
  assert.equal(receipt.verifiedHuman, false);
  assert.equal(receipt.publishAuthorized, false);
  assert.equal(receipt.digest, preview.digest);
  assert.equal(store.get("test-article").receipt.id, receipt.id);
  assert.throws(() => store.confirm(preview, "session-a"), {
    code: "INVALID_TICKET",
  });
});
test("expired preview cannot be confirmed", async (t) => {
  let clock = 100000;
  const { store } = await setup(t, { now: () => clock });
  await store.save(fixture(), 0, origin);
  const preview = store.preview("test-article", 1, "session");
  clock += 300000;
  assert.throws(() => store.confirm(preview, "session"), {
    code: "INVALID_TICKET",
  });
});
test("saving a new revision invalidates the previous receipt and preview", async (t) => {
  const { store } = await setup(t);
  await store.save(fixture(), 0, origin);
  const old = store.preview("test-article", 1, "session");
  store.confirm(store.preview("test-article", 1, "session"), "session");
  const next = await store.save(fixture(2, "New title"), 1, origin);
  assert.equal(next.receipt, null);
  assert.throws(() => store.confirm(old, "session"), {
    code: "SERVER_CONFLICT",
  });
});
test("CSS changes alter the confirmation fingerprint", async (t) => {
  const { store } = await setup(t);
  const before = await store.save(fixture(), 0, origin);
  const updated = fixture(2);
  const { revision, ...component } = updated.components[0];
  component.css = ".section{padding:40px}";
  updated.components[0] = { ...component, revision: hash(component) };
  updated.article.blocks[0].componentRevision = hash(component);
  const after = await store.save(updated, 1, origin);
  assert.notEqual(before.digest, after.digest);
});
test("restart preserves saved snapshots and receipts but not outstanding tickets", async (t) => {
  const { store, dir } = await setup(t);
  const saved = await store.save(fixture(), 0, origin);
  const preview = store.preview("test-article", 1, "session");
  store.confirm(store.preview("test-article", 1, "session"), "session");
  const reopened = createReviewStore(dir);
  t.after(() => reopened.close());
  assert.equal(reopened.get("test-article").digest, saved.digest);
  assert.ok(reopened.get("test-article").receipt);
  assert.throws(() => reopened.confirm(preview, "session"), {
    code: "INVALID_TICKET",
  });
});
test("independent connections use compare-and-swap, not last-writer-wins", async (t) => {
  const { store, dir } = await setup(t);
  const second = createReviewStore(dir);
  t.after(() => second.close());
  await store.save(fixture(), 0, origin);
  await second.save(fixture(2, "Other tab"), 1, origin);
  await rejects(
    () => store.save(fixture(3, "Stale writer"), 1, origin),
    "SERVER_CONFLICT",
  );
  assert.equal(store.get("test-article").snapshot.article.title, "Other tab");
});
test("HTTP denies cross-origin, missing-CSRF and non-JSON requests", async (t) => {
  const { store } = await setup(t);
  const middleware = reviewMiddleware(store);
  const server = createServer((req, res) =>
    middleware(req, res, () => {
      res.writeHead(404);
      res.end();
    }),
  );
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  t.after(() => new Promise((resolve) => server.close(resolve)));
  const base = `http://127.0.0.1:${server.address().port}`;
  function post(action, body = {}, extra = {}) {
    return new Promise((resolve, reject) => {
      const data = JSON.stringify(body);
      const req = request(
        `${base}/api/local-review/${action}`,
        {
          method: "POST",
          headers: {
            Origin: base,
            "Content-Type": "application/json",
            "X-SF-Local": "1",
            "Content-Length": Buffer.byteLength(data),
            ...extra,
          },
        },
        (res) => {
          const chunks = [];
          res.on("data", (chunk) => chunks.push(chunk));
          res.on("end", () =>
            resolve({
              status: res.statusCode,
              headers: { get: (name) => [res.headers[name]].flat().join("; ") },
              json: () => JSON.parse(Buffer.concat(chunks).toString()),
            }),
          );
        },
      );
      req.on("error", reject);
      req.end(data);
    });
  }
  assert.equal(
    (await post("session", {}, { Origin: "https://evil.example" })).status,
    403,
  );
  assert.equal(
    (await post("session", {}, { Host: "evil.example" })).status,
    403,
  );
  assert.equal(
    (await post("session", {}, { "Sec-Fetch-Site": "cross-site" })).status,
    403,
  );
  assert.equal(
    (await post("session", {}, { "Content-Type": "text/plain" })).status,
    415,
  );
  const session = await post("session");
  const cookie = session.headers.get("set-cookie").split(";")[0];
  assert.match(session.headers.get("set-cookie"), /HttpOnly; SameSite=Strict/);
  const { csrf } = await session.json();
  const auth = { Cookie: cookie, "X-SF-CSRF": csrf };
  assert.equal(
    (await post("read", { id: "test-article" }, { Cookie: cookie })).status,
    403,
  );
  assert.equal(
    (await post("save", { snapshot: fixture(), expectedVersion: 0 }, auth))
      .status,
    200,
  );
  const view = await (
    await post("preview", { id: "test-article", expectedVersion: 1 }, auth)
  ).json();
  const confirmed = await (
    await post(
      "confirm",
      { ticket: view.data.ticket, digest: view.data.digest, version: 1 },
      auth,
    )
  ).json();
  assert.equal(confirmed.data.publishAuthorized, false);
  assert.equal((await post("publish", {}, auth)).status, 404);
  assert.equal(
    (
      await post(
        "save",
        { snapshot: fixture(), expectedVersion: 1, approved: true },
        auth,
      )
    ).status,
    400,
  );
  assert.equal(
    (await post("read", { id: "x".repeat(4000000) }, auth)).status,
    413,
  );
});
