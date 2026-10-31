import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, writeFileSync, readdirSync, mkdtempSync, rmSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import { runInNewContext } from "node:vm";
import { unzipSync } from "fflate";
import { installI18n } from "./wp-i18n-fixture.mjs";

const plugin = new URL("../wordpress/atshift-deft/", import.meta.url);
const { version } = JSON.parse(readFileSync(new URL("../package.json", import.meta.url), "utf8"));
const read = file => readFileSync(new URL(file, plugin), "utf8");
test("POT includes PHP, JS, plurals and placeholders but not machine contracts", () => {
  const pot = read("languages/atshift-deft.pot");
  for (const message of ['msgid "Components"', 'msgid "Allow this access"', 'msgid "Request failed."', 'msgid_plural "%d files received privately. Not published."']) assert.ok(pot.includes(message), message);
  for (const file of ["admin.js", "catalog.js", "connections.js", "connection-profiles.js"]) assert.ok(pot.includes(`#: ${file}:`));
  assert.doesNotMatch(pot, /msgid "(?:sf:drafts|sf_publish_articles|structured-fields\/v1|Bearer |\[mcp_servers)/);
});

test("a translator PO compiles to MO and four path-hashed JS catalogs", () => {
  const dir = mkdtempSync(join(tmpdir(), "deft-catalog-"));
  try {
    const entries = [
      ['admin.js', 'No article selected', 'Aucun article'],
      ['catalog.js', 'Component loaded.', 'Composant charge.'],
      ['connections.js', 'No connections.', 'Aucune connexion.'],
      ['connection-profiles.js', 'Choose your AI and client first.', 'Choisissez votre IA.'],
    ];
    const po = 'msgid ""\nmsgstr ""\n"Language: fr_FR\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Plural-Forms: nplurals=2; plural=(n > 1);\\n"\n\n' +
      entries.map(([file,en,fr]) => `#: ${file}:1\nmsgid ${JSON.stringify(en)}\nmsgstr ${JSON.stringify(fr)}\n`).join('\n') +
      '\n#: admin.js:1\nmsgid "%d file received privately. Not published."\nmsgid_plural "%d files received privately. Not published."\nmsgstr[0] "%d fichier prive."\nmsgstr[1] "%d fichiers prives."\n' +
      '\n#: admin.js:1\nmsgid "Article r%1$s · %2$s"\nmsgstr "%2$s · Article r%1$s"\n';
    const path = join(dir, "atshift-deft-fr_FR.po"); writeFileSync(path, po);
    const stale = 'atshift-deft-fr_FR-' + '0'.repeat(32) + '.json';
    writeFileSync(join(dir, stale), '{}');
    const compiled = spawnSync(process.execPath, [fileURLToPath(new URL("../scripts/i18n.mjs", import.meta.url)), "compile", dir], {encoding:"utf8", env:process.env});
    assert.equal(compiled.status, 0, compiled.stdout + compiled.stderr);
    assert.ok(!readdirSync(dir).includes(stale), 'obsolete generated catalogs are removed only for the compiled locale');
    assert.equal(readFileSync(path, "utf8"), po, "compilation must preserve translator source");
    assert.ok(readFileSync(join(dir, "atshift-deft-fr_FR.mo")).length > 100);
    const window = {}; const i18n = installI18n(window);
    for (const [file,en,fr] of entries) {
      const hash = createHash("md5").update(file).digest("hex");
      assert.ok(readdirSync(dir).includes(`atshift-deft-fr_FR-${hash}.json`), readdirSync(dir).join(', '));
      const json = JSON.parse(readFileSync(join(dir, `atshift-deft-fr_FR-${hash}.json`), "utf8"));
      i18n.setLocaleData(json.locale_data.messages, "atshift-deft");
      assert.equal(i18n.__(en, "atshift-deft"), fr);
    }
    runInNewContext(read("connection-profiles.js"), {window, URL});
    assert.equal(window.sfConnectionProfiles.route("", "https://example.org/mcp").reason, "Choisissez votre IA.");
    const template = window.sfConnectionProfiles.template("claude-code", "https://example.org/mcp");
    assert.ok(JSON.parse(template.text).mcpServers);
    assert.match(template.text, /Bearer/);
    assert.equal(i18n.sprintf(i18n._n('%d file received privately. Not published.', '%d files received privately. Not published.', 2, 'atshift-deft'), 2), '2 fichiers prives.');
    assert.equal(i18n.sprintf(i18n.__('Article r%1$s · %2$s', 'atshift-deft'), 3, 'Brouillon'), 'Brouillon · Article r3');
    assert.equal(i18n.__("Untranslated example", "atshift-deft"), "Untranslated example");
  } finally { rmSync(dir, {recursive:true, force:true}); }
});

test("distribution contains current translation files and excludes backups", () => {
  const zip = unzipSync(readFileSync(new URL(`../.local/releases/atshift-deft-${version}.zip`, import.meta.url)));
  for (const name of readdirSync(new URL("languages/", plugin))) {
    if (!/\.(pot|po|mo|json)$/.test(name)) continue;
    const data = zip[`atshift-deft/languages/${name}`];
    assert.ok(data, name);
    assert.deepEqual(Buffer.from(data), readFileSync(new URL(`languages/${name}`, plugin)));
  }
  assert.ok(zip['atshift-deft/includes/i18n.php']);
  assert.ok(!Object.keys(zip).some(p=>/\.po~$|deft-catalog-/.test(p)));
});
