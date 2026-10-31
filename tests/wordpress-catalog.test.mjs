import { test, after } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { execFile, execFileSync } from "node:child_process";
import { promisify } from "node:util";
import { fileURLToPath } from "node:url";
import { randomUUID } from "node:crypto";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";
import Ajv from "ajv/dist/2020.js";
const origin = "http://127.0.0.1:8128",
  run = randomUUID();
const fixturePath = (name) =>
  fileURLToPath(new URL(`./wordpress-${name}-fixture.php`, import.meta.url));
const fixture = (name, action) =>
  execFileSync("php", [fixturePath(name), action, run], { encoding: "utf8" });
after(() => {
  fixture("users", "cleanup");
  fixture("catalog", "cleanup");
});
const data = JSON.parse(fixture("users", "create"));
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
  const page = await request("/wp-admin/admin.php?page=structured-fields");
  assert.equal(page.status, 200);
  const dom = new JSDOM(await page.text());
  const line = [...dom.window.document.scripts]
    .flatMap((s) => s.textContent.split("\n"))
    .map((s) => s.trim())
    .find((s) => s.startsWith("window.sfWordPress="));
  const config = JSON.parse(line.slice("window.sfWordPress=".length, -1));
  dom.window.close();
  const url = (path) => {
    const u = new URL(config.root),
      [route, query] = path.split("?");
    if (u.searchParams.has("rest_route"))
      u.searchParams.set(
        "rest_route",
        u.searchParams.get("rest_route") + route,
      );
    else u.pathname += route;
    for (const [k, v] of new URLSearchParams(query)) u.searchParams.set(k, v);
    return u;
  };
  return {
    request,
    url,
    api: (path, body, method = body === undefined ? "GET" : "POST") =>
      request(url(path), {
        method,
        headers: {
          "X-WP-Nonce": config.nonce,
          "Content-Type": "application/json",
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      }),
  };
}
const admin = await client(
  JSON.parse(
    await readFile(new URL("../.local/wp-credentials.json", import.meta.url)),
  ),
);
const a = await client(data.users[0]),
  b = await client(data.users[1]);
const c = structuredClone(
  JSON.parse(
    await readFile(
      new URL("../wordpress/atshift-deft/sample.json", import.meta.url),
    ),
  ).components[0],
);
c.id = `catalog-test-${run}`;
c.key = `catalog_${run.replaceAll("-", "_")}`;
c.name = "Catalog fixture";
c.variants.push({
  id: "compact",
  name: "Compact",
  classes: "compact highlighted",
});
delete c.revision;
const json = async (r) => {
  const value = await r.json();
  assert.equal(r.status, 200, value.message);
  return value;
};
const block = (component) => ({
  id: "intro-block",
  componentId: component.id,
  componentRevision: component.revision,
  variantId: "compact",
  values: Object.fromEntries(
    component.fields.map((f) => [f.key, { value: f.value, alt: f.alt }]),
  ),
});
const article = (id, component, revision = 1) => ({
  id,
  revision,
  title: "Catalog article",
  blocks: [block(component)],
});
const compose = (who, article, expectedVersion) =>
  who.api("articles/compose", { article, expectedVersion });
let first, updated, savedA, savedB, schemas;

