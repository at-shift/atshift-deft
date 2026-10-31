import { readFile, stat } from "node:fs/promises";
import { createHash } from "node:crypto";
import { pathToFileURL } from "node:url";

export async function transferFile(grant, file, expectedOrigin) {
  const url = new URL(grant.uploadUrl);
  const origin = new URL(expectedOrigin);
  if (
    url.origin !== origin.origin ||
    url.username ||
    url.password ||
    url.hash ||
    (url.protocol !== "https:" &&
      !(url.protocol === "http:" && url.hostname === "127.0.0.1"))
  )
    throw new Error(
      "Transfer destination must match the explicitly selected HTTPS site (or local 127.0.0.1).",
    );
  if (
    !/^[a-f0-9]{64}$/.test(grant.token) ||
    Date.parse(grant.expiresAt) <= Date.now() ||
    !Number.isFinite(Date.parse(grant.expiresAt))
  )
    throw new Error("Invalid or expired transfer grant.");
  const meta = grant.file;
  const info = await stat(file);
  if (
    !info.isFile() ||
    info.size < 1 ||
    info.size > 10 * 1024 * 1024 ||
    info.size !== meta.bytes
  )
    throw new Error("File size differs from the authorized transfer.");
  const bytes = await readFile(file);
  if (
    bytes.length !== meta.bytes ||
    createHash("sha256").update(bytes).digest("hex") !== meta.sha256
  )
    throw new Error("File contents differ from the authorized transfer.");
  const body = new FormData();
  body.append("purpose", meta.purpose);
  body.append("file", new Blob([bytes], { type: meta.mime }), meta.filename);
  const response = await fetch(url, {
    method: "POST",
    redirect: "error",
    headers: { "X-SF-Transfer-Token": grant.token },
    body,
  });
  const result = await response.json();
  if (!response.ok)
    throw new Error(
      `${result.code || response.status}: ${result.message || "Transfer failed."}`,
    );
  if (
    result.articleId !== grant.articleId ||
    result.sha256 !== meta.sha256 ||
    result.bytes !== meta.bytes ||
    result.purpose !== meta.purpose ||
    result.publishAuthorized !== false
  )
    throw new Error("Transfer receipt does not match the authorized file.");
  return result;
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  try {
    const [grantPath, file, origin] = process.argv.slice(2);
    if (!grantPath || !file || !origin)
      throw new Error(
        "Usage: node scripts/transfer-wordpress.mjs GRANT.json FILE EXPECTED_SITE_ORIGIN",
      );
    const grant = JSON.parse(await readFile(grantPath, "utf8"));
    console.log(JSON.stringify(await transferFile(grant, file, origin)));
  } catch (error) {
    // Do not print the grant or token, including on network errors.
    console.error(error.message);
    process.exitCode = 1;
  }
}
