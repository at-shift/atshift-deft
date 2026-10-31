import { DatabaseSync } from "node:sqlite";
import { mkdirSync, chmodSync } from "node:fs";
import { join } from "node:path";
import { createHash, randomBytes, randomUUID } from "node:crypto";
import { Worker } from "node:worker_threads";

export const hash = (value) =>
  createHash("sha256").update(JSON.stringify(value)).digest("hex");
export function problem(code, message, status = 400) {
  return Object.assign(new Error(message), { code, status });
}
export function validateSnapshot(snapshot, origin) {
  return new Promise((resolve, reject) => {
    const worker = new Worker(
      new URL("./validate-worker.mjs", import.meta.url),
      {
        workerData: { snapshot, origin },
        resourceLimits: { maxOldGenerationSizeMb: 128 },
      },
    );
    const timer = setTimeout(() => {
      worker.terminate();
      reject(problem("VALIDATION_TIMEOUT", "Snapshot validation timed out."));
    }, 15000);
    const end = () => {
      clearTimeout(timer);
      worker.terminate();
    };
    worker.once("message", (result) => {
      end();
      if (result.error)
        reject(problem(result.error.code, result.error.message));
      else resolve(result);
    });
    worker.once("error", () => {
      end();
      reject(problem("VALIDATION_FAILED", "Snapshot validation failed."));
    });
    worker.once("exit", (code) => {
      if (code !== 0) {
        clearTimeout(timer);
        reject(problem("VALIDATION_FAILED", "Snapshot validator stopped."));
      }
    });
  });
}

