import { parentPort, workerData } from "node:worker_threads";
import { JSDOM } from "jsdom";
import { validateSnapshotShape } from "./snapshot-schema.mjs";
import { snapshotFor } from "../src/review-snapshot.js";

try {
  const { snapshot, origin } = workerData;
  if (!validateSnapshotShape(snapshot))
    throw new Error("Invalid snapshot shape.");
  const dom = new JSDOM("", { url: origin });
  // A worker-local DOM reuses the browser validator without running scripts or fetching resources.
  globalThis.window = dom.window;
  globalThis.document = dom.window.document;
  globalThis.DOMParser = dom.window.DOMParser;
  globalThis.location = dom.window.location;
  const { decodeArticleStore, encodeArticleStore, renderArticle } =
    await import("../src/article-model.js");
  const record = { ...snapshot, history: [], review: null };
  await decodeArticleStore(encodeArticleStore([record]));
  const normalized = snapshotFor(record);
  if (
    normalized.components.length !== snapshot.components.length ||
    new Set(normalized.components.map((c) => `${c.id}@${c.revision}`)).size !==
      normalized.components.length
  )
    throw new Error("Only unique components used by the article may be saved.");
  for (const component of normalized.components) {
    if (
      new Set(component.variants.map((v) => v.id)).size !==
        component.variants.length ||
      new Set(component.fields.map((f) => f.id)).size !==
        component.fields.length
    )
      throw new Error("Component field and variant IDs must be unique.");
  }
  parentPort.postMessage({
    snapshot: normalized,
    html: renderArticle(normalized.article, normalized.components, true),
  });
  dom.window.close();
} catch (error) {
  parentPort.postMessage({
    error: { code: "INVALID_SNAPSHOT", message: error.message },
  });
}
