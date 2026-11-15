/// <reference types="vitest/config" />
import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import { createHash } from 'crypto'
import { readFileSync, readdirSync, writeFileSync } from 'fs'
import path from 'path'
import { version } from './package.json'

// Not kept on the device: the worker itself, the Android app (downloaded once),
// and the spreadsheet reader (large, loaded only to import an Excel file).
const NOT_KEPT = ['sw.js', 'downloads/', 'assets/xlsx-']

/**
 * Writes the list of the build's files into the service worker
 * (public/sw.js, `const BUILD`), named after their content: every build is
 * a new worker, which keeps the new files and drops the old.
 */
function serviceWorkerFiles(): Plugin {
  return {
    name: 'service-worker-files',
    apply: 'build',
    closeBundle() {
      const dist = path.resolve(__dirname, 'dist')
      const files = readdirSync(dist, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile())
        .map((entry) => path.relative(dist, path.join(entry.parentPath, entry.name)).split(path.sep).join('/'))
        .filter((file) => !NOT_KEPT.some((excluded) => file === excluded || file.startsWith(excluded)))
        .sort()
      const hash = createHash('sha256')
      for (const file of files) hash.update(file).update(readFileSync(path.join(dist, file)))

      const worker = path.join(dist, 'sw.js')
      const placeholder = "const BUILD = { id: 'dev', files: [] }"
      const source = readFileSync(worker, 'utf8')
      if (!source.includes(placeholder)) throw new Error(`sw.js: "${placeholder}" not found`)
      const build = { id: hash.digest('hex').slice(0, 12), files: files.map((file) => `/${file}`) }
      writeFileSync(worker, source.replace(placeholder, `const BUILD = ${JSON.stringify(build)}`))
    },
  }
}

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react(), serviceWorkerFiles()],
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