export function createReviewStore(
  directory,
  { now = Date.now, validate = validateSnapshot } = {},
) {
  mkdirSync(directory, { recursive: true, mode: 0o700 });
  chmodSync(directory, 0o700);
  const path = join(directory, "reviews.sqlite");
  const db = new DatabaseSync(path);
  chmodSync(path, 0o600);
  db.exec(`PRAGMA busy_timeout=2000;
    CREATE TABLE IF NOT EXISTS snapshots (id TEXT NOT NULL, version INTEGER NOT NULL, data TEXT NOT NULL,
      PRIMARY KEY(id, version));
    CREATE TABLE IF NOT EXISTS heads (id TEXT PRIMARY KEY, version INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS receipts (id TEXT NOT NULL, version INTEGER NOT NULL, data TEXT NOT NULL,
      PRIMARY KEY(id, version));`);
  const tickets = new Map();
  let validating = false;
  function get(id) {
    const row = db
      .prepare(
        `SELECT snapshots.data FROM snapshots JOIN heads USING(id,version) WHERE id=?`,
      )
      .get(id);
    if (!row) return null;
    const entry = JSON.parse(row.data);
    const receipt = db
      .prepare("SELECT data FROM receipts WHERE id=? AND version=?")
      .get(id, entry.version);
    return { ...entry, receipt: receipt ? JSON.parse(receipt.data) : null };
  }
  function transaction(fn) {
    db.exec("BEGIN IMMEDIATE");
    try {
      const result = fn();
      db.exec("COMMIT");
      return result;
    } catch (error) {
      db.exec("ROLLBACK");
      throw error;
    }
  }
  function matches(entry, expectedVersion) {
    if ((entry?.version || 0) !== expectedVersion)
      throw problem(
        "SERVER_CONFLICT",
        "The server copy changed. Read it again before saving or reviewing.",
        409,
      );
  }
  return {
    get,
    async save(snapshot, expectedVersion, origin) {
      if (Buffer.byteLength(JSON.stringify(snapshot) || "") > 4000000)
        throw problem("PAYLOAD_TOO_LARGE", "Snapshot exceeds 4 MB.", 413);
      if (!Number.isSafeInteger(expectedVersion) || expectedVersion < 0)
        throw problem(
          "INVALID_VERSION",
          "Expected server version is required.",
        );
      if (validating)
        throw problem(
          "SERVER_BUSY",
          "A snapshot is being validated. Retry shortly.",
          409,
        );
      let verified;
      validating = true;
      try {
        verified = await validate(snapshot, origin);
      } finally {
        validating = false;
      }
      const inputDigest = hash(verified.snapshot);
      const digest = hash({ renderer: "article-preview-v1", ...verified });
      return transaction(() => {
        const id = verified.snapshot.article.id;
        const previous = get(id);
        matches(previous, expectedVersion);
        if (previous?.digest === digest) return previous;
        if (
          previous &&
          verified.snapshot.article.revision <=
            previous.snapshot.article.revision
        )
          throw problem(
            "REVISION_CONFLICT",
            "Changed content requires a newer article revision.",
            409,
          );
        if (
          !previous &&
          db.prepare("SELECT count(*) AS count FROM heads").get().count >= 20
        )
          throw problem(
            "STORE_LIMIT",
            "This prototype stores up to 20 articles.",
          );
        if ((previous?.version || 0) >= 100)
          throw problem(
            "HISTORY_LIMIT",
            "This prototype stores up to 100 snapshots per article.",
          );
        const entry = {
          version: expectedVersion + 1,
          inputDigest,
          digest,
          snapshot: verified.snapshot,
          html: verified.html,
          savedAt: new Date(now()).toISOString(),
        };
        const serialized = JSON.stringify(entry);
        const size = db
          .prepare(
            "SELECT coalesce(sum(length(CAST(data AS BLOB))),0) AS bytes FROM snapshots",
          )
          .get().bytes;
        if (size + Buffer.byteLength(serialized) > 64000000)
          throw problem(
            "STORE_LIMIT",
            "Local snapshot storage is limited to 64 MB.",
          );
        db.prepare("INSERT INTO snapshots VALUES(?,?,?)").run(
          id,
          entry.version,
          serialized,
        );
        db.prepare(
          "INSERT INTO heads VALUES(?,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version",
        ).run(id, entry.version);
        return { ...entry, receipt: null };
      });
    },
    preview(id, expectedVersion, session) {
      const entry = get(id);
      if (!entry) throw problem("NOT_FOUND", "No server copy exists.", 404);
      matches(entry, expectedVersion);
      for (const [token, ticket] of tickets)
        if (ticket.expiresAt <= now()) tickets.delete(token);
      if (tickets.size >= 100)
        throw problem(
          "TICKET_LIMIT",
          "Close old previews and retry later.",
          429,
        );
      const token = randomBytes(32).toString("hex");
      const ticket = {
        id,
        version: entry.version,
        digest: entry.digest,
        session,
        expiresAt: now() + 300000,
      };
      tickets.set(token, ticket);
      return { ...entry, ticket: token, expiresAt: ticket.expiresAt };
    },
    confirm({ ticket: token, digest, version }, session) {
      const ticket = tickets.get(token);
      if (!ticket || ticket.session !== session || ticket.expiresAt <= now())
        throw problem(
          "INVALID_TICKET",
          "Preview confirmation expired or belongs to another session.",
          409,
        );
      return transaction(() => {
        const entry = get(ticket.id);
        matches(entry, ticket.version);
        if (
          ticket.version !== version ||
          ticket.digest !== digest ||
          entry.digest !== digest
        )
          throw problem(
            "PREVIEW_CHANGED",
            "Confirm the exact server preview that was opened.",
            409,
          );
        const receipt = {
          id: randomUUID(),
          articleId: ticket.id,
          serverVersion: version,
          articleRevision: entry.snapshot.article.revision,
          digest,
          at: new Date(now()).toISOString(),
          scope: "local-session-preview-only",
          verifiedHuman: false,
          publishAuthorized: false,
        };
        db.prepare(
          "INSERT INTO receipts VALUES(?,?,?) ON CONFLICT(id,version) DO UPDATE SET data=excluded.data",
        ).run(ticket.id, version, JSON.stringify(receipt));
        tickets.delete(token);
        return receipt;
      });
    },
    close() {
      tickets.clear();
      db.close();
    },
  };
}
