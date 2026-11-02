import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {unzipSync} from 'fflate';

const root = new URL('../', import.meta.url);
const {version} = JSON.parse(readFileSync(new URL('package.json', root)));
const directory = process.env.SF_RELEASE_DIR ? new URL('file://' + process.env.SF_RELEASE_DIR.replace(/\/$/, '') + '/') : new URL('.local/releases/', root);
const githubBytes = readFileSync(new URL(`atshift-deft-${version}.zip`, directory));
const github = unzipSync(githubBytes);
const org = unzipSync(readFileSync(new URL(`atshift-deft-${version}-wordpress-org.zip`, directory)));
const text = bytes => Buffer.from(bytes).toString();

const githubOnly = name => name === 'atshift-deft/includes/class-github-updater.php'
  || name === 'atshift-deft/GETTING-STARTED.ja.md'
  || /^atshift-deft\/languages\/atshift-deft-[A-Za-z]/.test(name);

test('distribution channels differ only in updater and bundled localization', () => {
  const adapter = 'atshift-deft/includes/class-github-updater.php';
  assert.ok(github[adapter]);
  assert.equal(org[adapter], undefined);
  assert.deepEqual(Object.keys(github).filter(name => !githubOnly(name)).sort(), Object.keys(org).sort());
  for (const name of Object.keys(org)) {
    if (name.endsWith('/atshift-deft.php')) continue;
    assert.deepEqual(github[name], org[name], name);
  }
  const entry = 'atshift-deft/atshift-deft.php';
  assert.match(text(github[entry]), /Update URI: https:\/\/github.com\/at-shift\/atshift-deft/);
  assert.match(text(github[entry]), /GitHubUpdater::register/);
  assert.doesNotMatch(text(org[entry]), /Update URI:|GitHubUpdater/);
  for (const [name, bytes] of Object.entries(org)) {
    if (name.endsWith('.php')) assert.doesNotMatch(text(bytes), /update_plugins_github\.com|api\.github\.com\/repos/);
  }
});

test('release manifest pins exact installable ZIP bytes and requirements', () => {
  const m = JSON.parse(readFileSync(new URL('atshift-deft-update.json', directory)));
  assert.equal(m.schemaVersion, 1);
  assert.equal(m.channel, 'github');
  assert.equal(m.version, version);
  assert.equal(m.plugin, 'atshift-deft/atshift-deft.php');
  assert.equal(m.package, `https://github.com/at-shift/atshift-deft/releases/download/v${version}/atshift-deft-${version}.zip`);
  assert.equal(m.sha256, createHash('sha256').update(githubBytes).digest('hex'));
  assert.equal(m.bytes, githubBytes.length);
  const header = text(github['atshift-deft/atshift-deft.php']);
  assert.equal(m.requires, header.match(/Requires at least:\s*(\S+)/)[1]);
  assert.equal(m.requires_php, header.match(/Requires PHP:\s*(\S+)/)[1]);
});

test('GitHub bundles Japanese; WordPress.org keeps English and translation support', () => {
  const files = Object.keys(github).filter(name => name.includes('/languages/'));
  assert.ok(files.some(name => name.endsWith('-ja.mo')));
  assert.ok(files.some(name => /-ja-[a-f0-9]{32}\.json$/.test(name)));
  assert.ok(github['atshift-deft/GETTING-STARTED.ja.md']);
  assert.equal(org['atshift-deft/GETTING-STARTED.ja.md'], undefined);
  assert.deepEqual(Object.keys(org).filter(name => name.includes('/languages/')).sort(),
    ['atshift-deft/languages/README.md', 'atshift-deft/languages/atshift-deft.pot']);
  assert.deepEqual(github['atshift-deft/languages/atshift-deft.pot'], org['atshift-deft/languages/atshift-deft.pot']);
  for (const file of ['atshift-deft.pot', 'atshift-deft-ja.po']) {
    assert.ok(text(github['atshift-deft/languages/' + file]).includes(`Project-Id-Version: atshift DeFt ${version}\\n`));
  }
  for (const archive of [github, org]) assert.ok(!Object.keys(archive).some(name => /\.po~$|\.env$|node_modules|\.git\//.test(name)));
});

test('distributions omit upstream shell build tools and retain an exact dependency manifest', () => {
  for (const archive of [github, org]) {
    assert.ok(!Object.keys(archive).some(name => /\.sh$/i.test(name)));
    const base = 'atshift-deft/vendor/';
    const manifest = JSON.parse(text(archive[base + 'dependency-manifest.json']));
    assert.deepEqual(Object.keys(manifest.files).sort(), Object.keys(archive)
      .filter(name => name.startsWith(base) && name !== base + 'dependency-manifest.json')
      .map(name => name.slice(base.length)).sort());
    for (const [name, hash] of Object.entries(manifest.files)) {
      assert.equal(createHash('sha256').update(archive[base + name]).digest('hex'), hash, name);
    }
    assert.ok(archive[base + 'paragonie/random_compat/LICENSE']);
    assert.ok(archive[base + 'paragonie/random_compat/lib/random.php']);
  }
});

test('distributions exclude development-only trees while preserving compatibility files', () => {
  for (const archive of [github, org]) {
    for (const name of Object.keys(archive)) {
      assert.ok(name.startsWith('atshift-deft/'), name);
      const relative = name.slice('atshift-deft/'.length);
      assert.doesNotMatch(relative, /^(?:src|server|scripts|tests|ai-kit|article-kit|node_modules|dist|public|wordpress-org|\.local|\.git)\//, name);
      assert.doesNotMatch(relative, /^docs\/branding\//, name);
      assert.doesNotMatch(relative, /^(?:package(?:-lock)?\.json|vite\.config\.js|index\.html)$/, name);
      assert.doesNotMatch(relative, /(?:~|\.(?:bak|old|orig|rej|sqlite|sqlite-shm|sqlite-wal))$/, name);
    }
    for (const file of ['includes/legacy-guard.php', 'includes/runtime.php', 'includes/class-private-blobs.php',
      'includes/oauth-entities.php', 'includes/oauth-repositories.php',
      'schema/snapshot.json', 'schema/snapshot-v2.json', 'schema/snapshot-v3.json']) {
      assert.ok(archive['atshift-deft/' + file], file);
    }
    assert.ok(archive['atshift-deft/docs/CONNECTIONS.md']);
  }
});
