import { createProfile, updateBinding, parseHTML } from "../src/model.js";
import {
  buildCatalog,
  makeBlock,
  createArticleRecord,
  proposalFor,
  prepareProposal,
  applyPrepared,
  editArticle,
  restoreRevision,
  renderArticle,
  markReviewed,
  handoffBundle,
  decodeArticleStore,
  encodeArticleStore,
  persistArticleStore,
  articleChanges,
} from "../src/article-model.js";

let passed = 0,
  failed = 0;
function assert(value, message = "Assertion failed") {
  if (!value) throw new Error(message);
}
function equal(a, b) {
  assert(
    JSON.stringify(a) === JSON.stringify(b),
    `${JSON.stringify(a)} != ${JSON.stringify(b)}`,
  );
}
async function check(name, run) {
  const li = document.createElement("li");
  try {
    await run();
    passed++;
    li.textContent = `PASS: ${name}`;
  } catch (e) {
    failed++;
    li.textContent = `FAIL: ${name}: ${e.message}`;
  }
  document.querySelector("#checks").append(li);
}
function rejects(fn, code) {
  try {
    fn();
  } catch (e) {
    equal(e.code, code);
    return;
  }
  throw new Error(`Expected ${code}`);
}
async function rejectsAsync(fn, code) {
  try {
    await fn();
  } catch (e) {
    equal(e.code, code);
    return;
  }
  throw new Error(`Expected ${code}`);
}
const profile = createProfile("feature");
const catalog = await buildCatalog([profile]);
const component = catalog.components[0];
const record = createArticleRecord(
  "記事の見出し",
  [component],
  [makeBlock(component)],
);
const proposal = () => {
  const v = proposalFor(record.article);
  v.title = "新しい見出し";
  return v;
};
const prepare = (p) => prepareProposal(JSON.stringify(p), record, catalog);

