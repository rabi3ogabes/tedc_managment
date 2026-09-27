/**
 * Brand Studio theme engine.
 *
 * The admin-managed theme is converted into CSS custom properties on <html>. Tailwind v4 emits
 * every colour utility as `var(--color-*)`, so re-defining the palette variables re-skins the
 * whole website, dashboard and portal instantly — no rebuild required.
 */

export type PatternType = 'none' | 'dots' | 'grid' | 'islamic_star' | 'arabesque' | 'diagonal' | 'custom'

export type Theme = {
  preset: string
  colors: { primary: string; accent: string; background: string; surface: string; text: string; link: string }
  buttons: { style: 'gradient' | 'solid' | 'outline'; radius: number; accent_text: string; uppercase: boolean }
  banners: { overlay_color: string; overlay_opacity: number; hero_images: (string | null)[]; page_banner_image: string | null; cta_style: 'gradient' | 'accent' | 'image' }
  pattern: { type: PatternType; color: string; opacity: number; size: number; image: string | null }
  shape: { card_radius: number; glass_blur: number }
}

export const DEFAULT_THEME: Theme = {
  preset: 'royal_navy',
  colors: { primary: '#0B1F3A', accent: '#C8A24A', background: '#F8F6F1', surface: '#FFFFFF', text: '#0F172A', link: '#8F6F22' },
  buttons: { style: 'gradient', radius: 12, accent_text: '#06122A', uppercase: false },
  banners: { overlay_color: '#06122A', overlay_opacity: 70, hero_images: [null, null, null, null], page_banner_image: null, cta_style: 'gradient' },
  pattern: { type: 'dots', color: '#C8A24A', opacity: 18, size: 22, image: null },
  shape: { card_radius: 16, glass_blur: 20 },
}

export type Preset = { id: string; name: { ar: string; en: string }; colors: Theme['colors']; accent_text: string; overlay: string; pattern: PatternType }

/** Curated luxury palettes. Each keeps WCAG AA contrast for body text and buttons. */
export const PRESETS: Preset[] = [
  { id: 'royal_navy', name: { ar: 'الكحلي الملكي والذهبي', en: 'Royal Navy & Gold' }, colors: DEFAULT_THEME.colors, accent_text: '#06122A', overlay: '#06122A', pattern: 'dots' },
  { id: 'qatar_maroon', name: { ar: 'العنابي القطري', en: 'Qatar Maroon' }, colors: { primary: '#5A0F2E', accent: '#D4AF37', background: '#FAF7F2', surface: '#FFFFFF', text: '#1F1216', link: '#8A1538' }, accent_text: '#2A0615', overlay: '#2A0615', pattern: 'islamic_star' },
  { id: 'emerald_sand', name: { ar: 'الزمردي والرملي', en: 'Emerald & Sand' }, colors: { primary: '#0F3D2E', accent: '#C9A66B', background: '#F7F4EC', surface: '#FFFFFF', text: '#10231C', link: '#7A5A22' }, accent_text: '#0A241B', overlay: '#08241B', pattern: 'arabesque' },
  { id: 'midnight_silver', name: { ar: 'منتصف الليل الفضي', en: 'Midnight Silver' }, colors: { primary: '#111827', accent: '#A7B1C2', background: '#F5F6F8', surface: '#FFFFFF', text: '#0B1220', link: '#334155' }, accent_text: '#0B1220', overlay: '#05070C', pattern: 'grid' },
  { id: 'desert_bronze', name: { ar: 'البرونزي الصحراوي', en: 'Desert Bronze' }, colors: { primary: '#3B2A1A', accent: '#B8864B', background: '#FBF7F0', surface: '#FFFFFF', text: '#231A10', link: '#8A5A22' }, accent_text: '#1F140A', overlay: '#1D140B', pattern: 'diagonal' },
  { id: 'ocean_pearl', name: { ar: 'اللؤلؤ والخليج', en: 'Gulf Pearl' }, colors: { primary: '#0C3B5E', accent: '#C5A572', background: '#F4F7F8', surface: '#FFFFFF', text: '#0A1F2E', link: '#0E6A8A' }, accent_text: '#06223A', overlay: '#051C2E', pattern: 'dots' },
]

// ---------------------------------------------------------------- colour maths

const clamp = (n: number) => Math.max(0, Math.min(255, Math.round(n)))

export function hexToRgb(hex: string): [number, number, number] {
  const h = hex.replace('#', '')
  const v = h.length === 3 ? h.split('').map((c) => c + c).join('') : h
  return [parseInt(v.slice(0, 2), 16), parseInt(v.slice(2, 4), 16), parseInt(v.slice(4, 6), 16)]
}

const rgbToHex = (r: number, g: number, b: number) => `#${[r, g, b].map((x) => clamp(x).toString(16).padStart(2, '0')).join('')}`

/** Mixes `hex` towards `target` by `amount` (0..1). */
export function mix(hex: string, target: string, amount: number) {
  const [r1, g1, b1] = hexToRgb(hex)
  const [r2, g2, b2] = hexToRgb(target)
  return rgbToHex(r1 + (r2 - r1) * amount, g1 + (g2 - g1) * amount, b1 + (b2 - b1) * amount)
}

export const rgba = (hex: string, alpha: number) => `rgb(${hexToRgb(hex).join(' ')} / ${alpha})`

