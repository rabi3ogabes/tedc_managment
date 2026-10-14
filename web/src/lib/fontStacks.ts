/** A font family name safe to put in a CSS stack. */
export const safeFamily = (name: string) => name.replace(/[^\p{L}\p{N} -]/gu, '').trim() || 'Qatar Sans'

/**
 * The font stacks of a language: brand fonts first, then the bundled web fonts. The Ministry's Lusail typeface leads wherever the brand has
 * not chosen a font of its own (the default is Qatar Sans), once its licensed files are in place; with no files it is never requested.
 */
export function stacksFor(arabic: string, latin: string, lang: string, lusail: boolean) {
  const ar = safeFamily(arabic)
  const en = safeFamily(latin)
  const lead = (family: string) => `${lusail && family === 'Qatar Sans' ? '"Lusail", ' : ''}"${family}"`
  return lang === 'en'
    ? { sans: `${lead(en)}, "Inter", "${ar}", "Lusail", "Tajawal", ui-sans-serif, system-ui, sans-serif`, display: `${lead(en)}, "Playfair Display", "${ar}", "Lusail", "El Messiri", serif` }
    : { sans: `${lead(ar)}, "Lusail", "Qatar Sans", "Tajawal", "${en}", "Inter", ui-sans-serif, system-ui, sans-serif`, display: `${lead(ar)}, "Lusail", "Qatar Sans", "El Messiri", "${en}", "Playfair Display", serif` }
}
