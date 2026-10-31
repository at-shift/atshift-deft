import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import Ajv2020 from "ajv/dist/2020.js";
import { zipSync } from "fflate";

const root = fileURLToPath(new URL("../", import.meta.url));
const source = resolve(root, "ai-kit");
const output = resolve(root, "public/ai-kit");
const manifest = JSON.parse(
  await readFile(resolve(source, "manifest.json"), "utf8"),
);
const files = {};
for (const { name } of manifest.files) {
  files[name] = new Uint8Array(await readFile(resolve(source, name)));
}
const decode = (name) => JSON.parse(new TextDecoder().decode(files[name]));
const validate = new Ajv2020({ allErrors: true }).compile(
  decode("schema.json"),
);
if (!validate(decode("example.json"))) {
  throw new Error(
    `AI kit example does not match its schema: ${JSON.stringify(validate.errors)}`,
  );
}

await mkdir(output, { recursive: true });
async function writeChanged(name, bytes) {
  const path = resolve(output, name);
  try {
    if ((await readFile(path)).equals(Buffer.from(bytes))) return;
  } catch (error) {
    if (error.code !== "ENOENT") throw error;
  }
  await writeFile(path, bytes);
}
for (const [name, bytes] of Object.entries(files))
  await writeChanged(name, bytes);
// A fixed timestamp makes the archive reproducible across builds and time zones.
const archive = zipSync(
  Object.fromEntries(
    Object.entries(files).map(([name, bytes]) => [
      name,
      [bytes, { mtime: new Date(1980, 0, 1, 0, 0, 0) }],
    ]),
  ),
  { level: 6 },
);
await writeChanged(manifest.archive, archive);
console.log(
  `AI kit v${manifest.version}: ${manifest.files.length} files, ${archive.length} bytes`,
);