function luminance(hex: string) {
  const [r, g, b] = hexToRgb(hex).map((c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** WCAG 2.1 contrast ratio between two colours (1..21). */
export function contrast(a: string, b: string) {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (l1 + 0.05) / (l2 + 0.05)
}

// ---------------------------------------------------------------- patterns

const enc = (svg: string) => `url("data:image/svg+xml,${encodeURIComponent(svg)}")`

/** Background pattern tiles, drawn in the chosen colour and opacity. */
export function patternImage(p: Theme['pattern']): string {
  const c = p.color
  const o = (p.opacity / 100).toFixed(2)
  switch (p.type) {
    case 'none':
      return 'none'
    case 'custom':
      return p.image ? `url("${p.image}")` : 'none'
    case 'dots':
      return enc(`<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"><circle cx="1.5" cy="1.5" r="1.2" fill="${c}" fill-opacity="${o}"/></svg>`)
    case 'grid':
      return enc(`<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><path d="M40 0H0v40" fill="none" stroke="${c}" stroke-opacity="${o}" stroke-width="1"/></svg>`)
    case 'diagonal':
      return enc(`<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path d="M-6 6l12-12M0 24L24 0M18 30l12-12" stroke="${c}" stroke-opacity="${o}" stroke-width="1.2"/></svg>`)
    case 'islamic_star':
      // Eight-pointed star (Rub el Hizb) lattice.
      return enc(`<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><g fill="none" stroke="${c}" stroke-opacity="${o}" stroke-width="1.1"><rect x="18" y="18" width="24" height="24"/><rect x="18" y="18" width="24" height="24" transform="rotate(45 30 30)"/><circle cx="30" cy="30" r="5"/><path d="M0 0l12 12M60 0L48 12M0 60l12-12M60 60L48 48"/></g></svg>`)
    case 'arabesque':
      return enc(`<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 56 56"><g fill="none" stroke="${c}" stroke-opacity="${o}" stroke-width="1.1"><path d="M28 0c0 15.5 12.5 28 28 28M28 0C28 15.5 15.5 28 0 28M28 56c0-15.5 12.5-28 28-28M28 56c0-15.5-12.5-28-28-28"/><circle cx="28" cy="28" r="3"/></g></svg>`)
  }
}

// ---------------------------------------------------------------- apply

export function themeVariables(theme: Theme): Record<string, string> {
  const { primary, accent, background, surface, text, link } = theme.colors
  const white = '#ffffff'
  const black = '#000000'
  const overlay = theme.banners.overlay_color
  const strength = theme.banners.overlay_opacity / 100

  const accentBg =
    theme.buttons.style === 'gradient'
      ? `linear-gradient(to left, ${mix(accent, white, 0.15)}, ${accent}, ${mix(accent, black, 0.15)})`
      : theme.buttons.style === 'solid'
        ? accent
        : 'transparent'

  return {
    '--color-navy-950': mix(primary, black, 0.35),
    '--color-navy-900': primary,
    '--color-navy-800': mix(primary, white, 0.08),
    '--color-navy-700': mix(primary, white, 0.16),
    '--color-navy-600': mix(primary, white, 0.26),
    '--color-navy-500': mix(primary, white, 0.38),
    '--color-navy-100': mix(primary, white, 0.88),
    '--color-gold-700': mix(accent, black, 0.28),
    '--color-gold-600': mix(accent, black, 0.15),
    '--color-gold-500': accent,
    '--color-gold-400': mix(accent, white, 0.15),
    '--color-gold-300': mix(accent, white, 0.35),
    '--color-gold-100': mix(accent, white, 0.8),
    '--color-ivory': background,
    '--color-ivory-dark': mix(background, black, 0.05),
    '--color-surface': surface,
    '--color-ink': text,
    '--color-link': link,
    '--shadow-glass': `0 10px 40px -12px ${rgba(primary, 0.25)}`,
    '--shadow-gold': `0 12px 40px -12px ${rgba(accent, 0.45)}`,
    '--radius-btn': `${theme.buttons.radius}px`,
    '--radius-card': `${theme.shape.card_radius}px`,
    '--glass-blur': `${theme.shape.glass_blur}px`,
    '--btn-accent-bg': accentBg,
    '--btn-accent-text': theme.buttons.style === 'outline' ? mix(accent, black, 0.28) : theme.buttons.accent_text,
    '--btn-accent-border': theme.buttons.style === 'outline' ? accent : 'transparent',
    '--btn-transform': theme.buttons.uppercase ? 'uppercase' : 'none',
    '--hero-overlay-strong': rgba(overlay, strength),
    '--hero-overlay-soft': rgba(overlay, strength * 0.4),
    '--pattern-image': patternImage(theme.pattern),
    '--pattern-size': `${theme.pattern.size}px`,
  }
}

export function applyTheme(theme: Theme) {
  const root = document.documentElement
  Object.entries(themeVariables(theme)).forEach(([k, v]) => root.style.setProperty(k, v))
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.colors.primary)
}

export function mergeTheme(input?: Partial<Theme> | null): Theme {
  if (!input) return DEFAULT_THEME
  return {
    ...DEFAULT_THEME,
    ...input,
    colors: { ...DEFAULT_THEME.colors, ...input.colors },
    buttons: { ...DEFAULT_THEME.buttons, ...input.buttons },
    banners: { ...DEFAULT_THEME.banners, ...input.banners, hero_images: [0, 1, 2, 3].map((i) => input.banners?.hero_images?.[i] ?? null) },
    pattern: { ...DEFAULT_THEME.pattern, ...input.pattern },
    shape: { ...DEFAULT_THEME.shape, ...input.shape },
  }
}
