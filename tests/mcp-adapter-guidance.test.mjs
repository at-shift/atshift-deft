import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const official = 'https://wordpress.org/plugins/mcp-adapter/';

test('README links the MCP requirement and places installation before AI connection', () => {
  const readme = read('README.md');
  const requirements = readme.split('## Requirements')[1].split('## Permissions')[0];
  assert.ok(requirements.includes(official));
  assert.match(requirements, /0\.7\.1\+ installed and active/);
  assert.ok(readme.indexOf('STEP 3. Install MCP Adapter Before Connecting') < readme.indexOf('STEP 4. Follow the Connection Instructions'));
  for (const path of ['docs/GETTING-STARTED.md', 'wordpress/atshift-deft/README.md', 'wordpress/atshift-deft/readme.txt', 'wordpress/atshift-deft/GETTING-STARTED.ja.md']) {
    assert.match(read(path), /https:\/\/(?:ja\.)?wordpress\.org\/plugins\/mcp-adapter\//, path);
  }
});

test('AI guidance checks the prerequisite before setup and preserves an existing connection', () => {
  const generated = read('wordpress/atshift-deft/includes/class-onboarding.php');
  assert.ok(generated.includes(official));
  assert.ok(generated.indexOf('## Before Connecting: Install MCP Adapter') < generated.indexOf('## Connection Details'));
  assert.match(generated, /version 0\.7\.1 or newer, installed and active/);
  assert.match(generated, /Install Now > Activate/);
  assert.match(generated, /ask for confirmation from the administrator/);
  assert.match(generated, /Do not install, update or activate plugins without explicit approval/);
  assert.match(generated, /Skip this prerequisite when a successful current-session live context response/);
  const standalone = read('wordpress/atshift-deft/AI-GUIDE.md');
  assert.ok(standalone.includes(official));
  assert.match(standalone, /Manual JSON import does not require MCP Adapter/);
  assert.match(standalone, /an offline guide does not prove the current state/);
});

// Render the real PHP template with isolated permission/escaping stubs, without a site or database.
const fixture = String.raw`
namespace Atshift\Deft {
  class Access {
    public static function workspace() { return getenv('TEST_WORKSPACE') === '1'; }
    public static function publisher() { return false; }
  }
  class Onboarding {
    public static function url() { return 'https://example.org/wp-admin/admin-post.php?action=sf_ai_guide'; }
    public static function publication_help($value) {}
  }
}
namespace {
  define('ABSPATH', '/');
  function esc_html_e($value, $domain) { echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
  function esc_attr_e($value, $domain) { esc_html_e($value, $domain); }
  function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
  function self_admin_url($path) { return 'https://example.org/wp-admin/' . $path; }
  function current_user_can($cap) { return $cap === 'install_plugins' && getenv('TEST_INSTALL') === '1'; }
  require $argv[1];
  \Atshift\Deft\connections_page();
}`;

for (const canInstall of [false, true]) {
  test(`connection page shows installation before client selection (install permission: ${canInstall})`, () => {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', fixture,
      fileURLToPath(new URL('../wordpress/atshift-deft/includes/connections-page.php', import.meta.url))],
    { encoding: 'utf8', env: { ...process.env, TEST_WORKSPACE: '1', TEST_INSTALL: canInstall ? '1' : '0' } });
    assert.equal(result.status, 0, result.stderr);
    const dom = new JSDOM(result.stdout);
    try {
      const doc = dom.window.document;
      const section = doc.querySelector('#sf-mcp-prerequisite');
      assert.ok(section);
      assert.ok(section.compareDocumentPosition(doc.querySelector('#sf-connection-client')) & dom.window.Node.DOCUMENT_POSITION_FOLLOWING);
      assert.equal(section.querySelectorAll('ol li').length, 3);
      assert.match(section.textContent, /0\.7\.1 or newer/);
      assert.match(section.textContent, /ask your site administrator/);
      const link = section.querySelector(`a[href="${official}"]`);
      assert.equal(link.target, '_blank');
      assert.ok(link.relList.contains('noopener') && link.relList.contains('noreferrer'));
      const search = section.querySelector('a[href*="plugin-install.php"]');
      assert.equal(Boolean(search), canInstall);
      if (search) {
        const url = new URL(search.href);
        assert.equal(url.searchParams.get('s'), 'MCP Adapter');
        assert.equal(url.searchParams.get('tab'), 'search');
        assert.equal(url.searchParams.has('action'), false, 'link only searches; it cannot install or activate');
      }
    } finally { dom.window.close(); }
  });
}

test('connection page remains inaccessible outside the workspace permission', () => {
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', fixture,
    fileURLToPath(new URL('../wordpress/atshift-deft/includes/connections-page.php', import.meta.url))],
  { encoding: 'utf8', env: { ...process.env, TEST_WORKSPACE: '0', TEST_INSTALL: '0' } });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout, '');
});

test('new connection guidance remains translatable and includes Japanese strings', () => {
  const source = read('wordpress/atshift-deft/includes/connections-page.php').split('<section id="sf-mcp-prerequisite"')[1].split('</section>')[0];
  const pot = read('wordpress/atshift-deft/languages/atshift-deft.pot');
  const po = read('wordpress/atshift-deft/languages/atshift-deft-ja.po');
  const messages = [...source.matchAll(/esc_html_e\( '((?:\\.|[^'])*)', 'atshift-deft' \)/g)].map(match => match[1].replaceAll("\\'", "'"));
  assert.equal(messages.length, 8);
  for (const message of messages) {
    assert.ok(pot.includes(`msgid ${JSON.stringify(message)}`), message);
    const entry = po.split(`msgid ${JSON.stringify(message)}\n`)[1];
    assert.ok(entry && /^msgstr "[^"\n]+"/u.test(entry), message);
  }
});
