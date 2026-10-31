import { randomBytes } from "node:crypto";
import { problem } from "./review-store.mjs";

const PREFIX = "/api/local-review/";
const MAX_BYTES = 4000000;
function exact(value, keys) {
  if (
    !value ||
    typeof value !== "object" ||
    Array.isArray(value) ||
    Object.keys(value).length !== keys.length ||
    keys.some((key) => !Object.hasOwn(value, key))
  )
    throw problem("INVALID_REQUEST", "Unexpected request properties.");
}
async function readJSON(req) {
  if (req.headers["content-type"] !== "application/json")
    throw problem("CONTENT_TYPE", "Use application/json.", 415);
  if (Number(req.headers["content-length"]) > MAX_BYTES)
    throw problem("PAYLOAD_TOO_LARGE", "Snapshot request exceeds 4 MB.", 413);
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BYTES)
      throw problem("PAYLOAD_TOO_LARGE", "Snapshot request exceeds 4 MB.", 413);
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString("utf8"));
  } catch {
    throw problem("INVALID_JSON", "Invalid JSON request.");
  }
}
export function reviewMiddleware(store, { now = Date.now } = {}) {
  const sessions = new Map();
  return async (req, res, next) => {
    if (!req.url?.startsWith(PREFIX)) return next();
    const reply = (status, body) => {
      res.writeHead(status, {
        "Content-Type": "application/json; charset=utf-8",
        "Cache-Control": "no-store",
        "X-Content-Type-Options": "nosniff",
      });
      res.end(JSON.stringify(body));
    };
    try {
      const origin = `http://127.0.0.1:${req.socket.localPort}`;
      if (
        req.headers.host !== new URL(origin).host ||
        req.headers.origin !== origin ||
        !["127.0.0.1", "::ffff:127.0.0.1"].includes(req.socket.remoteAddress) ||
        (req.headers["sec-fetch-site"] &&
          req.headers["sec-fetch-site"] !== "same-origin")
      )
        throw problem(
          "ORIGIN_DENIED",
          "Only the same-origin loopback application may use this API.",
          403,
        );
      if (req.method !== "POST")
        throw problem("METHOD_DENIED", "Use POST.", 405);
      if (req.headers["x-sf-local"] !== "1")
        throw problem(
          "CLIENT_REQUIRED",
          "Local application request required.",
          403,
        );
      const action = req.url.slice(PREFIX.length);
      for (const [key, session] of sessions)
        if (session.expiresAt <= now()) sessions.delete(key);
      const cookie = req.headers.cookie
        ?.split(";")
        .map((v) => v.trim())
        .find((v) => v.startsWith("sf_review="))
        ?.slice(10);
      let session = sessions.get(cookie);
      if (
        action !== "session" &&
        (!session || req.headers["x-sf-csrf"] !== session.csrf)
      )
        throw problem(
          "SESSION_REQUIRED",
          "Reopen the server preview to establish a local session.",
          403,
        );
      const body = await readJSON(req);
      if (action === "session") {
        exact(body, []);
        if (!session) {
          if (sessions.size >= 64)
            throw problem("SESSION_LIMIT", "Too many local sessions.", 429);
          const id = randomBytes(32).toString("hex");
          session = {
            id,
            csrf: randomBytes(32).toString("hex"),
            expiresAt: now() + 28800000,
          };
          sessions.set(id, session);
          res.setHeader(
            "Set-Cookie",
            `sf_review=${id}; HttpOnly; SameSite=Strict; Path=${PREFIX}; Max-Age=28800`,
          );
        }
        return reply(200, {
          csrf: session.csrf,
          scope: "local-session-only",
          authenticatedHuman: false,
        });
      }
      let result;
      if (action === "read") {
        exact(body, ["id"]);
        if (typeof body.id !== "string" || body.id.length > 80)
          throw problem("INVALID_ID", "Invalid article ID.");
        result = store.get(body.id);
      } else if (action === "save") {
        exact(body, ["snapshot", "expectedVersion"]);
        result = await store.save(body.snapshot, body.expectedVersion, origin);
      } else if (action === "preview") {
        exact(body, ["id", "expectedVersion"]);
        result = store.preview(body.id, body.expectedVersion, session.id);
      } else if (action === "confirm") {
        exact(body, ["ticket", "digest", "version"]);
        result = store.confirm(body, session.id);
      } else throw problem("NOT_FOUND", "Unknown local review action.", 404);
      reply(200, { data: result });
    } catch (error) {
      reply(error.status || 500, {
        error: {
          code: error.code || "SERVER_ERROR",
          message: error.code
            ? error.message
            : "The server operation failed. No confirmation was granted.",
        },
      });
    }
  };
}
