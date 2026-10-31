import { useEffect, useState } from "react";
import { registerArticleTools } from "./webmcp-registration.js";

export function useArticleTools(tools, enabled) {
  const [status, setStatus] = useState({
    supported: false,
    registered: false,
    error: null,
  });
  useEffect(() => {
    const controller = new AbortController();
    let timer,
      attempts = 0;
    function connect() {
      const api = document.modelContext;
      if (!api?.registerTool) {
        setStatus({ supported: false, registered: false, error: null });
        return false;
      }
      clearInterval(timer);
      setStatus({ supported: true, registered: false, error: null });
      if (enabled) {
        registerArticleTools(api, tools, controller.signal)
          .then(() => {
            if (!controller.signal.aborted)
              setStatus({ supported: true, registered: true, error: null });
          })
          .catch((error) => {
            if (!controller.signal.aborted)
              setStatus({ supported: true, registered: false, error });
          });
      }
      return true;
    }
    // Browser extensions may expose the standard API after the page mounts.
    if (!connect())
      timer = setInterval(() => {
        if (connect() || ++attempts >= 20) clearInterval(timer);
      }, 500);
    return () => {
      clearInterval(timer);
      controller.abort();
    };
  }, [tools, enabled]);
  return { ...status, registered: enabled && status.registered };
}
