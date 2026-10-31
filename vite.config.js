import { defineConfig } from "vite";
import { fileURLToPath } from "node:url";
import { createReviewStore } from "./server/review-store.mjs";
import { reviewMiddleware } from "./server/review-http.mjs";

export default defineConfig({
  server: {
    host: "127.0.0.1",
    cors: false,
    fs: {
      deny: [".env", ".env.*", "*.{crt,pem}", "**/.git/**", "**/.local/**"],
    },
    watch: { ignored: ["**/.local/**"] },
  },
  plugins: [
    {
      name: "local-article-review",
      configureServer(server) {
        const store = createReviewStore(
          fileURLToPath(new URL("./.local/review-store", import.meta.url)),
        );
        server.middlewares.use(reviewMiddleware(store));
        server.httpServer?.once("close", () => store.close());
      },
    },
  ],
});
