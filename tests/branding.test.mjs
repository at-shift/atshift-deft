import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { unzipSync } from 'fflate';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const plugin = 'wordpress/atshift-deft/';
const pkg = JSON.parse(read('package.json'));

test('package, plugin, text domain and readme agree on the DeFt identity', () => {
  const lock = JSON.parse(read('package-lock.json'));
  const entry = read(`${plugin}atshift-deft.php`);
  assert.equal(pkg.name, 'atshift-deft');
  assert.equal(lock.name, pkg.name);
  assert.equal(lock.packages[''].name, pkg.name);
  assert.equal(lock.version, pkg.version);
  assert.equal(lock.packages[''].version, pkg.version);
  assert.match(entry, /Plugin Name: atshift DeFt\n/);
  assert.match(entry, /Text Domain: atshift-deft\n/);
  assert.equal(entry.match(/Version: (\S+)/)[1], pkg.version);
  assert.equal(entry.match(/const VERSION = '([^']+)'/)[1], pkg.version);
  assert.equal(read(`${plugin}readme.txt`).match(/Stable tag: (\S+)/)[1], pkg.version);
  for (const name of ['atshift-deft.pot', 'atshift-deft-ja.po']) {
    assert.ok(read(`${plugin}languages/${name}`).includes(`Project-Id-Version: atshift DeFt ${pkg.version}\\n`), name);
  }
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
  assert.ok(read(`${plugin}includes/class-access.php`).includes("add_role( 'atshde_author'"));
  assert.ok(read(`${plugin}includes/class-store.php`).includes("'sf_"));
  assert.ok(read(`${plugin}includes/plugin.php`).includes("if ( 2 !== (int) get_option( 'atshde_brand_version' ) ) Access::update_brand_labels();"));
});

test('current product surfaces use DeFt without changing the slug', () => {
  for (const path of [
    'README.md', 'index.html', 'src/App.jsx', 'src/ArticleWorkspace.jsx',
    'docs/GETTING-STARTED.md', 'ai-kit/GUIDE.md', 'ai-kit/REQUEST.md',
    `${plugin}AI-GUIDE.md`, `${plugin}GETTING-STARTED.ja.md`, `${plugin}docs/CONNECTIONS.md`,
    `${plugin}includes/class-onboarding.php`, `${plugin}includes/class-mcp.php`,
    `${plugin}includes/class-access.php`, `${plugin}includes/class-reservations.php`,
    `${plugin}includes/runtime.php`, `${plugin}includes/legacy-guard.php`,
    'distribution/github/class-github-updater.php',
  ]) {
    assert.doesNotMatch(read(path), /\bDEFT\b/, path);
    assert.match(read(path), /DeFt/, path);
  }
  assert.match(read(`${plugin}readme.txt`), /^=== atshift DeFt ===/);
  assert.doesNotMatch(read(`${plugin}readme.txt`).split('== Changelog ==')[0], /\bDEFT\b/);
  assert.match(read(`${plugin}includes/i18n.php`), /namespace Atshift\\Deft;/);
  assert.match(read(`${plugin}includes/class-access.php`), /update_option\( 'atshde_brand_version', 2, false \)/);
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

test('brand icons have exact dimensions and matching directory copies', () => {
  for (const size of [20, 128, 256]) {
    const name = `icon-${size}x${size}.png`;
    const bytes = readFileSync(new URL(`../${plugin}assets/${name}`, import.meta.url));
    assert.deepEqual(bytes.subarray(0, 8), Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]));
    assert.equal(bytes.readUInt32BE(16), size);
    assert.equal(bytes.readUInt32BE(20), size);
    assert.ok(bytes.length < 1024 * 1024);
    if (size > 20) {
      assert.deepEqual(bytes, readFileSync(new URL(`../wordpress-org/assets/${name}`, import.meta.url)));
    }
  }
  const implementation = read(`${plugin}includes/plugin.php`);
  assert.ok(implementation.includes("plugins_url( 'assets/icon-20x20.png', PLUGIN_FILE ), 81"));
  assert.match(implementation, /class="sf-brand-heading"><img[^>]+/);
  assert.ok(implementation.includes('width="48" height="48" alt="" aria-hidden="true"'));
  assert.ok(read('README.md').includes('wordpress/atshift-deft/assets/icon-128x128.png'));
});

test('installable ZIP has only the new plugin root and includes guarded bootstrap', () => {
  const file = process.env.SF_TEST_ZIP || new URL(`../.local/releases/atshift-deft-${pkg.version}.zip`, import.meta.url);
  const files = unzipSync(readFileSync(file));
  assert.ok(Object.keys(files).length > 400);
  assert.ok(Object.keys(files).every((name) => name.startsWith('atshift-deft/') && !name.includes('structured-fields.php')));
  assert.equal(Buffer.from(files['atshift-deft/LICENSE']).toString(), read('LICENSE'));
  const entry = Buffer.from(files['atshift-deft/atshift-deft.php']).toString();
  const normalized = entry.replace(' * Update URI: https://github.com/at-shift/atshift-deft\n', '')
    .replace("require_once __DIR__ . '/includes/class-github-updater.php';\n\\Atshift\\Deft\\GitHubUpdater::register( __FILE__ );\n", '');
  assert.equal(normalized, read(`${plugin}atshift-deft.php`));
  assert.equal(entry.includes('GitHubUpdater'), Boolean(files['atshift-deft/includes/class-github-updater.php']));
  for (const path of ['includes/plugin.php', 'includes/class-component-appearance.php', 'includes/legacy-guard.php', 'includes/runtime.php', 'admin.css', 'readme.txt']) {
    assert.equal(Buffer.from(files[`atshift-deft/${path}`]).toString(), read(`${plugin}${path}`));
  }
  for (const size of [20, 128, 256]) {
    const path = `assets/icon-${size}x${size}.png`;
    assert.deepEqual(Buffer.from(files[`atshift-deft/${path}`]), readFileSync(new URL(`../${plugin}${path}`, import.meta.url)));
  }
});
