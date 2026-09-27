/**
 * Brand Studio theme engine.
 *
 * The admin-managed theme is converted into CSS custom properties on <html>. Tailwind v4 emits
 * every colour utility as `var(--color-*)`, so re-defining the palette variables re-skins the
 * whole website, dashboard and portal instantly — no rebuild required.
 */

export type PatternType = 'none' | 'serrated' | 'dots' | 'grid' | 'islamic_star' | 'arabesque' | 'diagonal' | 'custom'

export type Theme = {
  preset: string
  colors: { primary: string; accent: string; background: string; surface: string; text: string; link: string }
  buttons: { style: 'gradient' | 'solid' | 'outline'; radius: number; accent_text: string; uppercase: boolean }
  banners: { overlay_color: string; overlay_opacity: number; hero_images: (string | null)[]; page_banner_image: string | null; cta_style: 'gradient' | 'accent' | 'image' }
  pattern: { type: PatternType; color: string; opacity: number; size: number; image: string | null }
  shape: { card_radius: number; glass_blur: number }
  identity: { logo_ar: string | null; logo_en: string | null; logo_ar_light: string | null; logo_en_light: string | null; show_center_name: boolean }
  typography: { arabic_family: string; latin_family: string; arabic_font_url: string | null; latin_font_url: string | null; heading_weight: number }
}

/**
 * Official files dropped into web/public (see public/brand/README.md and public/fonts/README.md).
 * They are used automatically whenever no uploaded asset is configured in the Brand Studio.
 */
export const BRAND_FILES = {
  logo_ar: '/brand/logo-ar.png',
  logo_en: '/brand/logo-en.png',
  logo_ar_light: '/brand/logo-ar-white.png',
  logo_en_light: '/brand/logo-en-white.png',
}

/** Qatar Government identity (Government Communications Office brand guidelines). */
export const QATAR_GOV = {
  alAdaam: '#8A1538',
  dune: '#A29475',
  black: '#1A1A1A',
  white: '#FFFFFF',
}

export const DEFAULT_THEME: Theme = {
  preset: 'qatar_gov',
  colors: { primary: QATAR_GOV.alAdaam, accent: QATAR_GOV.dune, background: '#F8F6F2', surface: '#FFFFFF', text: QATAR_GOV.black, link: QATAR_GOV.alAdaam },
  buttons: { style: 'solid', radius: 10, accent_text: QATAR_GOV.black, uppercase: false },
  banners: { overlay_color: '#3A0918', overlay_opacity: 72, hero_images: [null, null, null, null], page_banner_image: null, cta_style: 'gradient' },
  pattern: { type: 'serrated', color: QATAR_GOV.dune, opacity: 16, size: 40, image: null },
  shape: { card_radius: 14, glass_blur: 18 },
  identity: { logo_ar: null, logo_en: null, logo_ar_light: null, logo_en_light: null, show_center_name: true },
  typography: { arabic_family: 'Qatar Sans', latin_family: 'Qatar Sans', arabic_font_url: null, latin_font_url: null, heading_weight: 700 },
}

export type Preset = { id: string; name: { ar: string; en: string }; colors: Theme['colors']; accent_text: string; overlay: string; pattern: PatternType }

/** Curated luxury palettes. Each keeps WCAG AA contrast for body text and buttons. */
export const PRESETS: Preset[] = [
  { id: 'qatar_gov', name: { ar: 'الهوية الحكومية القطرية', en: 'Qatar Government' }, colors: DEFAULT_THEME.colors, accent_text: QATAR_GOV.black, overlay: '#3A0918', pattern: 'serrated' },
  { id: 'royal_navy', name: { ar: 'الكحلي الملكي والذهبي', en: 'Royal Navy & Gold' }, colors: { primary: '#0B1F3A', accent: '#C8A24A', background: '#F8F6F1', surface: '#FFFFFF', text: '#0F172A', link: '#8F6F22' }, accent_text: '#06122A', overlay: '#06122A', pattern: 'dots' },
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
    case 'serrated':
      // The nine-point serration of the Qatari flag, as a repeating ornamental band.
      return enc(`<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><path d="M16 0l8 4.44-8 4.45 8 4.44-8 4.45 8 4.44-8 4.45 8 4.44-8 4.45" fill="none" stroke="${c}" stroke-opacity="${o}" stroke-width="1.2" stroke-linejoin="round"/></svg>`)
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
    '--heading-weight': String(theme.typography.heading_weight),
  }
}

const safeFamily = (name: string) => name.replace(/[^\p{L}\p{N} -]/gu, '').trim() || 'Qatar Sans'

/** Font stacks for the active language. Brand fonts first, then bundled web fonts. */
export function fontStacks(theme: Theme, lang: string) {
  const ar = safeFamily(theme.typography.arabic_family)
  const en = safeFamily(theme.typography.latin_family)
  return lang === 'en'
    ? { sans: `"${en}", "Inter", "${ar}", "Tajawal", ui-sans-serif, system-ui, sans-serif`, display: `"${en}", "Playfair Display", "${ar}", "El Messiri", serif` }
    : { sans: `"${ar}", "Tajawal", "${en}", "Inter", ui-sans-serif, system-ui, sans-serif`, display: `"${ar}", "El Messiri", "${en}", "Playfair Display", serif` }
}

/** Registers uploaded font files (Brand Studio) as @font-face rules. */
function registerFonts(theme: Theme) {
  const rules = [
    [theme.typography.arabic_family, theme.typography.arabic_font_url],
    [theme.typography.latin_family, theme.typography.latin_font_url],
  ]
    .filter(([, url]) => url)
    .map(([family, url]) => `@font-face{font-family:"${safeFamily(family!)}";src:url("${encodeURI(url!)}");font-weight:100 900;font-display:swap;}`)
    .join('\n')

  let tag = document.getElementById('tedc-brand-fonts') as HTMLStyleElement | null
  if (!tag) {
    tag = document.createElement('style')
    tag.id = 'tedc-brand-fonts'
    document.head.appendChild(tag)
  }
  if (tag.textContent !== rules) tag.textContent = rules
}

/** Resolves the logo for a language / background, falling back to the files shipped in /public/brand. */
export function logoFor(theme: Theme, lang: string, onDark: boolean): { src: string; invert: boolean } {
  const id = theme.identity
  const base = lang === 'en' ? id.logo_en ?? id.logo_ar : id.logo_ar ?? id.logo_en
  const light = lang === 'en' ? id.logo_en_light ?? id.logo_ar_light : id.logo_ar_light ?? id.logo_en_light
  if (onDark && light) return { src: light, invert: false }
  if (base) return { src: base, invert: onDark }
  if (onDark) return { src: lang === 'en' ? BRAND_FILES.logo_en_light : BRAND_FILES.logo_ar_light, invert: false }
  return { src: lang === 'en' ? BRAND_FILES.logo_en : BRAND_FILES.logo_ar, invert: false }
}

export function applyTheme(theme: Theme, lang: string) {
  const root = document.documentElement
  Object.entries(themeVariables(theme)).forEach(([k, v]) => root.style.setProperty(k, v))
  const fonts = fontStacks(theme, lang)
  root.style.setProperty('--font-sans', fonts.sans)
  root.style.setProperty('--font-display', fonts.display)
  registerFonts(theme)
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
    identity: { ...DEFAULT_THEME.identity, ...input.identity },
    typography: { ...DEFAULT_THEME.typography, ...input.typography },
  }
}
