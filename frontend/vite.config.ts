/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'
import { version } from './package.json'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  // The app version from package.json, shown at the bottom of every page.
  define: {
    __APP_VERSION__: JSON.stringify(version),
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  // Unit tests of the pure modules (src/**/*.test.ts): `npm test`. jsdom
  // because i18n reads localStorage and the browser's language.
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Lando's proxy (https://firefunds.lndo.site).
    allowedHosts: ['.lndo.site'],
    watch: {
      usePolling: true,
    },
    // Same layout as the built app (FrankenPHP): the API on the page's own
    // origin under /api, so no CORS and no backend URL baked into the build.
    proxy: {
      '/api': 'http://backend:8000',
    },
  },
})
