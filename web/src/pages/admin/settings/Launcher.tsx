import clsx from 'clsx'
import { ArrowUpRight, Keyboard, Search, Sparkles } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { GROUP_ORDER, type SettingsSection } from './registry'

type Props = { sections: SettingsSection[]; open: Set<string>; recent: string[]; onOpen: (id: string) => void }

/** The home tab: a searchable, grouped launcher for every settings page. */
export default function Launcher({ sections, open, recent, onOpen }: Props) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const term = q.trim().toLowerCase()

  const filtered = useMemo(() => sections.filter((s) => !term || [t(`mgmt.settings.sections.${s.id}.title`), t(`mgmt.settings.sections.${s.id}.desc`), ...s.keywords].join(' ').toLowerCase().includes(term)), [sections, term, t])
  const recents = recent.map((id) => sections.find((s) => s.id === id)).filter((s): s is SettingsSection => !!s).slice(0, 4)
  const tips = t('mgmt.settings.tips', { returnObjects: true }) as unknown as string[]

  return (
    <div className="space-y-8">
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass sm:p-8">
        <div className="pointer-events-none absolute -end-16 -top-16 size-64 rounded-full bg-gold-500/20 blur-3xl" />
        <div className="relative flex flex-wrap items-end justify-between gap-6">
          <div className="max-w-xl">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-gold-300"><Sparkles className="size-3.5" />{t('mgmt.settings.tabsOpen', { count: open.size })}</span>
            <h2 className="mt-3 text-2xl font-bold sm:text-3xl">{t('mgmt.settings.title')}</h2>
            <p className="mt-1.5 text-sm text-white/70">{t('mgmt.settings.subtitle')}</p>
          </div>
          <div className="relative w-full sm:max-w-sm">
            <Search className="absolute start-3.5 top-3.5 size-4 text-slate-400" />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mgmt.settings.search')} aria-label={t('mgmt.settings.search')} className="w-full rounded-xl border-0 bg-white py-3 pe-4 ps-10 text-sm text-navy-900 shadow-lg outline-none ring-2 ring-transparent placeholder:text-slate-400 focus:ring-gold-400" />
          </div>
        </div>
      </div>

      {!term && recents.length > 0 && (
        <section>
          <h3 className="mb-3 text-sm font-bold text-slate-500">{t('mgmt.settings.recent')}</h3>
          <div className="flex flex-wrap gap-2">
            {recents.map((s) => { const Icon = s.icon; return (
              <button key={s.id} type="button" onClick={() => onOpen(s.id)} className="inline-flex items-center gap-2 rounded-full border border-navy-100 bg-white px-4 py-2 text-sm font-semibold text-navy-900 shadow-sm transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow">
                <Icon className="size-4 text-gold-600" />{t(`mgmt.settings.sections.${s.id}.title`)}
              </button>
            ) })}
          </div>
        </section>
      )}

      {sections.length === 0 ? <p className="rounded-2xl bg-white p-8 text-center text-slate-500">{t('mgmt.settings.noAccess')}</p> : filtered.length === 0 ? (
        <p className="rounded-2xl bg-white p-8 text-center text-slate-500">{t('mgmt.settings.noResults')}</p>
      ) : GROUP_ORDER.map((group) => {
        const items = filtered.filter((s) => s.group === group)
        if (!items.length) return null
        return (
          <section key={group}>
            <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-navy-900"><span className="h-4 w-1 rounded-full bg-gold-500" />{t(`mgmt.settings.groups.${group}`)}</h3>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {items.map((s) => {
                const Icon = s.icon
                const isOpen = open.has(s.id)
                return (
                  <button key={s.id} type="button" onClick={() => onOpen(s.id)} className="group relative flex flex-col items-start gap-4 overflow-hidden rounded-2xl border border-navy-100 bg-white p-5 text-start shadow-sm transition duration-300 hover:-translate-y-1 hover:border-gold-400 hover:shadow-glass">
                    <span className="absolute inset-x-0 top-0 h-1 bg-gradient-to-l from-navy-900 via-navy-700 to-gold-500 opacity-0 transition group-hover:opacity-100" />
                    <div className="flex w-full items-start justify-between">
                      <span className="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-navy-900 to-navy-700 text-gold-300 shadow-md transition group-hover:scale-105"><Icon className="size-6" /></span>
                      {isOpen ? <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700"><span className="size-1.5 animate-pulse rounded-full bg-emerald-500" />{t('mgmt.settings.opened')}</span>
                        : <ArrowUpRight className="size-5 text-slate-300 transition group-hover:text-gold-600 rtl:-scale-x-100" />}
                    </div>
                    <div>
                      <div className="text-base font-bold text-navy-900">{t(`mgmt.settings.sections.${s.id}.title`)}</div>
                      <p className="mt-1 text-sm leading-relaxed text-slate-500">{t(`mgmt.settings.sections.${s.id}.desc`)}</p>
                    </div>
                  </button>
                )
              })}
            </div>
          </section>
        )
      })}

      <section className={clsx('rounded-2xl border border-dashed border-navy-200 bg-white/60 p-5')}>
        <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-navy-900"><Keyboard className="size-4 text-gold-600" />{t('mgmt.settings.tipTitle')}</h3>
        <ul className="grid gap-2 text-sm text-slate-600 sm:grid-cols-2">{tips.map((tip) => <li key={tip} className="flex gap-2"><span className="mt-2 size-1.5 shrink-0 rounded-full bg-gold-500" />{tip}</li>)}</ul>
      </section>
    </div>
  )
}
