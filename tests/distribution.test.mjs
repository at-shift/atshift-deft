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

test('distribution channels differ only in updater bootstrap and implementation', () => {
  const adapter = 'atshift-deft/includes/class-github-updater.php';
  assert.ok(github[adapter]);
  assert.equal(org[adapter], undefined);
  assert.deepEqual(Object.keys(github).filter(name => name !== adapter).sort(), Object.keys(org).sort());
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

test('both channels carry identical matching translation catalogs and no backups', () => {
  const files = Object.keys(org).filter(name => name.includes('/languages/'));
  assert.ok(files.some(name => name.endsWith('-ja.mo')));
  assert.ok(files.some(name => /-ja-[a-f0-9]{32}\.json$/.test(name)));
  for (const name of files) assert.deepEqual(github[name], org[name]);
  for (const file of ['atshift-deft.pot', 'atshift-deft-ja.po']) {
    assert.ok(text(org['atshift-deft/languages/' + file]).includes(`Project-Id-Version: atshift DEFT ${version}\\n`));
  }
  assert.ok(!Object.keys(org).some(name => /\.po~$|\.env$|node_modules|\.git\//.test(name)));
});
