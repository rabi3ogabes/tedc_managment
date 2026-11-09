import { Info } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { helpRoute, type HelpArticle } from './helpApi'
import { useHelp } from './HelpProvider'

const base = 'inline-grid size-8 shrink-0 place-items-center rounded-full align-middle transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500'

/**
 * The ⓘ beside a page title. It appears when an article has been written for the page (its «related pages» in the
 * help editor) and opens the help window on it; for the people who write help it is always there — dashed when the
 * page has none yet — and the window offers to write it. Nothing to place by hand: write the article, link the page.
 */
export function PageHelp() {
  const { t } = useTranslation()
  const help = useHelp()
  const { can } = useAuth()
  const { pathname } = useLocation()
  const res = useGet<{ data: HelpArticle[] }>(help ? '/me/help/articles' : null, { route: helpRoute(pathname) }, { staleTime: 300_000 })
  const count = res.data?.data.length ?? 0
  const known = !res.isLoading   // until the answer is in the button looks like the plain one, so «write it» never flashes
  const manager = can('help.manage')
  if (!help || (!count && !manager)) return null
  const label = String(count || !known ? t('hlp.pageHelp') : t('hlp.pageHelpNone'))
  return (
    <button type="button" onClick={() => help.open({ page: true })} aria-label={label} title={label} data-tour="page-help"
      className={`${base} ms-2 ${count || !known ? 'bg-gold-100 text-gold-800 hover:bg-gold-200' : 'border border-dashed border-gold-400 text-gold-700 hover:bg-gold-50'}`}>
      <Info className="size-4.5" aria-hidden />
    </button>
  )
}

/** An ⓘ for one section or control: opens the help window on the article with this address (written and edited in Settings → Help articles). */
export function HelpTip({ slug, label }: { slug: string; label?: string }) {
  const { t } = useTranslation()
  const help = useHelp()
  if (!help) return null
  const text = label ?? String(t('hlp.tip'))
  return (
    <button type="button" onClick={() => help.open({ slug })} aria-label={text} title={text} data-tour={`help-tip-${slug}`}
      className="inline-grid size-7 shrink-0 place-items-center rounded-full text-gold-700 transition hover:bg-gold-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500">
      <Info className="size-4" aria-hidden />
    </button>
  )
}
