import clsx from 'clsx'
import { CornerDownLeft, Search } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { SettingsSection } from './registry'

type Props = { sections: SettingsSection[]; open: Set<string>; onPick: (id: string) => void; onClose: () => void }

/** Ctrl/⌘ + K quick switcher: type, arrow, Enter. */
export default function CommandPalette({ sections, open, onPick, onClose }: Props) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [index, setIndex] = useState(0)
  const list = useRef<HTMLUListElement>(null)

  const results = useMemo(() => {
    const term = q.trim().toLowerCase()
    return sections.filter((s) => {
      if (!term) return true
      const hay = [t(`mgmt.settings.sections.${s.id}.title`), t(`mgmt.settings.sections.${s.id}.desc`), ...s.keywords].join(' ').toLowerCase()
      return hay.includes(term)
    })
  }, [sections, q, t])

  useEffect(() => {
    list.current?.querySelector<HTMLElement>('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
  }, [index])

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setIndex((i) => Math.min(results.length - 1, i + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setIndex((i) => Math.max(0, i - 1)) }
    else if (e.key === 'Enter' && results[index]) { e.preventDefault(); onPick(results[index].id) }
    else if (e.key === 'Escape') { e.preventDefault(); onClose() }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center bg-navy-950/55 p-4 pt-[12vh] backdrop-blur-sm" onMouseDown={onClose} role="dialog" aria-modal="true" aria-label={t('mgmt.settings.openSetting')}>
      <div className="card w-full max-w-xl animate-fade-up overflow-hidden !p-0" onMouseDown={(e) => e.stopPropagation()}>
        <div className="relative border-b border-navy-100">
          <Search className="absolute start-4 top-4 size-5 text-slate-400" />
          <input autoFocus value={q} onChange={(e) => { setQ(e.target.value); setIndex(0) }} onKeyDown={onKey} placeholder={t('mgmt.settings.search')} aria-label={t('mgmt.settings.search')} className="w-full bg-transparent py-4 pe-4 ps-12 text-base outline-none placeholder:text-slate-400" />
        </div>
        <ul ref={list} role="listbox" className="max-h-80 overflow-y-auto p-2">
          {results.length === 0 && <li className="px-4 py-8 text-center text-sm text-slate-400">{t('mgmt.settings.noResults')}</li>}
          {results.map((s, i) => {
            const Icon = s.icon
            return (
              <li key={s.id} role="option" aria-selected={i === index}>
                <button type="button" onMouseEnter={() => setIndex(i)} onClick={() => onPick(s.id)} className={clsx('flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition', i === index ? 'bg-navy-900 text-white' : 'hover:bg-ivory')}>
                  <span className={clsx('grid size-9 shrink-0 place-items-center rounded-lg', i === index ? 'bg-gold-500 text-navy-950' : 'bg-navy-900 text-gold-300')}><Icon className="size-4" /></span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-bold">{t(`mgmt.settings.sections.${s.id}.title`)}</span>
                    <span className={clsx('block truncate text-xs', i === index ? 'text-white/70' : 'text-slate-500')}>{t(`mgmt.settings.sections.${s.id}.desc`)}</span>
                  </span>
                  {open.has(s.id) && <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', i === index ? 'bg-white/20' : 'bg-emerald-50 text-emerald-700')}>{t('mgmt.settings.opened')}</span>}
                  {i === index && <CornerDownLeft className="size-4 shrink-0 opacity-70" />}
                </button>
              </li>
            )
          })}
        </ul>
        <div className="flex items-center justify-between border-t border-navy-100 bg-ivory/70 px-4 py-2 text-[11px] text-slate-500"><span>{t('mgmt.settings.searchPalette')}</span><span dir="ltr">↑ ↓ · Enter · Esc</span></div>
      </div>
    </div>
  )
}
