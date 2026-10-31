import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import Ajv2020 from "ajv/dist/2020.js";
import { unzipSync } from "fflate";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root));
const json = async (path) => JSON.parse(await read(path));
const schema = await json("ai-kit/schema.json");
const example = await json("ai-kit/example.json");
const manifest = await json("ai-kit/manifest.json");
const validate = new Ajv2020({ allErrors: true }).compile(schema);
const changed = (operation) => {
  const value = structuredClone(example);
  operation(value);
  return value;
};

test("example satisfies the strict AI authoring schema", () => {
  assert.equal(validate(example), true, JSON.stringify(validate.errors));
  assert.equal(example.schemaVersion, manifest.schemaVersion);
});
test("example covers all supported field types", () => {
  assert.deepEqual(example.profiles[0].fields.map((f) => f.type).sort(), [
    "image",
    "link",
    "text",
    "textarea",
  ]);
});
test("unknown field types and string booleans are rejected", () => {
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].fields[0].type = "loop";
      }),
    ),
    false,
  );
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].fields[0].required = "true";
      }),
    ),
    false,
  );
});
test("missing field values and extra authoring properties are rejected", () => {
  assert.equal(
    validate(
      changed((v) => {
        delete v.profiles[0].fields[0].alt;
      }),
    ),
    false,
  );
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].fields[0].aiWrite = true;
      }),
    ),
    false,
  );
});
test("AI output cannot include internal IDs, activation, or permission settings", () => {
  for (const key of ["id", "activeVersion", "versions"]) {
    assert.equal(
      validate(
        changed((v) => {
          v.profiles[0][key] = 1;
        }),
      ),
      false,
    );
  }
  assert.equal(
    validate(
      changed((v) => {
        v.settings = { aiDraft: true };
      }),
    ),
    false,
  );
});
test("machine names and blank labels are rejected", () => {
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].key = "Bad-key";
      }),
    ),
    false,
  );
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].fields[0].label = "  ";
      }),
    ),
    false,
  );
});
test("profile, field, variant, and string limits are enforced", () => {
  for (const operation of [
    (v) => {
      v.profiles = [];
    },
    (v) => {
      v.profiles = Array(51).fill(v.profiles[0]);
    },
    (v) => {
      v.profiles[0].fields = Array(101).fill(v.profiles[0].fields[0]);
    },
    (v) => {
      v.profiles[0].variants = [];
    },
    (v) => {
      v.profiles[0].variants = Array(31).fill(v.profiles[0].variants[0]);
    },
    (v) => {
      v.profiles[0].css = "x".repeat(50001);
    },
    (v) => {
      v.profiles[0].variants[0].name = "x".repeat(201);
    },
    (v) => {
      v.profiles[0].variants[0].classes = "x".repeat(2001);
    },
  ])
    assert.equal(validate(changed(operation)), false);
});
test("variant classes accept a set of tokens but not attribute delimiters", () => {
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].variants[0].classes = "site-left site-emphasis";
      }),
    ),
    true,
  );
  assert.equal(
    validate(
      changed((v) => {
        v.profiles[0].variants[0].classes = 'bad" onclick="bad';
      }),
    ),
    false,
  );
});
test("ZIP contains exactly the four source files with matching bytes", async () => {
  const archive = unzipSync(await read(`public/ai-kit/${manifest.archive}`));
  assert.deepEqual(
    Object.keys(archive).sort(),
    manifest.files.map((f) => f.name).sort(),
  );
  for (const { name } of manifest.files) {
    const source = await read(`ai-kit/${name}`);
    assert.deepEqual(Buffer.from(archive[name]), source);
    assert.deepEqual(await read(`public/ai-kit/${name}`), source);
  }
});
