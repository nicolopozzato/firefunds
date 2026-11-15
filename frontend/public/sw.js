// FireFunds' service worker: push notifications, and the app's own files
// kept on the device so it opens at once. Never the data: /api always goes to
// the server (a financial app keeps nothing on the phone).
//
// The page is asked of the server first, as without a worker, so a new
// release still arrives on the next load; only when the server doesn't answer
// within a few seconds (offline, restarting) does the kept copy open, and the
// app says it can't reach the server. Scripts and styles have a new name with
// every build, so the kept ones are always right.

// This build's files, written in by the build (vite.config.ts): a new build is
// a new worker. Empty in dev, where nothing is kept.
const BUILD = { id: 'dev', files: [] }

const CACHE = `app-${BUILD.id}`
const FILES = new Set(BUILD.files)
const PAGE_TIMEOUT_MS = 4000

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      if (FILES.size > 0) {
        const cache = await caches.open(CACHE)
        await cache.addAll(BUILD.files.map((file) => new Request(file, { cache: 'reload' })))
      }
      await self.skipWaiting()
    })()
  )
})

// The previous builds' files go.
self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys()
      await Promise.all(names.filter((name) => name.startsWith('app-') && name !== CACHE).map((name) => caches.delete(name)))
      await self.clients.claim()
    })()
  )
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (FILES.size === 0 || request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return

  if (request.mode === 'navigate') {
    event.respondWith(page(request))
  } else if (FILES.has(url.pathname)) {
    event.respondWith(caches.match(request).then((kept) => kept ?? fetch(request)))
  }
})

// The server's page when it answers in time, else the copy kept with this build.
async function page(request) {
  const network = fetch(request)
  try {
    const response = await Promise.race([network, new Promise((resolve) => setTimeout(resolve, PAGE_TIMEOUT_MS))])
    if (response?.ok) return response
  } catch {
    // Offline: the kept copy below.
  }
  const kept = await caches.match('/index.html')
  return kept ?? network
}

// The server words the notification in the user's language: title, body,
// the page it opens, a tag so the same one replaces itself instead of piling up.
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' }
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'FireFunds', {
      body: data.body || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      tag: data.tag,
      data: { url: data.url || '/' },
    })
  )
})

// A tap opens its page: in the app already open (it routes there itself), else a new window.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/', self.location.origin).href
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const open = windows.find((client) => new URL(client.url).origin === self.location.origin)
      if (open) {
        await open.focus()
        open.postMessage({ type: 'navigate', url: new URL(url).pathname })
        return
      }
      await self.clients.openWindow(url)
    })()
  )
})
