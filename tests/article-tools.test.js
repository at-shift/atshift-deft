import { createProfile } from "../src/model.js";
import {
  buildCatalog,
  makeBlock,
  createArticleRecord,
  proposalFor,
  prepareProposal,
  applyPrepared,
  markReviewed,
} from "../src/article-model.js";
import { createArticleTools } from "../src/article-tools.js";

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
  const item = document.createElement("li");
  try {
    await run();
    passed++;
    item.textContent = `PASS: ${name}`;
  } catch (error) {
    failed++;
    item.textContent = `FAIL: ${name}: ${error.message}`;
  }
  document.querySelector("#checks").append(item);
}
const catalog = await buildCatalog([createProfile("feature")]);
const record = createArticleRecord("Tool test", catalog.components, [
  makeBlock(catalog.components[0]),
]);
const clone = (v) => structuredClone(v);
function harness() {
  const h = {
    state: {
      enabled: true,
      record: clone(record),
      catalog,
      pending: null,
      locked: false,
      storageCurrent: true,
      interacting: false,
      view: { viewport: "desktop", withCSS: true, loaded: true, error: null },
    },
    stages: 0,
    displays: 0,
  };
  h.tools = createArticleTools({
    getState: () => h.state,
    stageProposal: (pending) => {
      h.stages++;
      h.state = { ...h.state, pending };
    },
    showPreview: (view) => {
      h.displays++;
      h.state = { ...h.state, view: { ...view, loaded: false, error: null } };
    },
  });
  h.call = async (name, input = {}, options) => {
    const response = await h.tools
      .find((t) => t.name === `sf_${name}`)
      .execute(input, options);
    const value = JSON.parse(response.content[0].text);
    equal(response.isError, !value.ok);
    return value;
  };
  h.proposal = () => ({
    ...proposalFor(h.state.record.article),
    title: "AI proposal",
  });
  h.previewInput = () => ({
    articleId: h.state.record.article.id,
    baseRevision: h.state.record.article.revision,
    proposalId: h.state.pending?.proposalId || null,
    viewport: "mobile",
    withCSS: false,
  });
  return h;
}

