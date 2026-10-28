// FireFunds' service worker: push notifications only. Nothing is cached:
// the app and its data always come from the server (a financial app keeps
// nothing on the phone), so a new release still arrives on the next load.

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

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
