// Registration is asynchronous. Roll back the whole group on partial failure.
export async function registerArticleTools(api, tools, signal) {
  const group = new AbortController();
  let ready = false;
  const unavailable = () => ({
    content: [
      {
        type: "text",
        text: JSON.stringify({
          ok: false,
          error: {
            code: group.signal.aborted
              ? "CONNECTION_DISABLED"
              : "CONNECTION_NOT_READY",
            message: "Reconnect and discover the current tools.",
            path: "",
          },
        }),
      },
    ],
    isError: true,
  });
  const abort = () => group.abort(signal.reason);
  signal.addEventListener("abort", abort, { once: true });
  if (signal.aborted) abort();
  try {
    for (const tool of tools) {
      group.signal.throwIfAborted();
      await api.registerTool(
        {
          ...tool,
          async execute(...args) {
            if (group.signal.aborted || !ready) return unavailable();
            const result = await tool.execute(...args);
            return group.signal.aborted ? unavailable() : result;
          },
        },
        { signal: group.signal },
      );
    }
    group.signal.throwIfAborted();
    ready = true;
  } catch (error) {
    group.abort();
    signal.removeEventListener("abort", abort);
    throw error;
  }
}