await check(
  "only four scoped tools; no save, approve, publish, config or listing tool",
  () => {
    const h = harness();
    equal(
      h.tools.map((t) => t.name),
      [
        "sf_get_article_context",
        "sf_propose_article",
        "sf_show_preview",
        "sf_get_preview_state",
      ],
    );
    equal(
      h.tools.map((t) => t.annotations.readOnlyHint),
      [true, false, false, true],
    );
    assert(h.tools.every((t) => t.annotations.untrustedContentHint));
  },
);
await check(
  "disabled connection rejects even a previously acquired handler",
  async () => {
    const h = harness();
    h.state.enabled = false;
    for (const name of ["get_article_context", "get_preview_state"])
      equal((await h.call(name)).error.code, "CONNECTION_DISABLED");
    equal(
      (await h.call("propose_article", h.proposal())).error.code,
      "CONNECTION_DISABLED",
    );
    equal(
      (await h.call("show_preview", h.previewInput())).error.code,
      "CONNECTION_DISABLED",
    );
    equal(h.stages + h.displays, 0);
  },
);
await check(
  "read context returns only selected article and explicitly limited capabilities",
  async () => {
    const h = harness();
    const response = await h.call("get_article_context");
    equal(response.data.currentArticle, h.state.record.article);
    equal(response.data.capabilities.transport, "webmcp");
    for (const key of [
      "publish",
      "approve",
      "persistDraft",
      "componentMutation",
    ])
      equal(response.data.capabilities[key], false);
    assert(!Object.hasOwn(response.data, "records"));
    response.data.currentArticle.title = "Mutated response";
    equal(h.state.record.article.title, "Tool test");
  },
);
await check(
  "unknown tool keys and attempted authorization are rejected",
  async () => {
    const h = harness();
    equal(
      (await h.call("get_article_context", { articleId: "other" })).error.code,
      "INVALID_TOOL_INPUT",
    );
    equal(
      (await h.call("propose_article", { ...h.proposal(), approval: true }))
        .error.code,
      "INVALID_TOOL_INPUT",
    );
    equal(
      (await h.call("show_preview", { ...h.previewInput(), publish: true }))
        .error.code,
      "INVALID_TOOL_INPUT",
    );
    equal(h.stages + h.displays, 0);
  },
);
await check(
  "tool stages the same article and diff as file import without persisting",
  async () => {
    const h = harness();
    const before = JSON.stringify(h.state.record);
    const proposal = h.proposal();
    const file = prepareProposal(
      JSON.stringify(proposal),
      h.state.record,
      catalog,
    );
    const response = await h.call("propose_article", proposal);
    assert(response.ok);
    equal(response.data.saved, false);
    equal(response.data.published, false);
    equal(JSON.stringify(h.state.record), before);
    equal(h.state.pending.article, file.article);
    equal(h.state.pending.changes, file.changes);
    assert(h.state.pending.proposalId);
    equal(h.stages, 1);
  },
);
await check(
  "duplicate concurrent proposals cannot replace the pending review",
  async () => {
    const h = harness();
    const one = h.call("propose_article", h.proposal());
    const two = h.call("propose_article", h.proposal());
    assert((await one).ok);
    equal((await two).error.code, "PENDING_PROPOSAL");
    equal(h.stages, 1);
  },
);
await check("stale revision and another article never stage", async () => {
  const h = harness();
  equal(
    (await h.call("propose_article", { ...h.proposal(), baseRevision: 100 }))
      .error.code,
    "STALE_REVISION",
  );
  equal(
    (await h.call("propose_article", { ...h.proposal(), articleId: "other" }))
      .error.code,
    "WRONG_ARTICLE",
  );
  equal(h.stages, 0);
});
await check(
  "invalid component and unsafe values reuse canonical validation",
  async () => {
    const h = harness();
    const invalid = h.proposal();
    invalid.blocks[0].componentId = "unknown";
    equal(
      (await h.call("propose_article", invalid)).error.code,
      "UNKNOWN_COMPONENT_REVISION",
    );
    const unsafe = h.proposal();
    unsafe.blocks[0].values.image.value = "javascript:alert(1)";
    equal(
      (await h.call("propose_article", unsafe)).error.code,
      "INVALID_FIELD_VALUE",
    );
    equal(h.stages, 0);
  },
);
await check("oversized input is rejected before schema traversal", async () => {
  const h = harness();
  equal(
    (await h.call("propose_article", { junk: "a".repeat(2000001) })).error.code,
    "PAYLOAD_TOO_LARGE",
  );
});
await check(
  "unresolved questions may preview but cannot be applied",
  async () => {
    const h = harness();
    const p = h.proposal();
    p.questions = ["Which date is confirmed?"];
    assert((await h.call("propose_article", p)).ok);
    const response = await h.call("get_preview_state");
    equal(response.data.canApply, false);
    equal(response.data.questions, p.questions);
    equal(response.data.saved, false);
  },
);
await check(
  "preview identity distinguishes saved draft and pending proposal",
  async () => {
    const h = harness();
    const saved = await h.call("get_preview_state");
    equal(saved.data.kind, "saved-draft");
    equal(saved.data.proposalId, null);
    equal(saved.data.saved, true);
    await h.call("propose_article", h.proposal());
    const pending = await h.call("get_preview_state");
    equal(pending.data.kind, "proposal");
    equal(pending.data.proposalId, h.state.pending.proposalId);
    assert(saved.data.contentDigest !== pending.data.contentDigest);
    equal(pending.data.proposedContentReviewed, false);
  },
);
await check(
  "review receipt belongs only to saved draft, not new proposal",
  async () => {
    const h = harness();
    h.state.record = await markReviewed(h.state.record);
    await h.call("propose_article", h.proposal());
    const response = await h.call("get_preview_state");
    equal(response.data.savedDraftReview.revision, 1);
    equal(response.data.proposedContentReviewed, false);
    equal(response.data.published, false);
  },
);
await check(
  "preview controls require exact article, revision and proposal ID",
  async () => {
    const h = harness();
    await h.call("propose_article", h.proposal());
    for (const [field, value, code] of [
      ["articleId", "other", "WRONG_ARTICLE"],
      ["baseRevision", 50, "STALE_REVISION"],
      ["proposalId", null, "WRONG_PROPOSAL"],
    ])
      equal(
        (await h.call("show_preview", { ...h.previewInput(), [field]: value }))
          .error.code,
        code,
      );
    equal(h.displays, 0);
  },
);
await check(
  "preview switches display only and does not claim visual correctness",
  async () => {
    const h = harness();
    const before = JSON.stringify(h.state.record);
    assert((await h.call("show_preview", h.previewInput())).ok);
    const response = await h.call("get_preview_state");
    equal(response.data.preview.viewport, "mobile");
    equal(response.data.preview.withCSS, false);
    equal(response.data.preview.documentLoaded, false);
    equal(response.data.preview.visualInspectionRequired, true);
    equal(response.data.preview.shareableURL, null);
    equal(JSON.stringify(h.state.record), before);
  },
);
await check("manual dialog and file load block tools", async () => {
  const h = harness();
  h.state.interacting = true;
  equal((await h.call("propose_article", h.proposal())).error.code, "UI_BUSY");
  equal((await h.call("get_article_context")).error.code, "UI_BUSY");
  equal(h.stages, 0);
});
await check("storage conflict blocks reads as well as proposals", async () => {
  const h = harness();
  h.state.storageCurrent = false;
  equal((await h.call("get_article_context")).error.code, "STORAGE_CONFLICT");
  equal(
    (await h.call("propose_article", h.proposal())).error.code,
    "STORAGE_CONFLICT",
  );
  equal(h.stages, 0);
});
await check("cancelled invocation has no side effects", async () => {
  const h = harness();
  const controller = new AbortController();
  controller.abort();
  equal(
    (
      await h.call("propose_article", h.proposal(), {
        signal: controller.signal,
      })
    ).error.code,
    "CANCELLED",
  );
  equal(h.stages, 0);
});
await check(
  "article changes during digest are not reported as current preview",
  async () => {
    const h = harness();
    const response = h.call("get_preview_state");
    h.state = { ...h.state, record: clone(h.state.record) };
    equal((await response).error.code, "STATE_CHANGED");
  },
);
await check("disconnect during digest cancels the stale result", async () => {
  const h = harness();
  const response = h.call("get_preview_state");
  h.state.enabled = false;
  equal((await response).error.code, "CONNECTION_DISABLED");
});
await check(
  "after human apply, earlier proposal revision is stale",
  async () => {
    const h = harness();
    const p = h.proposal();
    await h.call("propose_article", p);
    h.state.record = applyPrepared(h.state.record, h.state.pending);
    h.state.pending = null;
    equal((await h.call("propose_article", p)).error.code, "STALE_REVISION");
    equal((await h.call("get_preview_state")).data.baseRevision, 2);
  },
);

document.querySelector("#result").textContent =
  `${passed} passed, ${failed} failed`;
document.documentElement.dataset.result = failed ? "failed" : "passed";
