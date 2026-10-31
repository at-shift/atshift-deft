import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { unzipSync } from 'fflate';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const plugin = 'wordpress/atshift-deft/';
const pkg = JSON.parse(read('package.json'));

test('package, plugin, text domain and readme agree on the DEFT identity', () => {
  const lock = JSON.parse(read('package-lock.json'));
  const entry = read(`${plugin}atshift-deft.php`);
  assert.equal(pkg.name, 'atshift-deft');
  assert.equal(lock.name, pkg.name);
  assert.equal(lock.packages[''].name, pkg.name);
  assert.equal(lock.version, pkg.version);
  assert.equal(lock.packages[''].version, pkg.version);
  assert.match(entry, /Plugin Name: atshift DEFT\n/);
  assert.match(entry, /Text Domain: atshift-deft\n/);
  assert.equal(entry.match(/Version: (\S+)/)[1], pkg.version);
  assert.equal(entry.match(/const VERSION = '([^']+)'/)[1], pkg.version);
  assert.equal(read(`${plugin}readme.txt`).match(/Stable tag: (\S+)/)[1], pkg.version);
  assert.ok(!existsSync(new URL('../wordpress/structured-fields/structured-fields.php', import.meta.url)));
});

test('data identifiers, API routes, OAuth issuer and browser storage stay compatible', () => {
  assert.match(read(`${plugin}includes/class-oauth.php`), /home_url\( '\/structured-fields-oauth' \)/);
  const connections = read(`${plugin}includes/class-connections.php`);
  assert.ok(connections.includes('/structured-fields-mcp/v1/mcp'));
  assert.ok(connections.includes('/structured-fields-mcp/v1/publishing'));
  assert.ok(read(`${plugin}includes/plugin.php`).includes("register_rest_route( 'structured-fields/v1'"));
  assert.ok(read('src/article-model.js').includes('structured-fields.articles.prototype.v1'));
  assert.ok(read('src/model.js').includes('structured-fields.'));
  assert.ok(read(`${plugin}includes/class-access.php`).includes("add_role( 'sf_author'"));
  assert.ok(read(`${plugin}includes/class-store.php`).includes("'sf_"));
});

test('plugin URLs use the root entry after bootstrap extraction', () => {
  const implementation = read(`${plugin}includes/plugin.php`);
  assert.ok(implementation.includes("plugins_url( 'admin.css', PLUGIN_FILE )"));
  assert.ok(implementation.includes('register_activation_hook( PLUGIN_FILE,'));
  assert.ok(!implementation.includes('__FILE__'));
  assert.ok(implementation.includes("get_plugin_page_hookname( 'structured-fields-connections'"));
  for (const name of ['class-behaviors.php', 'class-oauth-consent.php']) {
    assert.ok(read(`${plugin}includes/${name}`).includes("'/atshift-deft.php'"));
  }
});

test('installable ZIP has only the new plugin root and includes guarded bootstrap', () => {
  const file = new URL(`../.local/releases/atshift-deft-${pkg.version}.zip`, import.meta.url);
  const files = unzipSync(readFileSync(file));
  assert.ok(Object.keys(files).length > 400);
  assert.ok(Object.keys(files).every((name) => name.startsWith('atshift-deft/') && !name.includes('structured-fields.php')));
  assert.equal(Buffer.from(files['atshift-deft/LICENSE']).toString(), read('LICENSE'));
  for (const path of ['atshift-deft.php', 'includes/plugin.php', 'includes/legacy-guard.php', 'includes/runtime.php', 'readme.txt']) {
    assert.equal(Buffer.from(files[`atshift-deft/${path}`]).toString(), read(`${plugin}${path}`));
  }
});
