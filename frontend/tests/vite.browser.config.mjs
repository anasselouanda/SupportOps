import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  root: fileURLToPath(new URL("..", import.meta.url)),
  plugins: [react()],
  cacheDir: "node_modules/.vite-mvp-browser",
  define: { "import.meta.env.VITE_API_BASE_URL": JSON.stringify("/") },
  server: {
    host: "127.0.0.1", port: 5174, strictPort: true,
    allowedHosts: [".localhost"],
    proxy: {
      "/api": { target: "http://127.0.0.1:8001" },
      "/sanctum": { target: "http://127.0.0.1:8001" },
    },
  },
});
