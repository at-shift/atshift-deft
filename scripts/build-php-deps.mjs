import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { spawnSync } from "node:child_process";
import { access, cp, mkdir, readFile, readdir, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs } from "node:util";

const { values } = parseArgs({ options: {
  composer: { type: "string" }, php: { type: "string" }, help: { type: "boolean" },
} });
if (values.help) {
  console.log("Usage: node scripts/build-php-deps.mjs [--composer /path/to/composer.phar] [--php /path/to/php]\nEnvironment: COMPOSER_BINARY, PHP_BINARY. Requires PHP 8.2+; installs only locked versions.\nWithout Composer on PATH, downloads a checksum-verified official PHAR into .local/tools.");
  process.exit(0);
}

const root = fileURLToPath(new URL("../", import.meta.url));
const local = path.join(root, ".local");
const work = path.join(local, "php-deps");
const tools = path.join(local, "tools");
const target = path.join(root, "wordpress/atshift-deft/vendor");
const php = values.php ?? process.env.PHP_BINARY ?? "php";
const prefix = "Atshift\\Deft\\Vendor\\";
// Official release checksum: https://getcomposer.org/download/2.11.1/composer.phar.sha256sum
const composerVersion = "2.11.1";
const composerSha256 = "6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c";
const env = { ...process.env,
  COMPOSER_HOME: path.join(tools, "composer-home"),
  COMPOSER_CACHE_DIR: path.join(tools, "composer-cache"),
  COMPOSER_ROOT_VERSION: "0.0.0",
  COMPOSER_NO_INTERACTION: "1",
};
// Composer reserves these variables for manifest/vendor overrides, not its executable.
for (const name of ["COMPOSER", "COMPOSER_VENDOR_DIR", "COMPOSER_BIN_DIR", "COMPOSER_NO_DEV",
  "COMPOSER_IGNORE_PLATFORM_REQ", "COMPOSER_IGNORE_PLATFORM_REQS"]) delete env[name];

function run(command, args, cwd = root, capture = false) {
  const result = spawnSync(command, args, { cwd, env, encoding: "utf8",
    stdio: capture ? "pipe" : "inherit", maxBuffer: 16 * 1024 * 1024 });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    if (capture) process.stderr.write((result.stdout ?? "") + (result.stderr ?? ""));
    throw new Error(`${command} ${args[0]} failed (${result.status ?? result.signal})`);
  }
  return (result.stdout ?? "").trim();
}

async function exists(filename) {
  try { await access(filename); return true; } catch { return false; }
}

const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");
async function files(directory, base = directory) {
  const result = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const filename = path.join(directory, entry.name);
    assert(!entry.isSymbolicLink(), `Unexpected symlink: ${filename}`);
    if (entry.isDirectory()) result.push(...await files(filename, base));
    else result.push(path.relative(base, filename).split(path.sep).join("/"));
  }
  return result.sort();
}

async function resolveComposer() {
  const requested = values.composer ?? process.env.COMPOSER_BINARY;
  if (requested) return requested.endsWith(".phar") ? [php, path.resolve(requested)] : [requested];
  const probe = spawnSync("composer", ["--no-plugins", "--no-scripts", "--version"], { env, encoding: "utf8" });
  if (!probe.error && probe.status === 0) return ["composer"];
  await mkdir(tools, { recursive: true });
  const phar = path.join(tools, "composer.phar");
  if (!await exists(phar)) {
    const response = await fetch(`https://getcomposer.org/download/${composerVersion}/composer.phar`);
    assert(response.ok, `Composer download failed: HTTP ${response.status}`);
    const bytes = Buffer.from(await response.arrayBuffer());
    assert.equal(sha256(bytes), composerSha256, "Official Composer PHAR checksum mismatch");
    await writeFile(`${phar}.tmp`, bytes);
    await rename(`${phar}.tmp`, phar);
  }
  assert.equal(sha256(await readFile(phar)), composerSha256,
    "Local Composer PHAR checksum mismatch; use --composer for a different trusted Composer");
  console.log(`Verified Composer ${composerVersion}: SHA-256 ${composerSha256}`);
  return [php, phar];
}

assert(Number(run(php, ["-r", "echo PHP_VERSION_ID;"], root, true)) >= 80200,
  "The dependency build requires PHP 8.2+.");
assert(await exists(path.join(root, "composer.lock")), "composer.lock is required; builds never resolve updates.");
await mkdir(work, { recursive: true });
const mutex = path.join(work, "build.lock");
try { await mkdir(mutex); } catch {
  throw new Error(`Dependency build already running, or interrupted: inspect ${mutex} before removing it.`);
}