await check("canonical article and stable component revision", async () => {
  equal(
    (await buildCatalog([profile])).components[0].revision,
    component.revision,
  );
  equal(prepare(proposal()).article.blocks[0].id, record.article.blocks[0].id);
});
await check("proposal preview does not mutate stored content", () => {
  const before = JSON.stringify(record);
  prepare(proposal());
  equal(JSON.stringify(record), before);
});
await check(
  "apply increments revision and retains an immutable history snapshot",
  () => {
    const next = applyPrepared(record, prepare(proposal()));
    equal(next.article.revision, 2);
    equal(next.history[0].article.title, "記事の見出し");
    next.article.blocks[0].values.heading.value = "Changed";
    assert(
      next.history[0].article.blocks[0].values.heading.value !== "Changed",
    );
  },
);
await check("stale proposal and wrong article are rejected", () => {
  const p = proposal();
  p.baseRevision = 2;
  rejects(() => prepare(p), "STALE_REVISION");
  p.baseRevision = 1;
  p.articleId = "different";
  rejects(() => prepare(p), "WRONG_ARTICLE");
});
await check("proposal becomes stale if the draft changes after preview", () => {
  const pending = prepare(proposal());
  const next = applyPrepared(record, pending);
  rejects(() => applyPrepared(next, pending), "STALE_REVISION");
});
await check(
  "unknown schema keys cannot request publication or permissions",
  () => {
    for (const key of ["publish", "approval", "settings", "html", "css"]) {
      const p = proposal();
      p[key] = true;
      rejects(() => prepare(p), "INVALID_SHAPE");
    }
  },
);
await check(
  "invalid JSON, string revision and oversized payload are rejected",
  () => {
    rejects(() => prepareProposal("```json", record, catalog), "INVALID_JSON");
    const p = proposal();
    p.baseRevision = "1";
    rejects(() => prepare(p), "INVALID_SHAPE");
    rejects(
      () => prepareProposal(" ".repeat(2000001), record, catalog),
      "PAYLOAD_TOO_LARGE",
    );
  },
);
await check(
  "unknown component, revision, variant, missing and extra fields rejected",
  () => {
    let p = proposal();
    p.blocks[0].componentId = "missing";
    rejects(() => prepare(p), "UNKNOWN_COMPONENT_REVISION");
    p = proposal();
    p.blocks[0].componentRevision = "0".repeat(64);
    rejects(() => prepare(p), "UNKNOWN_COMPONENT_REVISION");
    p = proposal();
    p.blocks[0].variantId = "missing";
    rejects(() => prepare(p), "UNKNOWN_VARIANT");
    p = proposal();
    delete p.blocks[0].values.heading;
    rejects(() => prepare(p), "FIELD_MISMATCH");
    p = proposal();
    p.blocks[0].values.extra = { value: "x", alt: "" };
    rejects(() => prepare(p), "FIELD_MISMATCH");
  },
);
await check("empty required values and unsafe image URLs rejected", () => {
  let p = proposal();
  p.blocks[0].values.heading.value = " ";
  rejects(() => prepare(p), "INVALID_FIELD_VALUE");
  p = proposal();
  p.blocks[0].values.image.value = "javascript:alert(1)";
  rejects(() => prepare(p), "INVALID_FIELD_VALUE");
  p = proposal();
  p.blocks[0].values.image.alt = "";
  rejects(() => prepare(p), "INVALID_FIELD_VALUE");
});
await check("unanswered questions block applying but retain a preview", () => {
  const p = proposal();
  p.questions = ["開催日は？"];
  const pending = prepare(p);
  assert(renderArticle(pending.article, pending.components));
  rejects(() => applyPrepared(record, pending), "UNRESOLVED_QUESTIONS");
});
await check(
  "duplicate block IDs rejected, repeated components with unique IDs allowed",
  () => {
    const p = proposal();
    p.blocks.push(structuredClone(p.blocks[0]));
    rejects(() => prepare(p), "DUPLICATE_BLOCK_ID");
    p.blocks[1].id = "another-instance";
    equal(prepare(p).article.blocks.length, 2);
  },
);
await check(
  "reordered, added, removed, changed values and alt text appear in diff",
  () => {
    const before = structuredClone(record.article);
    before.blocks.push({ ...structuredClone(before.blocks[0]), id: "second" });
    const after = structuredClone(before);
    after.blocks.reverse();
    after.blocks[0].values.image.alt = "New alt";
    const changes = articleChanges(before, after);
    assert(changes.some((c) => c.kind === "order"));
    assert(changes.some((c) => c.kind === "field" && c.afterAlt === "New alt"));
    assert(
      articleChanges(record.article, before).some((c) => c.kind === "add"),
    );
    assert(
      articleChanges(before, record.article).some((c) => c.kind === "remove"),
    );
  },
);
await check(
  "text escapes HTML and preview has no executable scripts or live links",
  () => {
    const p = proposal();
    p.title = "<script>alert(1)</script>";
    p.blocks[0].values.heading.value = "<img src=x onerror=alert(1)>";
    const prepared = prepare(p);
    const html = renderArticle(prepared.article, prepared.components);
    const doc = parseHTML(html);
    assert(!doc.querySelector("script"));
    assert(!doc.querySelector("[onerror]"));
    equal(doc.querySelector("h1").textContent, p.title);
    assert(
      doc
        .querySelector('meta[http-equiv="Content-Security-Policy"]')
        .content.includes("default-src 'none'"),
    );
  },
);
await check(
  "component changes do not change existing article rendering",
  async () => {
    const changed = structuredClone(profile);
    changed.css += "\np{color:red}";
    const nextCatalog = await buildCatalog([changed]);
    assert(nextCatalog.components[0].revision !== component.revision);
    const before = renderArticle(record.article, record.components);
    const pending = prepareProposal(
      JSON.stringify(proposal()),
      record,
      nextCatalog,
    );
    equal(pending.components[0].revision, component.revision);
    equal(renderArticle(record.article, record.components), before);
  },
);
await check(
  "manual edit invalidates the exact-version review receipt",
  async () => {
    const reviewed = await markReviewed(record);
    equal(reviewed.review.revision, 1);
    const changed = structuredClone(record.article);
    changed.title = "人の修正";
    const next = editArticle(reviewed, changed);
    equal(next.review, null);
    equal(next.article.revision, 2);
    equal(handoffBundle(next, catalog).currentArticle.title, "人の修正");
  },
);
await check(
  "restoring makes a new revision without rolling revision IDs backwards",
  () => {
    const next = applyPrepared(record, prepare(proposal()));
    const restored = restoreRevision(next, 1);
    equal(restored.article.revision, 3);
    equal(restored.article.title, record.article.title);
    equal(restored.history.length, 2);
  },
);
await check(
  "handoff declares transport, schema, limits and non-publication",
  () => {
    const bundle = handoffBundle(record, catalog);
    equal(bundle.capabilities.publish, false);
    equal(bundle.capabilities.forms, false);
    equal(bundle.proposalTemplate.baseRevision, 1);
    equal(bundle.proposalSchema.additionalProperties, false);
  },
);
await check(
  "h1-owning components are excluded from article catalog",
  async () => {
    const p = structuredClone(profile);
    p.html = p.html.replaceAll("h2", "h1");
    const result = await buildCatalog([p]);
    equal(result.components.length, 0);
    equal(result.excluded.length, 1);
  },
);
await check("invalid component markup is excluded", async () => {
  const p = structuredClone(profile);
  p.html += "<script>alert(1)</script>";
  equal((await buildCatalog([p])).components.length, 0);
});
await check("store roundtrip preserves snapshots and history", async () => {
  const next = applyPrepared(record, prepare(proposal()));
  equal(await decodeArticleStore(encodeArticleStore([next])), [next]);
});
await check(
  "corrupted storage and changed component hashes are rejected",
  async () => {
    await rejectsAsync(() => decodeArticleStore("{bad"), "INVALID_STORE");
    const changed = structuredClone(record);
    changed.components[0].css += "p{}";
    await rejectsAsync(
      () => decodeArticleStore(encodeArticleStore([changed])),
      "COMPONENT_CHANGED",
    );
  },
);
await check("stale review receipt is discarded on load", async () => {
  const reviewed = await markReviewed(record);
  reviewed.review.contentDigest = "fake";
  equal(
    (await decodeArticleStore(encodeArticleStore([reviewed])))[0].review,
    null,
  );
});
await check(
  "concurrent tab writes and quota failures do not overwrite stored content",
  () => {
    let raw = null;
    const fake = {
      getItem: () => raw,
      setItem: (_key, v) => {
        raw = v;
      },
    };
    const saved = persistArticleStore([record], null, fake);
    rejects(() => persistArticleStore([], null, fake), "STORAGE_CONFLICT");
    equal(raw, saved);
    rejects(
      () =>
        persistArticleStore([], saved, {
          getItem: () => raw,
          setItem: () => {
            throw new Error("quota");
          },
        }),
      "STORAGE_UNAVAILABLE",
    );
    equal(raw, saved);
  },
);
await check(
  "link labels use canonical alt and reject empty labels",
  async () => {
    const p = createProfile();
    p.html =
      '<section><a data-field="link" href="/contact/">お問い合わせ</a></section>';
    p.fields = [
      {
        id: "link-field",
        key: "link",
        label: "Link",
        type: "link",
        value: "/contact/",
        alt: "お問い合わせ",
        required: true,
      },
    ];
    const c = await buildCatalog([p]);
    const r = createArticleRecord("Link test", c.components, [
      makeBlock(c.components[0]),
    ]);
    const input = proposalFor(r.article);
    input.blocks[0].values.link.alt = "";
    rejects(
      () => prepareProposal(JSON.stringify(input), r, c),
      "MISSING_LINK_TEXT",
    );
    assert(
      !parseHTML(renderArticle(r.article, r.components)).querySelector(
        "a[href]",
      ),
    );
  },
);
await check(
  "HTML IDs are excluded without silently breaking ID-based CSS",
  async () => {
    const p = structuredClone(profile);
    p.html = p.html.replace("<section class=", '<section id="feature" class=');
    p.css += "\n#feature { color: red; }";
    const c = await buildCatalog([p]);
    equal(c.components.length, 0);
    assert(c.excluded[0].reasons.some((reason) => reason.includes("HTML IDs")));
    assert(p.html.includes('id="feature"'));
  },
);
await check("CSS closing tags cannot escape the style boundary", async () => {
  const p = structuredClone(profile);
  p.css = "p{color:red} </style><script>alert(1)</script>";
  const c = await buildCatalog([p]);
  const r = createArticleRecord("CSS test", c.components, [
    makeBlock(c.components[0]),
  ]);
  assert(
    !parseHTML(renderArticle(r.article, r.components)).querySelector("script"),
  );
});
await check("no-op and history limit return explicit errors", () => {
  rejects(
    () => applyPrepared(record, prepare(proposalFor(record.article))),
    "NO_CHANGES",
  );
  const full = { ...record, history: Array(100).fill({}) };
  rejects(() => applyPrepared(full, prepare(proposal())), "HISTORY_LIMIT");
});

await check(
  "JSON object key order does not create false content changes",
  () => {
    const next = structuredClone(record.article);
    for (const block of next.blocks)
      for (const key of Object.keys(block.values)) {
        const value = block.values[key];
        block.values[key] = { alt: value.alt, value: value.value };
      }
    equal(articleChanges(record.article, next), []);
  },
);

document.querySelector("#result").textContent =
  `${passed} passed, ${failed} failed`;
document.documentElement.dataset.result = failed ? "failed" : "passed";
