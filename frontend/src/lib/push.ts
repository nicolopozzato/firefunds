import { api } from './api'

/**
 * Push notifications on this device. Every browser needs the service worker
 * (public/sw.js) and the user's permission; iPhone and iPad (iOS 16.4+) only
 * once the app is on the home screen and opened from there, and the
 * permission can only be asked after a tap.
 */
export type PushSupport = 'ok' | 'unsupported' | 'needs-install' | 'denied'

export interface PushDevice {
  id: string
  endpoint: string
  label: string
  createdAt: string
  lastSentAt: string | null
}

const isAppleMobile = () =>
  /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

const isInstalled = () =>
  window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true

export function pushSupport(): PushSupport {
  if (isAppleMobile() && !isInstalled()) return 'needs-install'
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  return 'ok'
}

export function registerServiceWorker(): void {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Without it there is no push; the app works the same.
    })
  }
}

/** This device's subscription, when push is on here. */
export async function currentSubscription(): Promise<PushSubscription | null> {
  if (pushSupport() !== 'ok') return null
  const registration = await navigator.serviceWorker.ready
  return registration.pushManager.getSubscription()
}

/** Asks the permission (call it from a tap), subscribes this device and tells the server. */
export async function enablePush(): Promise<PushDevice> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('permission')
  const { data } = await api.get<{ publicKey: string }>('/api/push/key')
  const registration = await navigator.serviceWorker.ready
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(data.publicKey) }))
  const json = subscription.toJSON()
  const { data: device } = await api.post<PushDevice>('/api/push/subscriptions', { endpoint: json.endpoint, keys: json.keys, label: deviceLabel() })
  return device
}

export async function disablePush(): Promise<void> {
  const subscription = await currentSubscription()
  if (!subscription) return
  await api.post('/api/push/unsubscribe', { endpoint: subscription.endpoint })
  await subscription.unsubscribe()
}

/** "Chrome su Android", "Safari su iPhone": how the device appears in the list. */
export function deviceLabel(userAgent = navigator.userAgent): string {
  const browser = /Edg\//.test(userAgent)
    ? 'Edge'
    : /SamsungBrowser/.test(userAgent)
      ? 'Samsung Internet'
      : /Firefox|FxiOS/.test(userAgent)
        ? 'Firefox'
        : /Chrome|CriOS/.test(userAgent)
          ? 'Chrome'
          : /Safari/.test(userAgent)
            ? 'Safari'
            : 'Browser'
  const system = /iPhone/.test(userAgent)
    ? 'iPhone'
    : /iPad/.test(userAgent) || (/Macintosh/.test(userAgent) && typeof navigator !== 'undefined' && navigator.maxTouchPoints > 1)
      ? 'iPad'
      : /Android/.test(userAgent)
        ? 'Android'
        : /Mac OS X|Macintosh/.test(userAgent)
          ? 'Mac'
          : /Windows/.test(userAgent)
            ? 'Windows'
            : /Linux/.test(userAgent)
              ? 'Linux'
              : ''
  return system ? `${browser} · ${system}` : browser
}

/** The VAPID public key as the PushManager takes it. */
function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}
