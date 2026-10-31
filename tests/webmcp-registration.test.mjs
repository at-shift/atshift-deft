import test from "node:test";
import assert from "node:assert/strict";
import { registerArticleTools } from "../src/webmcp-registration.js";

const definitions = [{ name: "read" }, { name: "propose" }];
function registry(failAt = -1, error = new Error("registration denied")) {
  const active = new Set();
  const signals = [];
  let count = 0;
  return {
    active,
    signals,
    async registerTool(tool, { signal }) {
      signals.push(signal);
      if (count++ === failAt) throw error;
      active.add(tool.name);
      signal.addEventListener("abort", () => active.delete(tool.name), {
        once: true,
      });
    },
  };
}
test("registers the full group before reporting success", async () => {
  const api = registry();
  const controller = new AbortController();
  await registerArticleTools(api, definitions, controller.signal);
  assert.deepEqual([...api.active], ["read", "propose"]);
  controller.abort();
});
test("awaits asynchronous completion instead of premature readiness", async () => {
  let release,
    complete = false;
  const controller = new AbortController();
  const operation = registerArticleTools(
    {
      registerTool: () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    },
    [definitions[0]],
    controller.signal,
  ).then(() => {
    complete = true;
  });
  await Promise.resolve();
  assert.equal(complete, false);
  release();
  await operation;
  assert.equal(complete, true);
  controller.abort();
});
test("disconnect removes all registrations", async () => {
  const api = registry();
  const controller = new AbortController();
  await registerArticleTools(api, definitions, controller.signal);
  controller.abort();
  assert.equal(api.active.size, 0);
  assert(api.signals.every((s) => s.aborted));
});
test("partial asynchronous failure rolls back already registered tools", async () => {
  const api = registry(1);
  const controller = new AbortController();
  await assert.rejects(
    registerArticleTools(api, definitions, controller.signal),
    /denied/,
  );
  assert.equal(api.active.size, 0);
  assert(api.signals.every((s) => s.aborted));
});
test("synchronous API exceptions also fail closed", async () => {
  await assert.rejects(
    registerArticleTools(
      {
        registerTool() {
          throw new Error("sync error");
        },
      },
      definitions,
      new AbortController().signal,
    ),
    /sync error/,
  );
});
test("pre-cancelled connection registers nothing", async () => {
  const api = registry();
  const controller = new AbortController();
  controller.abort();
  await assert.rejects(
    registerArticleTools(api, definitions, controller.signal),
    { name: "AbortError" },
  );
  assert.equal(api.signals.length, 0);
});
test("cancellation during registration never enables the remaining tools", async () => {
  let release;
  const api = registry();
  const controller = new AbortController();
  const operation = registerArticleTools(
    {
      async registerTool(tool, options) {
        await api.registerTool(tool, options);
        await new Promise((resolve) => {
          release = resolve;
        });
      },
    },
    definitions,
    controller.signal,
  );
  await Promise.resolve();
  await Promise.resolve();
  controller.abort();
  release();
  await assert.rejects(operation, { name: "AbortError" });
  assert.equal(api.signals.length, 1);
  assert.equal(api.active.size, 0);
});
test("strict lifecycle cleanup permits a clean remount", async () => {
  const api = registry();
  const first = new AbortController();
  const next = new AbortController();
  await registerArticleTools(api, definitions, first.signal);
  first.abort();
  await registerArticleTools(api, definitions, next.signal);
  assert.deepEqual([...api.active], ["read", "propose"]);
  next.abort();
});

test("callbacks cannot run until the complete group is ready", async () => {
  let callback,
    release,
    calls = 0;
  const controller = new AbortController();
  const operation = registerArticleTools(
    {
      registerTool(tool) {
        callback = tool.execute;
        return new Promise((resolve) => {
          release = resolve;
        });
      },
    },
    [
      {
        name: "read",
        execute() {
          calls++;
          return "data";
        },
      },
    ],
    controller.signal,
  );
  assert.equal((await callback()).isError, true);
  assert.equal(calls, 0);
  release();
  await operation;
  assert.equal(await callback(), "data");
  controller.abort();
  assert.equal((await callback()).isError, true);
  assert.equal(calls, 1);
});

test("disconnect suppresses an in-flight read result", async () => {
  let callback, release;
  const controller = new AbortController();
  await registerArticleTools(
    {
      registerTool(tool) {
        callback = tool.execute;
      },
    },
    [
      {
        name: "read",
        execute() {
          return new Promise((resolve) => {
            release = resolve;
          });
        },
      },
    ],
    controller.signal,
  );
  const reading = callback();
  controller.abort();
  release("private draft");
  const result = await reading;
  assert.equal(result.isError, true);
  assert(!JSON.stringify(result).includes("private draft"));
});