test("catalog and machine-readable schemas require authenticated workspace access", async () => {
  assert.equal((await fetch(admin.url("catalog"))).status, 403);
  assert.equal((await admin.request(admin.url("catalog"))).status, 403);
  const list = await json(await a.api("catalog"));
  assert.ok(list.items.some((x) => x.id === "intro"));
  schemas = await json(await a.api("catalog/schema"));
  assert.equal(
    new Ajv().compile(schemas.register)({ component: c, expectedVersion: 0 }),
    true,
  );
  assert.equal((await a.api("catalog?includeDisabled=1")).status, 403);
  assert.equal((await a.api("catalog?page=0")).status, 400);
});
test("only administrators can register shared component designs", async () => {
  assert.equal(
    (await a.api("catalog", { component: c, expectedVersion: 0 })).status,
    403,
  );
  first = await json(
    await admin.api("catalog", { component: c, expectedVersion: 0 }),
  );
  assert.match(first.revision, /^[a-f0-9]{64}$/);
  assert.equal(first.version, 1);
  assert.equal(first.availableForNewArticles, true);
  for (const who of [a, b])
    assert.equal(
      (await json(await who.api(`catalog/${c.id}`))).revision,
      first.revision,
    );
});
test("catalog normalization is idempotent and preserves caller-independent content hashes", async () => {
  const reordered = Object.fromEntries(
    Object.entries(first.component).reverse(),
  );
  assert.equal(
    (
      await json(
        await admin.api("catalog", {
          component: reordered,
          expectedVersion: 1,
        }),
      )
    ).version,
    1,
  );
  assert.equal(fixture("catalog", "count"), "1");
  assert.equal(
    (await admin.api("catalog", { component: c, expectedVersion: 0 })).status,
    409,
  );
});
test("invalid markup, CSS imports, hash tampering, field types and authority flags are rejected", async () => {
  for (const mutate of [
    (x) => {
      x.html += "<script>alert(1)</script>";
    },
    (x) => {
      x.css = '@import "https://invalid.example/x.css";';
    },
    (x) => {
      x.revision = "0".repeat(64);
    },
    (x) => {
      x.fields[0].required = "true";
    },
    (x) => {
      x.fields[0].key = "wrong";
    },
    (x) => {
      x.approved = true;
    },
    (x) => {
      x.id = "../escape";
    },
    (x) => {
      x.id = "schema";
    },
  ]) {
    const invalid = structuredClone(c);
    mutate(invalid);
    assert.equal(
      (await admin.api("catalog", { component: invalid, expectedVersion: 1 }))
        .status,
      400,
    );
  }
  assert.equal(
    (await admin.api("catalog", { component: c, expectedVersion: "1" })).status,
    400,
  );
  assert.equal(fixture("catalog", "count"), "1");
});
test("machine names are unique across IDs and stable within a registered component", async () => {
  assert.equal(
    (
      await admin.api("catalog", {
        component: { ...c, id: `${c.id}-other` },
        expectedVersion: 0,
      })
    ).status,
    409,
  );
  assert.equal(
    (
      await admin.api("catalog", {
        component: { ...c, key: "renamed" },
        expectedVersion: 1,
      })
    ).status,
    409,
  );
});
test("authors compose from IDs and values without importing HTML/CSS", async () => {
  const draft = article(data.users[0].id, first.component, 2);
  draft.blocks[0].values.heading.value = "<strong>Plain text</strong>";
  assert.equal(
    new Ajv().compile(schemas.compose)({ article: draft, expectedVersion: 1 }),
    true,
  );
  savedA = await json(await compose(a, draft, 1));
  assert.equal(savedA.version, 2);
  assert.equal(savedA.snapshot.schemaVersion, 2);
  assert.equal(savedA.snapshot.components[0].revision, first.revision);
  const preview = await a.request(savedA.previewUrl);
  assert.equal(preview.status, 200);
  const html = await preview.text();
  assert.match(html, /&lt;strong&gt;Plain text&lt;\/strong&gt;/);
  assert.match(html, /compact highlighted/);
  assert.equal(savedA.publishAuthorized, false);
});
test("new article creation and saved-snapshot imports use the approved catalog", async () => {
  const created = await json(
    await compose(a, article(data.race, first.component), 0),
  );
  assert.equal(created.version, 1);
  const imported = structuredClone(created.snapshot);
  imported.article.id = data.users[1].id;
  imported.article.revision = 2;
  savedB = await json(
    await b.api("articles", { snapshot: imported, expectedVersion: 1 }),
  );
  assert.equal(savedB.version, 2);
});
test("shared definitions never expose another staff member's draft", async () => {
  for (const who of [b, admin]) {
    assert.equal((await compose(who, savedA.snapshot.article, 2)).status, 403);
    assert.equal((await who.api(`articles/${savedA.articleId}`)).status, 404);
  }
});
test("compose rejects unknown design data, component revisions and invalid field sets", async () => {
  assert.equal(
    (
      await a.api("articles/compose", {
        article: savedA.snapshot.article,
        expectedVersion: 2,
        components: [c],
      })
    ).status,
    400,
  );
  for (const mutate of [
    (x) => {
      x.blocks[0].values.heading.value = "";
    },
    (x) => {
      x.blocks[0].variantId = "unknown";
    },
    (x) => {
      x.blocks.push(structuredClone(x.blocks[0]));
    },
    (x) => {
      x.blocks[0].values.extra = { value: "x", alt: "" };
    },
  ]) {
    const draft = structuredClone(savedA.snapshot.article);
    draft.revision++;
    mutate(draft);
    assert.equal((await compose(a, draft, 2)).status, 400);
  }
  const unknown = article(data.dbRace, {
    ...first.component,
    revision: "0".repeat(64),
  });
  assert.equal((await compose(a, unknown, 0)).status, 409);
});
test("catalog updates append a version without rewriting saved article snapshots", async () => {
  const next = structuredClone(c);
  next.css += "\n.compact{padding:8px}";
  updated = await json(
    await admin.api("catalog", { component: next, expectedVersion: 1 }),
  );
  assert.equal(updated.version, 2);
  assert.notEqual(updated.revision, first.revision);
  assert.equal(fixture("catalog", "count"), "2");
  const loaded = await json(await a.api(`articles/${savedA.articleId}`));
  assert.equal(loaded.digest, savedA.digest);
  const old = await json(
    await a.api(`catalog/${c.id}?revision=${first.revision}`),
  );
  assert.equal(old.availableForNewArticles, false);
  assert.equal(
    (await compose(a, article(data.dbRace, first.component), 0)).status,
    409,
  );
});
test("existing articles can keep old pinned revisions or explicitly select the new one", async () => {
  const next = structuredClone(savedA.snapshot.article);
  next.revision++;
  savedA = await json(await compose(a, next, 2));
  assert.equal(savedA.snapshot.components[0].revision, first.revision);
  const nextB = article(savedB.articleId, updated.component, 3);
  savedB = await json(await compose(b, nextB, 2));
  assert.equal(savedB.snapshot.components[0].revision, updated.revision);
  assert.equal((await compose(a, next, 2)).status, 409);
});
test("disabling a component blocks new uses but preserves pinned drafts and previews", async () => {
  assert.equal(
    (
      await a.api(`catalog/${c.id}/availability`, {
        enabled: false,
        expectedVersion: 2,
      })
    ).status,
    403,
  );
  const disabled = await json(
    await admin.api(`catalog/${c.id}/availability`, {
      enabled: false,
      expectedVersion: 2,
    }),
  );
  assert.equal(disabled.version, 3);
  assert.ok(
    !(await json(await a.api("catalog"))).items.some((x) => x.id === c.id),
  );
  assert.equal(
    (await compose(a, article(data.dbRace, updated.component), 0)).status,
    409,
  );
  const raw = {
    schemaVersion: 2,
    article: article(data.dbRace, updated.component),
    components: [updated.component],
  };
  assert.equal(
    (await a.api("articles", { snapshot: raw, expectedVersion: 0 })).status,
    403,
  );
  const next = structuredClone(savedB.snapshot.article);
  next.revision++;
  savedB = await json(await compose(b, next, 3));
  assert.equal((await b.request(savedB.previewUrl)).status, 200);
  assert.equal(
    (
      await admin.api(`catalog/${c.id}/availability`, {
        enabled: true,
        expectedVersion: 2,
      })
    ).status,
    409,
  );
});
test("re-enabling uses compare-and-swap without adding duplicate immutable payloads", async () => {
  const enabled = await json(
    await admin.api(`catalog/${c.id}/availability`, {
      enabled: true,
      expectedVersion: 3,
    }),
  );
  assert.equal(enabled.version, 4);
  assert.equal(fixture("catalog", "count"), "2");
  assert.equal(
    (await json(await a.api(`catalog/${c.id}`))).availableForNewArticles,
    true,
  );
});
test("catalog-composed articles retain exact private media references and approval binding", async () => {
  const media = (await json(await a.api("catalog/media"))).component;
  const next = structuredClone(savedA.snapshot.article);
  next.revision++;
  const resource = {
    ...block(media),
    id: "resources",
    variantId: media.variants[0].id,
  };
  resource.values.image = {
    value: "",
    alt: "Private image",
    assetRef: data.users[0].data.staged.image,
  };
  resource.values.document = {
    value: "",
    alt: "Private PDF",
    assetRef: data.users[0].data.staged.document,
  };
  next.blocks.push(resource);
  savedA = await json(await compose(a, next, 3));
  assert.equal((await a.request(savedA.previewUrl)).status, 200);
  const review = await json(
    await a.api(`articles/${savedA.articleId}/reviews`, { expectedVersion: 4 }),
  );
  assert.equal(review.manifest.files.length, 2);
  assert.equal((await b.api(`reviews/${review.reviewId}`)).status, 404);
});
test("independent PHP workers cannot silently overwrite each other's catalog updates", async () => {
  const start = String(Date.now() / 1000 + 2);
  const results = await Promise.all(
    ["race-a", "race-b"].map((action) =>
      promisify(execFile)("php", [fixturePath("catalog"), action, run, start], {
        encoding: "utf8",
      }),
    ),
  );
  assert.deepEqual(results.map((x) => x.stdout).sort(), [
    "saved",
    "sf_conflict",
  ]);
  assert.equal((await json(await admin.api(`catalog/${c.id}`))).version, 5);
  assert.equal(fixture("catalog", "count"), "3");
});
