import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// base './' so the build works from any folder under public_html.
// Dev proxy expects: php -S 127.0.0.1:8010 -t server   (run from repo root)
export default defineConfig({
  base: './',
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      '/api': { target: 'http://127.0.0.1:8010', changeOrigin: false },
    },
  },
});
