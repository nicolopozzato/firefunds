/**
 * Theme: light and dark mode, applied as the CSS variables Tailwind's tokens
 * read (bg-background, text-positive…). One accent colour (blue) for
 * everyone. Light mode is a soft grey page with white cards; dark mode is
 * "graphite": near-black neutral surfaces.
 */

export type ThemeMode = 'light' | 'dark'
/** The user's choice (User::theme): a mode, or whatever the device uses. */
export type ThemePreference = ThemeMode | 'system'
export type Density = 'compact' | 'comfortable' | 'spacious'

export const DENSITIES: Density[] = ['compact', 'comfortable', 'spacious']

export interface ThemeConfig {
  mode: ThemeMode
  density: Density
}

export interface SurfaceColors {
  background: string
  foreground: string
  card: string
  cardForeground: string
  popover: string
  popoverForeground: string
  primary: string
  primaryForeground: string
  secondary: string
  secondaryForeground: string
  muted: string
  mutedForeground: string
  accent: string
  accentForeground: string
  destructive: string
  destructiveForeground: string
  border: string
  input: string
  ring: string
}

const SURFACES: Record<ThemeMode, SurfaceColors> = {
  // Neutral greys with a hint of blue-violet (zinc), not shadcn's slate:
  // the page a step darker than the cards, so cards stand out without shadows.
  light: {
    background: '240 7% 97%',
    foreground: '240 10% 9%',
    card: '0 0% 100%',
    cardForeground: '240 10% 9%',
    popover: '0 0% 100%',
    popoverForeground: '240 10% 9%',
    primary: '221 83% 53%',
    primaryForeground: '0 0% 100%',
    secondary: '240 6% 94%',
    secondaryForeground: '240 10% 9%',
    muted: '240 6% 95%',
    mutedForeground: '240 4% 43%',
    accent: '240 6% 94%',
    accentForeground: '240 10% 9%',
    destructive: '0 72% 51%',
    destructiveForeground: '0 0% 100%',
    border: '240 6% 90%',
    input: '240 6% 86%',
    ring: '221 83% 53%',
  },
  dark: {
    background: '240 8% 5%',
    foreground: '240 6% 93%',
    card: '240 7% 8%',
    cardForeground: '240 6% 93%',
    popover: '240 6% 10%',
    popoverForeground: '240 6% 93%',
    primary: '217 91% 60%',
    primaryForeground: '0 0% 100%',
    secondary: '240 5% 15%',
    secondaryForeground: '240 6% 93%',
    muted: '240 5% 14%',
    mutedForeground: '240 5% 62%',
    accent: '240 5% 16%',
    accentForeground: '240 6% 93%',
    destructive: '0 70% 52%',
    destructiveForeground: '0 0% 98%',
    border: '240 5% 17%',
    input: '240 5% 21%',
    ring: '217 91% 60%',
  },
}

/**
 * Colours with a meaning, the same in every scheme: money in (positive) and
 * out (negative), transfers and info (info), investments (invest), warnings;
 * and the charts' series, in order (series1…8), plus "other". Tailwind:
 * text-positive, bg-negative/10…; SVG: hsl(var(--series-1)).
 */
interface SemanticColors {
  positive: string
  negative: string
  info: string
  invest: string
  warning: string
  series1: string
  series2: string
  series3: string
  series4: string
  series5: string
  series6: string
  series7: string
  series8: string
  seriesOther: string
}

const SEMANTIC: Record<ThemeMode, SemanticColors> = {
  light: {
    positive: '142.1 76.2% 36.3%',
    negative: '0 72.2% 50.6%',
    info: '221.2 83.2% 53.3%',
    invest: '262.1 83.3% 57.8%',
    warning: '32.1 94.6% 43.7%',
    series1: '212.8 67.7% 50.2%',
    series2: '17 82.1% 56.3%',
    series3: '158.5 73.3% 39.6%',
    series4: '40.8 100% 46.5%',
    series5: '337.4 70.3% 69.6%',
    series6: '120 100% 25.7%',
    series7: '248.8 48.4% 44.1%',
    series8: '0.4 73.5% 58.6%',
    seriesOther: '55 9.1% 74.1%',
  },
  // Lighter and a little less saturated: bright colours vibrate on near-black.
  dark: {
    positive: '142 60% 55%',
    negative: '0 85% 68%',
    info: '213 90% 66%',
    invest: '258 85% 74%',
    warning: '38 92% 58%',
    series1: '213 80% 62%',
    series2: '20 88% 62%',
    series3: '158 62% 48%',
    series4: '41 95% 56%',
    series5: '337 72% 72%',
    series6: '122 48% 50%',
    series7: '250 72% 72%',
    series8: '0 80% 66%',
    seriesOther: '240 5% 38%',
  },
}

