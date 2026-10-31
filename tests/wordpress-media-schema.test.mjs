import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import Ajv from "ajv/dist/2020.js";
import { validateSnapshotShape } from "../server/snapshot-schema.mjs";
const schema = JSON.parse(
  await readFile(
    new URL(
      "../wordpress/atshift-deft/schema/snapshot-v2.json",
      import.meta.url,
    ),
  ),
);
const sample = JSON.parse(
  await readFile(
    new URL(
      "../wordpress/atshift-deft/media-sample.json",
      import.meta.url,
    ),
  ),
);
const validate = new Ajv().compile(schema);
test("WordPress v2 accepts optional pinned references while v1 fails closed", () => {
  const value = structuredClone(sample);
  value.article.blocks[0].values.image.assetRef = {
    kind: "staged",
    id: "123",
    sha256: "a".repeat(64),
  };
  assert.equal(validate(value), true);
  assert.equal(validateSnapshotShape(value), false);
});
test("v2 rejects unknown properties, coercion, and unknown reference kinds", () => {
  for (const ref of [
    { kind: "url", id: "1", sha256: "a".repeat(64) },
    { kind: "attachment", id: 123, sha256: "a".repeat(64) },
    { kind: "staged", id: "1", sha256: "bad" },
    { kind: "staged", id: "1", sha256: "a".repeat(64), approved: true },
  ]) {
    const value = structuredClone(sample);
    value.article.blocks[0].values.image.assetRef = ref;
    assert.equal(validate(value), false);
  }
});
