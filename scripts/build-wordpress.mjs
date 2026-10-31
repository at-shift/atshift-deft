import { mkdir, readFile, writeFile } from "node:fs/promises";
import { snapshotSchema } from "../server/snapshot-schema.mjs";
import { createHash } from "node:crypto";
import {
  behaviorBindingsSchema,
  behaviorSettingsSchema,
} from "../server/behavior-schema.mjs";
const root = new URL("../wordpress/atshift-deft/", import.meta.url);
await mkdir(new URL("schema/", root), { recursive: true });
await writeFile(
  new URL("schema/snapshot.json", root),
  JSON.stringify(snapshotSchema, null, 2) + "\n",
);
// WordPress v2 keeps media identities; the browser/local-server v1 shape stays strict.
const mediaSchema = structuredClone(snapshotSchema);
mediaSchema.properties.schemaVersion = { type: "integer", enum: [2] };
mediaSchema.required.unshift("schemaVersion");
mediaSchema.properties.article.properties.blocks.items.properties.values.additionalProperties.properties.assetRef =
  {
    type: "object",
    properties: {
      kind: { type: "string", enum: ["staged", "attachment"] },
      id: { type: "string", minLength: 1, maxLength: 80 },
      sha256: { type: "string", pattern: "^[a-f0-9]{64}$" },
    },
    required: ["kind", "id", "sha256"],
    additionalProperties: false,
  };
await writeFile(
  new URL("schema/snapshot-v2.json", root),
  JSON.stringify(mediaSchema, null, 2) + "\n",
);
const behaviorSchema = structuredClone(mediaSchema);
behaviorSchema.properties.schemaVersion.enum = [3];
behaviorSchema.properties.components.items.properties.behaviors =
  behaviorBindingsSchema;
behaviorSchema.properties.article.properties.blocks.items.properties.behaviorSettings =
  {
    type: "object",
    maxProperties: 4,
    propertyNames: { pattern: "^[a-z][a-z0-9_]{0,39}$" },
    additionalProperties: behaviorSettingsSchema,
  };
await writeFile(
  new URL("schema/snapshot-v3.json", root),
  JSON.stringify(behaviorSchema, null, 2) + "\n",
);
const behaviorAssets = {};
for (const file of [
  "fade-in-v1.css",
  "image-zoom-v1.css",
  "image-zoom-v1.js",
]) {
  behaviorAssets[file] = createHash("sha256")
    .update(await readFile(new URL("behaviors/" + file, root)))
    .digest("hex");
}
await writeFile(
  new URL("behaviors/manifest.json", root),
  JSON.stringify(behaviorAssets, null, 2) + "\n",
);
// A portable fixture: media paths belong to the installed plugin, not the Vite origin.
const component = {
  id: "intro",
  key: "intro",
  name: "Introduction",
  purpose: "Article introduction",
  html: '<section class="sf-intro"><h2 data-field="heading">A new way to compose</h2><p data-field="body">Build an article from reusable, meaningful components.</p></section>',
  css: ".sf-intro{border-left:4px solid #087f79;padding:12px 24px}.sf-intro h2{margin-top:0}",
  fields: [
    {
      id: "heading",
      key: "heading",
      label: "Heading",
      type: "text",
      required: true,
      value: "A new way to compose",
      alt: "",
    },
    {
      id: "body",
      key: "body",
      label: "Body",
      type: "textarea",
      required: true,
      value: "Build an article from reusable, meaningful components.",
      alt: "",
    },
  ],
  variants: [{ id: "default", name: "Default", classes: "" }],
};
component.revision = createHash("sha256")
  .update(JSON.stringify(component))
  .digest("hex");
const sample = {
  article: {
    id: "wordpress-sample",
    revision: 1,
    title: "atshift DEFT on WordPress",
    blocks: [
      {
        id: "intro-one",
        componentId: component.id,
        componentRevision: component.revision,
        variantId: "default",
        values: Object.fromEntries(
          component.fields.map((f) => [f.key, { value: f.value, alt: f.alt }]),
        ),
      },
    ],
  },
  components: [component],
};
await writeFile(
  new URL("sample.json", root),
  JSON.stringify(sample, null, 2) + "\n",
);
console.log("WordPress schema and sample built.");
const media = {
  id: "media",
  key: "media",
  name: "Article resources",
  purpose: "An image and a PDF download",
  html: '<section class="sf-resources"><h2>Resources</h2><img data-field="image" src="" alt=""><p><a data-field="document" href=""></a></p></section>',
  css: ".sf-resources{padding:16px 0}.sf-resources img{display:block;max-width:100%;height:auto}.sf-resources img[hidden]{display:none}.sf-resources a{font-weight:600}",
  fields: [
    {
      id: "image",
      key: "image",
      label: "Article image",
      type: "image",
      required: false,
      value: "",
      alt: "",
    },
    {
      id: "document",
      key: "document",
      label: "Download PDF",
      type: "link",
      required: false,
      value: "",
      alt: "",
    },
  ],
  variants: [{ id: "default", name: "Default", classes: "" }],
};
media.revision = createHash("sha256")
  .update(JSON.stringify(media))
  .digest("hex");
await writeFile(
  new URL("media-sample.json", root),
  JSON.stringify(
    {
      schemaVersion: 2,
      article: {
        id: "wordpress-media-sample",
        revision: 1,
        title: "Article with private media",
        blocks: [
          {
            id: "resources-one",
            componentId: media.id,
            componentRevision: media.revision,
            variantId: "default",
            values: Object.fromEntries(
              media.fields.map((f) => [f.key, { value: f.value, alt: f.alt }]),
            ),
          },
        ],
      },
      components: [media],
    },
    null,
    2,
  ) + "\n",
);