function paletteFor(config: ThemeConfig): SurfaceColors & SemanticColors {
  return { ...SURFACES[config.mode], ...SEMANTIC[config.mode] }
}

/** The mode on screen, for the few components that can't follow the CSS variables (toasts). */
let currentMode: ThemeMode = 'light'
const modeListeners = new Set<() => void>()

export function getThemeMode(): ThemeMode {
  return currentMode
}

export function subscribeThemeMode(listener: () => void): () => void {
  modeListeners.add(listener)
  return () => modeListeners.delete(listener)
}

/**
 * Apply theme to the document
 */
export function applyTheme(config: ThemeConfig): void {
  const root = document.documentElement
  const colors = paletteFor(config)

  // Apply color variables: cardForeground -> --card-foreground, series1 -> --series-1
  Object.entries(colors).forEach(([key, value]) => {
    const cssVar = `--${key.replace(/([A-Z])/g, '-$1').replace(/(\d+)/g, '-$1').toLowerCase()}`
    root.style.setProperty(cssVar, value)
  })

  // Apply density class
  root.setAttribute('data-density', config.density)

  root.classList.remove('light', 'dark')
  root.classList.add(config.mode)
  // Native controls (date pickers, selects) and scrollbars follow the mode too.
  root.style.colorScheme = config.mode
  root.style.backgroundColor = ''

  // The phone's status bar (installed app, Android) takes the page's background,
  // so it blends with the top bar in either mode.
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', hslTripletToHex(colors.background))

  if (config.mode !== currentMode) {
    currentMode = config.mode
    modeListeners.forEach((listener) => listener())
  }
}

/** "222.2 84% 4.9%" (a theme colour, as the CSS variables hold it) -> "#020817". */
function hslTripletToHex(triplet: string): string {
  const [h, s, l] = triplet.replace(/%/g, '').split(/\s+/).map(Number)
  const saturation = s / 100
  const lightness = l / 100
  const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation
  const channel = (n: number) => {
    const k = (n + h / 30) % 12
    const value = lightness - chroma / 2 * Math.max(-1, Math.min(k - 3, 9 - k, 1))
    return Math.round(value * 255).toString(16).padStart(2, '0')
  }
  return `#${channel(0)}${channel(8)}${channel(4)}`
}

/**
 * Get current theme from localStorage or return default
 */
export function getTheme(): ThemeConfig {
  const stored = localStorage.getItem('theme-config')
  if (stored) {
    try {
      const { mode, density } = JSON.parse(stored)
      return { mode: mode === 'dark' ? 'dark' : 'light', density: DENSITIES.includes(density) ? density : 'comfortable' }
    } catch {
      // Fall through to default
    }
  }

  return {
    mode: 'light',
    density: 'comfortable',
  }
}

/**
 * Save theme to localStorage
 */
export function saveTheme(config: ThemeConfig): void {
  try {
    localStorage.setItem('theme-config', JSON.stringify(config))
  } catch {
    // Not remembered for the next start: it still applies now.
  }
  applyTheme(config)
}

const PREFERENCE_KEY = 'theme-preference'
const DARK_QUERY = '(prefers-color-scheme: dark)'

/** The last preference applied: the user's, remembered for the login page and the next start. */
export function getThemePreference(): ThemePreference {
  try {
    const stored = localStorage.getItem(PREFERENCE_KEY)
    if (stored === 'light' || stored === 'dark' || stored === 'system') return stored
  } catch {
    // Fall through to default
  }
  return 'system'
}

export function resolveThemeMode(preference: ThemePreference): ThemeMode {
  if (preference !== 'system') return preference
  return window.matchMedia?.(DARK_QUERY).matches ? 'dark' : 'light'
}

/**
 * Applies a preference (and remembers it, with the user's density when
 * given); "system" is followed live by useApplyThemePreference.
 */
export function applyThemePreference(
  preference: ThemePreference,
  appearance: Partial<Pick<ThemeConfig, 'density'>> = {}
): void {
  try {
    localStorage.setItem(PREFERENCE_KEY, preference)
  } catch {
    // Not remembered: it still applies now.
  }
  const density = appearance.density && DENSITIES.includes(appearance.density) ? appearance.density : undefined
  saveTheme({
    ...getTheme(),
    ...(density && { density }),
    mode: resolveThemeMode(preference),
  })
}

/** Calls back when the device switches between light and dark (e.g. at sunset). */
export function onSystemModeChange(callback: () => void): () => void {
  const query = window.matchMedia?.(DARK_QUERY)
  if (!query) return () => {}
  query.addEventListener('change', callback)
  return () => query.removeEventListener('change', callback)
}
