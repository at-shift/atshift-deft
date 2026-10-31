export function createReviewClient(fetcher = fetch) {
  let csrf;
  async function request(action, body) {
    const response = await fetcher(`/api/local-review/${action}`, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        "X-SF-Local": "1",
        ...(csrf ? { "X-SF-CSRF": csrf } : {}),
      },
      body: JSON.stringify(body),
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error(
        "ローカル保存サーバーが利用できません。npm run dev で起動してください。",
      );
    }
    if (!response.ok || result.error)
      throw Object.assign(
        new Error(result.error?.message || "Server request failed."),
        { code: result.error?.code },
      );
    return result;
  }
  return {
    async connect() {
      csrf = (await request("session", {})).csrf;
    },
    async read(id) {
      return (await request("read", { id })).data;
    },
    async save(snapshot, expectedVersion) {
      return (await request("save", { snapshot, expectedVersion })).data;
    },
    async preview(id, expectedVersion) {
      return (await request("preview", { id, expectedVersion })).data;
    },
    async confirm(preview) {
      return (
        await request("confirm", {
          ticket: preview.ticket,
          digest: preview.digest,
          version: preview.version,
        })
      ).data;
    },
  };
}
