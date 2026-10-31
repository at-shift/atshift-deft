import { spawnSync } from "node:child_process";
import { readdirSync, readFileSync, writeFileSync, renameSync, unlinkSync, mkdtempSync, mkdirSync, cpSync, rmSync } from "node:fs";
import { createHash } from "node:crypto";
import { resolve, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../", import.meta.url));
const source = "wordpress/atshift-deft";
const languages = `${source}/languages`;
const cli = process.env.WP_CLI || "wp";
function wp(...args) {
  const result = spawnSync(cli.endsWith(".phar") ? (process.env.PHP_BINARY || "php") : cli,
    [...(cli.endsWith(".phar") ? [cli] : []), "i18n", ...args], { cwd: root, stdio: "inherit" });
  if (result.error) throw new Error("WP-CLI is required. Set WP_CLI to its executable or .phar path.", { cause: result.error });
  if (result.status !== 0) process.exit(result.status || 1);
}
const mode = process.argv[2];
if (mode === "pot") {
  mkdirSync(resolve(root, '.local'), { recursive: true });
  const stage = mkdtempSync(resolve(root, '.local/i18n-source-'));
  try {
    cpSync(resolve(root, source), stage, { recursive: true, filter: path => !/\/(vendor|languages)(\/|$)/.test(path) });
    cpSync(resolve(root, 'distribution/github/class-github-updater.php'), join(stage, 'includes/class-github-updater.php'));
    wp("make-pot", stage, `${languages}/atshift-deft.pot`, "--domain=atshift-deft", "--slug=atshift-deft", "--exclude=vendor,languages",
      '--headers={"POT-Creation-Date":""}', '--file-comment=Copyright (C) 2026 at-shift\nThis file is distributed under the GPLv2 or later.');
  } finally { rmSync(stage, { recursive: true, force: true }); }
} else if (mode === "compile") {
  const directory = resolve(root, process.argv[3] || languages);
  for (const file of readdirSync(directory).sort()) {
    if (!/^atshift-deft-[A-Za-z][A-Za-z0-9_@-]*\.po$/.test(file)) continue;
    wp("make-mo", join(directory, file));
    const prefix = file.slice(0, -3) + "-";
    for (const generated of readdirSync(directory)) {
      if (generated.startsWith(prefix) && /^[a-f0-9]{32}\.json$/.test(generated.slice(prefix.length))) unlinkSync(join(directory, generated));
    }
    // Keep JS entries in PO files for translators and repeatable builds.
    wp("make-json", join(directory, file), directory, "--no-purge");
    // Some WP-CLI versions mistake the end of "admin.js" for ".min.js".
    // Repair only that known source-path rewrite; WordPress hashes "admin.js".
    const badName = prefix + createHash("md5").update("a.js").digest("hex") + ".json";
    if (readdirSync(directory).includes(badName)) {
      const path = join(directory, badName);
      const data = JSON.parse(readFileSync(path, "utf8"));
      if (data.source !== "a.js") throw new Error("Unexpected translation source path.");
      data.source = "admin.js";
      writeFileSync(path, JSON.stringify(data));
      renameSync(path, join(directory, prefix + createHash("md5").update("admin.js").digest("hex") + ".json"));
    }
  }
} else throw new Error("Use pot or compile.");
