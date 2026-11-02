import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';

const root = new URL('../wordpress/atshift-deft/', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');

test('first-party PHP declarations use the product-specific namespace', () => {
  for (const path of ['atshift-deft.php', ...readdirSync(new URL('includes/', root)).filter(p => p.endsWith('.php')).map(p => 'includes/' + p)]) {
    const text = read(path);
    assert.match(text, /namespace Atshift\\Deft(?:\\[A-Za-z]+)*;/, path);
    assert.doesNotMatch(text, /namespace StructuredFields|class_alias\s*\(/, path);
    if (!path.endsWith('legacy-guard.php')) assert.doesNotMatch(text, /StructuredFields/, path);
  }
  assert.match(read('includes/legacy-guard.php'), /defined\( 'StructuredFields\\\\VERSION' \)/);
});

test('WordPress asset handles and browser globals use the product-specific prefix', () => {
  for (const path of readdirSync(new URL('includes/', root)).filter(p => p.endsWith('.php'))) {
    assert.doesNotMatch(read('includes/' + path), /wp_(?:register|enqueue|localize|add_inline|set_script_translations)[a-z_]*\( 'sf-/, path);
  }
  assert.ok(read('includes/plugin.php').includes('window.atshiftDeftWordPress='));
  for (const file of ['admin.js', 'catalog.js', 'connections.js', 'connection-profiles.js']) {
    assert.doesNotMatch(read(file), /\bsfWordPress\b|\bsfConnectionProfiles\b/);
  }
  assert.match(read('connection-profiles.js'), /window\.atshiftDeftConnectionProfiles =/);
});

test('rebuilt vendor manifest uses the isolated product namespace', () => {
  const manifest = JSON.parse(read('vendor/dependency-manifest.json'));
  assert.equal(manifest.namespacePrefix, 'Atshift\\Deft\\Vendor\\');
  for (const path of Object.keys(manifest.files).filter(p => p.endsWith('.php'))) {
    assert.doesNotMatch(read('vendor/' + path), /StructuredFields\\/, path);
  }
});
