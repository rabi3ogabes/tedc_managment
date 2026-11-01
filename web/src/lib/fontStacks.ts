/** A font family name safe to put in a CSS stack. */
export const safeFamily = (name: string) => name.replace(/[^\p{L}\p{N} -]/gu, '').trim() || 'Qatar Sans'

/**
 * The font stacks of a language: brand fonts first, then the bundled web fonts. The Ministry's Lusail typeface leads wherever the brand has
 * not chosen a font of its own (the default is Lusail, and an older saved Qatar Sans), once its files are in place (uploaded to the font
 * library or dropped in /public/fonts/lusail); with no files it is never requested and the stack falls through to Qatar Sans → Tajawal.
 * Headings take `display` when the administrator gave them a family of their own.
 */
export function stacksFor(arabic: string, latin: string, lang: string, lusail: boolean, display?: string | null) {
  const ar = safeFamily(arabic)
  const en = safeFamily(latin)
  const lead = (family: string) => `${lusail && family === 'Qatar Sans' ? '"Lusail", ' : ''}"${family}"`
  const head = display ? `"${safeFamily(display)}", ` : ''
  return lang === 'en'
    ? { sans: `${lead(en)}, "Inter", "${ar}", "Lusail", "Tajawal", ui-sans-serif, system-ui, sans-serif`, display: `${head}${lead(en)}, "Playfair Display", "${ar}", "Lusail", "El Messiri", serif` }
    : { sans: `${lead(ar)}, "Lusail", "Qatar Sans", "Tajawal", "${en}", "Inter", ui-sans-serif, system-ui, sans-serif`, display: `${head}${lead(ar)}, "Lusail", "Qatar Sans", "El Messiri", "${en}", "Playfair Display", serif` }
}
