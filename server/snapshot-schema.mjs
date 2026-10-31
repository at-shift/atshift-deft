import Ajv2020 from "ajv/dist/2020.js";
import proposal from "../article-kit/proposal.schema.json" with { type: "json" };
const text = { type: "string", maxLength: 50000 };
const id = { type: "string", minLength: 1, maxLength: 80 };
const object = (properties) => ({
  type: "object",
  properties,
  required: Object.keys(properties),
  additionalProperties: false,
});
const list = (items, maxItems) => ({ type: "array", items, maxItems });
export const snapshotSchema = object({
  article: object({
    id,
    revision: {
      ...proposal.properties.baseRevision,
      maximum: Number.MAX_SAFE_INTEGER,
    },
    title: proposal.properties.title,
    blocks: proposal.properties.blocks,
  }),
  components: list(
    object({
      id,
      key: id,
      name: text,
      purpose: text,
      html: text,
      css: text,
      revision: { type: "string", pattern: "^[a-f0-9]{64}$" },
      fields: list(
        object({
          id,
          key: id,
          label: text,
          type: { type: "string", enum: ["text", "textarea", "image", "link"] },
          required: { type: "boolean" },
          value: text,
          alt: text,
        }),
        100,
      ),
      variants: list(object({ id, name: text, classes: text }), 100),
    }),
    50,
  ),
});
export const validateSnapshotShape = new Ajv2020().compile(snapshotSchema);
