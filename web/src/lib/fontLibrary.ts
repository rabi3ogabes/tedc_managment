/**
 * The Brand Studio font library (docs/FONTS.md): typefaces the administrator uploads, one file per weight and format. The website loads
 * the web formats first (WOFF2 → WOFF → OpenType), the mobile app the OpenType / TrueType files. Pure helpers, no DOM, so they are tested
 * with `npm test`.
 */
export type FontFormat = 'woff2' | 'woff' | 'otf' | 'ttf'
export type FontFile = { weight: number; style: 'normal' | 'italic'; format: FontFormat; url: string }
export type FontFamily = { id: string; family: string; files: FontFile[] }

export const FORMATS: FontFormat[] = ['woff2', 'woff', 'otf', 'ttf']
export const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900] as const

/** Weight words found in font file names, longest first so "ExtraBold" is not read as "Bold". */
const WEIGHT_WORDS: [RegExp, number][] = [
  [/(hairline|thin)/i, 100], [/(extra|ultra)[\s_-]?light/i, 200], [/(semi|demi)[\s_-]?bold/i, 600], [/(extra|ultra)[\s_-]?bold/i, 800],
  [/(black|heavy)/i, 900], [/light/i, 300], [/medium/i, 500], [/bold/i, 700], [/(regular|book|normal|roman)/i, 400],
]

export const formatOf = (name: string): FontFormat | null => {
  const ext = name.split('.').pop()?.toLowerCase()
  return FORMATS.includes(ext as FontFormat) ? (ext as FontFormat) : null
}

/** "Lusail-SemiBold.woff2" → 600; a name without a weight word is the regular weight. */
export function weightFromName(name: string): number {
  const stem = name.replace(/\.[a-z0-9]+$/i, '')
  for (const [re, w] of WEIGHT_WORDS) if (re.test(stem)) return w
  const n = stem.match(/(?:^|[^0-9])([1-9]00)(?:[^0-9]|$)/)
  return n ? Number(n[1]) : 400
}

export const italicFromName = (name: string) => /italic|oblique/i.test(name.replace(/\.[a-z0-9]+$/i, ''))

/** "Lusail-Bold.otf" → "Lusail"; "IBMPlexSansArabic_SemiBold.ttf" → "IBMPlexSansArabic". */
export function familyFromName(name: string): string {
  const stem = name.replace(/\.[a-z0-9]+$/i, '').replace(/[_]+/g, '-')
  const base = stem.split('-')[0] || stem
  return safeName(base.replace(/(Italic|Regular|Bold|Light|Medium|Thin|Black|Heavy)$/i, '') || base)
}

/** A family name that is safe in a CSS stack and a Flutter family name (letters, digits, spaces, hyphens). */
export const safeName = (name: string) => name.replace(/[^\p{L}\p{N} -]/gu, '').replace(/\s+/g, ' ').trim().slice(0, 60)

/**
 * The family name and weight written inside an OpenType / TrueType file (the `name` and `OS/2` tables), when the file can be read.
 * WOFF / WOFF2 files are compressed and are read from their name instead.
 */
export function readSfnt(buffer: ArrayBuffer): { family: string | null; weight: number | null; italic: boolean } | null {
  try {
    const v = new DataView(buffer)
    const tag = v.getUint32(0)
    if (tag !== 0x00010000 && tag !== 0x4f54544f && tag !== 0x74727565) return null   // TrueType, 'OTTO', 'true'
    const tables = v.getUint16(4)
    let name = -1
    let os2 = -1
    for (let i = 0; i < tables; i++) {
      const rec = 12 + i * 16
      const t = String.fromCharCode(v.getUint8(rec), v.getUint8(rec + 1), v.getUint8(rec + 2), v.getUint8(rec + 3))
      if (t === 'name') name = v.getUint32(rec + 8)
      if (t === 'OS/2') os2 = v.getUint32(rec + 8)
    }
    const weight = os2 >= 0 ? v.getUint16(os2 + 4) : null
    const italic = os2 >= 0 ? (v.getUint16(os2 + 62) & 1) === 1 : false
    let family: string | null = null
    if (name >= 0) {
      const count = v.getUint16(name + 2)
      const strings = name + v.getUint16(name + 4)
      const found: Record<number, string> = {}
      for (let i = 0; i < count; i++) {
        const r = name + 6 + i * 12
        const platform = v.getUint16(r)
        const id = v.getUint16(r + 6)
        if (id !== 1 && id !== 16) continue
        const len = v.getUint16(r + 8)
        const off = strings + v.getUint16(r + 10)
        let s = ''
        if (platform === 3 || platform === 0) for (let k = 0; k < len; k += 2) s += String.fromCharCode(v.getUint16(off + k))
        else for (let k = 0; k < len; k++) s += String.fromCharCode(v.getUint8(off + k))
        if (s && (platform === 3 || !found[id])) found[id] = s
      }
      family = found[16] || found[1] || null
    }
    return { family: family ? safeName(family) : null, weight: weight ? Math.min(900, Math.max(100, Math.round(weight / 100) * 100)) : null, italic }
  } catch {
    return null
  }
}

/** The @font-face rules of a library: one rule per weight and style, its sources ordered so browsers take the smallest format. */
export function fontFaceCss(library: FontFamily[] | undefined): string {
  const rules: string[] = []
  for (const fam of library ?? []) {
    const family = safeName(fam.family)
    if (!family) continue
    const groups = new Map<string, FontFile[]>()
    for (const f of fam.files ?? []) {
      if (!FORMATS.includes(f.format) || !/^(https?:\/\/|\/(?!\/))/.test(f.url)) continue
      const key = `${f.weight}|${f.style}`
      groups.set(key, [...(groups.get(key) ?? []), f])
    }
    for (const [key, files] of groups) {
      const [weight, style] = key.split('|')
      const src = [...files].sort((a, b) => FORMATS.indexOf(a.format) - FORMATS.indexOf(b.format))
        .map((f) => `url("${encodeURI(f.url)}") format("${f.format === 'otf' ? 'opentype' : f.format === 'ttf' ? 'truetype' : f.format}")`).join(',')
      rules.push(`@font-face{font-family:"${family}";src:${src};font-weight:${weight};font-style:${style};font-display:swap;}`)
    }
  }
  return rules.join('\n')
}

export const hasFamily = (library: FontFamily[] | undefined, family: string) => (library ?? []).some((f) => f.family.toLowerCase() === family.toLowerCase())

/** The weights a family carries, and whether the mobile app can use it (it needs OpenType / TrueType files). */
export function familyInfo(fam: FontFamily) {
  const weights = [...new Set(fam.files.map((f) => f.weight))].sort((a, b) => a - b)
  const formats = FORMATS.filter((fmt) => fam.files.some((f) => f.format === fmt))
  const web = fam.files.some((f) => f.format === 'woff2' || f.format === 'woff')
  const app = fam.files.some((f) => f.format === 'otf' || f.format === 'ttf')
  return { weights, formats, web, app }
}

/** Adds files to a library: into the family of the same name (replacing the same weight + style + format), or as a new family. */
export function addFiles(library: FontFamily[], family: string, files: FontFile[]): FontFamily[] {
  const name = safeName(family)
  const key = (f: FontFile) => `${f.weight}|${f.style}|${f.format}`
  const existing = library.find((f) => f.family.toLowerCase() === name.toLowerCase())
  if (!existing) return [...library, { id: name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || `font-${library.length + 1}`, family: name, files }]
  return library.map((f) => f !== existing ? f : { ...f, files: [...f.files.filter((x) => !files.some((n) => key(n) === key(x))), ...files] })
}
