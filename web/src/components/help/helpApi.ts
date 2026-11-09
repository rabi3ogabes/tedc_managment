/* eslint-disable @typescript-eslint/no-explicit-any */
import { useTranslation } from 'react-i18next'

export type HelpArticle = {
  id: string; slug: string; title_ar: string; title_en: string; module: string; version: number; updated_at?: string; has_video: boolean
  excerpt_ar?: string; excerpt_en?: string; body_ar?: string | null; body_en?: string | null; video_url?: string | null; video_asset_url?: string | null
  screenshots?: { url: string; caption_ar?: string; caption_en?: string }[]; roles?: string[]; related_routes?: string[]; status?: string; sort_order?: number
}

/** How an article's text looks, in the help window and in the editor that writes it, so what is written is what is read. */
export const HELP_BODY_CLASS =
  'help-body space-y-3 leading-8 text-slate-800 [&_blockquote]:border-s-4 [&_blockquote]:border-gold-500 [&_blockquote]:bg-ivory [&_blockquote]:px-3 [&_blockquote]:py-1 [&_h3]:pt-2 [&_h3]:text-lg [&_h3]:font-bold [&_h3]:text-navy-900 [&_li]:ms-1 [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6'

/** The page's path as the help articles know it: the /admin and /portal prefixes are dropped, and the two home pages have their own keys. */
export function helpRoute(pathname: string): string {
  const p = pathname.replace(/\/+$/, '')
  if (p === '/admin' || p === '') return '/dashboard'
  if (p === '/portal') return '/portal'
  return p.replace(/^\/(admin|portal)(?=\/)/, '')
}

/** Picks the field of the current language. */
export function useLang() {
  const { i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  return { lang, pick: (o: any, key: string) => (o?.[`${key}_${lang}`] ?? o?.[`${key}_ar`] ?? '') as string }
}
