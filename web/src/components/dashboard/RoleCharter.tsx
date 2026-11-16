import clsx from 'clsx'
import { ArrowUpLeft, ArrowUpRight, ChevronDown, ChevronUp, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'

type Section = {
  title: string
  items: string[]
  /** The page where the heading is done, and the right that page asks for (the link is shown only to a role that holds it). */
  to?: string
  needs?: string
}
type Charter = { role: string; name: { ar: string; en: string }; permissions: Section[]; responsibilities: Section[] }
type Tab = 'permissions' | 'responsibilities'

const OPEN_KEY = 'tedc.roleCharter.open'

/**
 * What the role the person is acting in may do (الصلاحيات) and what it answers for (المسؤوليات), in the words of the Ministry's
 * roles document. The text is the document's own, so it is always shown in Arabic; a heading that has a page in the system
 * carries a link to it. A role the document does not describe shows nothing.
 */
export default function RoleCharter() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { data } = useGet<{ data: Charter | null }>('/dashboard/charter', undefined, { staleTime: 10 * 60_000 })
  const [tab, setTab] = useState<Tab>('permissions')
  const [open, setOpen] = useState(() => { try { return localStorage.getItem(OPEN_KEY) !== '0' } catch { return true } })
  const charter = data?.data
  if (!charter) return null

  const rtl = i18n.dir() === 'rtl'
  const Go = rtl ? ArrowUpLeft : ArrowUpRight
  const sections = charter[tab]
  const toggle = () => { const next = !open; setOpen(next); try { localStorage.setItem(OPEN_KEY, next ? '1' : '0') } catch { /* storage unavailable */ } }
  const tabs: [Tab, string, number][] = [['permissions', String(t('charter.permissions')), charter.permissions.length], ['responsibilities', String(t('charter.responsibilities')), charter.responsibilities.length]]

  return (
    <section aria-label={String(t('charter.title'))} className="mb-8 overflow-hidden rounded-3xl border border-navy-100 bg-white">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-navy-900 text-gold-300"><ShieldCheck className="size-5" /></span>
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-bold leading-tight text-navy-900">{t('charter.title')}</h2>
          <p className="mt-0.5 text-sm text-slate-600">{t('charter.role')} <bdi className="font-bold text-navy-900">{i18n.language === 'en' ? charter.name.en : charter.name.ar}</bdi></p>
        </div>

        <div className="flex items-center gap-1 rounded-2xl border border-navy-100 p-1" role="tablist" aria-label={String(t('charter.title'))}>
          {tabs.map(([id, label, n]) => (
            <button key={id} type="button" role="tab" aria-selected={open && tab === id} onClick={() => { setTab(id); if (!open) toggle() }}
              className={clsx('inline-flex h-9 items-center gap-2 rounded-xl px-3.5 text-sm font-bold transition', open && tab === id ? 'bg-navy-900 text-white' : 'text-navy-900 hover:bg-navy-50')}>
              {label}<span className={clsx('rounded-full px-1.5 text-xs tabular-nums', open && tab === id ? 'bg-white/15' : 'bg-navy-50 text-navy-800')}>{n}</span>
            </button>
          ))}
        </div>
        <button type="button" onClick={toggle} aria-expanded={open} className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-navy-100 px-3 text-sm font-bold text-navy-900 transition hover:border-gold-400">
          {open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}{t(open ? 'charter.hide' : 'charter.show')}
        </button>
      </div>

      {open && (
        <div className="border-t border-navy-100 bg-ivory/60 px-5 pb-5 pt-4" role="tabpanel">
          {/* The words of the document: Arabic whatever the language of the page. */}
          <div className="gap-4 md:columns-2 2xl:columns-3" dir="rtl" lang="ar">
            {sections.map((s) => (
              <article key={s.title} className="mb-4 break-inside-avoid rounded-2xl border border-navy-100 bg-white p-4">
                <div className="flex items-start justify-between gap-3">
                  <h3 className="text-[15px] font-bold leading-snug text-navy-900">{s.title}</h3>
                  {s.to && (!s.needs || can(s.needs)) && (
                    <Link to={s.to} className="inline-flex shrink-0 items-center gap-1 rounded-full bg-navy-50 px-2.5 py-1 text-xs font-bold text-navy-900 transition hover:bg-navy-900 hover:text-white" dir={i18n.dir()} lang={i18n.language}>
                      {t('charter.open')}<Go className="size-3.5" />
                    </Link>
                  )}
                </div>
                <ul className="mt-2.5 space-y-2">
                  {s.items.map((item) => (
                    <li key={item} className="flex gap-2.5 text-sm leading-7 text-ink">
                      <span aria-hidden className="mt-[0.7rem] size-1.5 shrink-0 rounded-full bg-gold-500" />
                      <span>{item}</span>
                    </li>
                  ))}
                </ul>
              </article>
            ))}
          </div>
          <p className="text-xs text-slate-500">{t('charter.source')}{i18n.language === 'en' && ` ${t('charter.arabicOnly')}`}</p>
        </div>
      )}
    </section>
  )
}