try {
  const [executable, ...composerArgs] = await resolveComposer();
  const composer = (args, cwd = root, capture = false) => run(executable,
    [...composerArgs, "--no-interaction", "--no-plugins", "--no-scripts", ...args], cwd, capture);
  const composerIdentity = composer(["--version"], root, true);
  composer(["validate", "--strict", "--no-check-publish"]);
  // Audit both runtime and build-time packages before executing the build tool.
  const audit = composer(["audit", "--locked", "--format=json"], root, true);
  await writeFile(path.join(work, "audit.json"), `${audit}\n`);

  const lockBytes = await readFile(path.join(root, "composer.lock"));
  const lock = JSON.parse(lockBytes);
  const buildTools = path.join(work, "tools");
  const runtime = path.join(work, "runtime");
  const scoped = path.join(work, "scoped");
  const loader = path.join(work, "loader");
  for (const directory of [buildTools, runtime, scoped, loader]) {
    await rm(directory, { recursive: true, force: true });
    await mkdir(directory, { recursive: true });
  }
  for (const directory of [buildTools, runtime]) {
    for (const filename of ["composer.json", "composer.lock"]) {
      await cp(path.join(root, filename), path.join(directory, filename));
    }
  }
  composer(["install", "--prefer-dist", "--no-progress"], buildTools);
  composer(["install", "--no-dev", "--prefer-dist", "--no-progress"], runtime);
  composer(["check-platform-reqs", "--no-dev"], runtime);
  const runtimeAudit = composer(["audit", "--locked", "--no-dev", "--format=json"], runtime, true);
  await writeFile(path.join(work, "audit-runtime.json"), `${runtimeAudit}\n`);

  const scoper = path.join(buildTools, "vendor/bin/php-scoper");
  const scope = (cwd, output, inputs) => run(php, [scoper, "add-prefix", ...inputs,
    "--config", path.join(root, "scoper.inc.php"), "--output-dir", output,
    "--force", "--no-interaction", "--no-ansi"], cwd);
  scope(runtime, scoped, ["composer.json", "vendor"]);
  composer(["dump-autoload", "--no-dev", "--classmap-authoritative", "--strict-psr"], scoped);
  // Composer regenerates its own unprefixed classes; isolate those in a second pass.
  scope(scoped, loader, ["vendor/autoload.php", "vendor/composer"]);
  await cp(path.join(loader, "composer"), path.join(scoped, "vendor/composer"), { recursive: true });
  const output = path.join(scoped, "vendor");
  await cp(path.join(loader, "autoload.php"), path.join(output, "autoload-scoped.php"));
  await writeFile(path.join(output, "autoload.php"), `<?php

// The plugin bootstrap handles the admin notice; never load dependencies below PHP 8.2.
if (PHP_VERSION_ID < 80200) {
    return false;
}

return require __DIR__ . '/autoload-scoped.php';
`);
  await rm(path.join(output, "bin"), { recursive: true, force: true });
  await rm(path.join(output, "scoper-autoload.php"), { force: true });

  const packages = [];
  for (const pkg of lock.packages) {
    const original = path.join(runtime, "vendor", pkg.name);
    const licenses = (await files(original)).filter((name) => /^(?:licen[cs]e|copying|copyright|notice)(?:[.-]|$)/i.test(path.basename(name)));
    assert(licenses.length, `Missing license/notice files for ${pkg.name}`);
    for (const name of licenses) assert.deepEqual(
      await readFile(path.join(output, pkg.name, name)), await readFile(path.join(original, name)),
      `License/notice changed: ${pkg.name}/${name}`);
    packages.push({ name: pkg.name, version: pkg.version, reference: pkg.source?.reference ?? pkg.dist?.reference,
      license: pkg.license, licenseFiles: licenses });
  }
  const installed = JSON.parse(await readFile(path.join(output, "composer/installed.json"), "utf8"));
  assert.equal(installed.dev, false);
  assert.deepEqual(installed.packages.map((pkg) => pkg.name).sort(), lock.packages.map((pkg) => pkg.name).sort());
  for (const pkg of lock["packages-dev"]) assert(!await exists(path.join(output, pkg.name)), `Dev dependency bundled: ${pkg.name}`);
  assert.deepEqual(await readFile(path.join(output, "composer/LICENSE")),
    await readFile(path.join(runtime, "vendor/composer/LICENSE")), "Composer license changed");

  run(php, ["-r", String.raw`
$vendor = $argv[1];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
}
$loader = require $vendor . '/autoload.php';
$prefix = 'Atshift\\Deft\\Vendor\\';
foreach ($loader->getClassMap() as $class => $file) {
    if (!str_starts_with($class, $prefix)) throw new RuntimeException('Unprefixed class: ' . $class);
}
foreach (['League\\OAuth2\\Server\\AuthorizationServer', 'League\\OAuth2\\Server\\ResourceServer', 'League\\OAuth2\\Server\\Grant\\AuthCodeGrant', 'League\\OAuth2\\Server\\Grant\\RefreshTokenGrant'] as $class) {
    if (!class_exists($prefix . $class)) throw new RuntimeException('Cannot load: ' . $class);
}
foreach (['League\\OAuth2\\Server\\AuthorizationServer', 'Nyholm\\Psr7\\Response', 'Psr\\Http\\Message\\ResponseInterface', 'Composer\\Autoload\\ClassLoader'] as $class) {
    if (class_exists($class, false) || interface_exists($class, false)) throw new RuntimeException('Leaked symbol: ' . $class);
}
$factory = new Atshift\Deft\Vendor\Nyholm\Psr7\Factory\Psr17Factory();
$request = $factory->createServerRequest('POST', 'https://example.test/token')->withParsedBody(['grant_type' => 'authorization_code']);
if (!$request instanceof Atshift\Deft\Vendor\Psr\Http\Message\ServerRequestInterface) throw new RuntimeException('PSR-7 isolation failed');
$key = Atshift\Deft\Vendor\Defuse\Crypto\Key::createNewRandomKey();
$ciphertext = Atshift\Deft\Vendor\Defuse\Crypto\Crypto::encrypt('scope-smoke', $key);
if (Atshift\Deft\Vendor\Defuse\Crypto\Crypto::decrypt($ciphertext, $key) !== 'scope-smoke') throw new RuntimeException('Crypto smoke failed');
$jwt = Atshift\Deft\Vendor\Lcobucci\JWT\Configuration::forSymmetricSigner(
    new Atshift\Deft\Vendor\Lcobucci\JWT\Signer\Hmac\Sha256(),
    Atshift\Deft\Vendor\Lcobucci\JWT\Signer\Key\InMemory::plainText(random_bytes(32))
);
$token = $jwt->builder()->issuedBy('scope-smoke')->getToken($jwt->signer(), $jwt->signingKey());
$jwt->validator()->assert($jwt->parser()->parse($token->toString()),
    new Atshift\Deft\Vendor\Lcobucci\JWT\Validation\Constraint\SignedWith($jwt->signer(), $jwt->verificationKey()));
$pkce = new Atshift\Deft\Vendor\League\OAuth2\Server\CodeChallengeVerifiers\S256Verifier();
if (!$pkce->verifyCodeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')) throw new RuntimeException('PKCE RFC7636 vector failed');
echo 'Scoped classmap, OAuth classes, PSR-7, encryption, JWT and PKCE smoke checks passed.' . PHP_EOL;
`, output]);

  for (const order of ["scoped-first", "unscoped-first"]) run(php, ["-r", String.raw`
$paths = [$argv[1] . '/autoload.php', $argv[2] . '/vendor/autoload.php'];
if ($argv[3] === 'unscoped-first') $paths = array_reverse($paths);
foreach ($paths as $file) require $file;
$scoped = new Atshift\Deft\Vendor\Nyholm\Psr7\Response();
$unscoped = new Nyholm\Psr7\Response();
if (!$scoped instanceof Atshift\Deft\Vendor\Psr\Http\Message\ResponseInterface || $scoped instanceof Psr\Http\Message\ResponseInterface) throw new RuntimeException('Scoped PSR conflict');
if (!$unscoped instanceof Psr\Http\Message\ResponseInterface || $unscoped instanceof Atshift\Deft\Vendor\Psr\Http\Message\ResponseInterface) throw new RuntimeException('Unscoped PSR conflict');
if (!class_exists(Atshift\Deft\Vendor\League\OAuth2\Server\AuthorizationServer::class) || !class_exists(League\OAuth2\Server\AuthorizationServer::class)) throw new RuntimeException('OAuth coexistence failed');
echo 'Unprefixed dependency coexistence passed: ' . $argv[3] . PHP_EOL;
`, output, runtime, order]);

  const hashes = {};
  for (const name of await files(output)) hashes[name] = sha256(await readFile(path.join(output, name)));
  const manifest = { namespacePrefix: prefix, minimumPhp: "8.2.0", composer: composerIdentity,
    composerLockSha256: sha256(lockBytes), scoperConfigSha256: sha256(await readFile(path.join(root, "scoper.inc.php"))),
    packages, files: hashes };
  await writeFile(path.join(output, "dependency-manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`);
  // Keep the previous usable bundle until the replacement has passed every check.
  const backup = path.join(work, "previous-vendor");
  assert(!await exists(backup), `Inspect previous interrupted replacement: ${backup}`);
  const hadTarget = await exists(target);
  if (hadTarget) await rename(target, backup);
  try { await rename(output, target); } catch (error) {
    if (hadTarget) await rename(backup, target);
    throw error;
  }
  await rm(backup, { recursive: true, force: true });
  console.log(`Built ${packages.length} runtime packages (${Object.keys(hashes).length + 1} files) in ${target}`);
  console.log(`Bundle file-map SHA-256: ${sha256(JSON.stringify(hashes))}`);
  console.log(`Audits: ${path.join(work, "audit.json")} and ${path.join(work, "audit-runtime.json")}`);
} finally {
  await rm(mutex, { recursive: true, force: true });
}
